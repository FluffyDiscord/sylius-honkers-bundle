<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\EventListener;

use FluffyDiscord\Honkers\DTO\ChatOrder;
use FluffyDiscord\Honkers\DTO\SiteCredentials;
use FluffyDiscord\Honkers\Telemetry\TelemetryClient;
use FluffyDiscord\HonkersBundle\Reporting\BackendReportGuard;
use FluffyDiscord\SyliusHonkersPlugin\Attribution\ChatAttributedOrderInterface;
use FluffyDiscord\SyliusHonkersPlugin\Attribution\ChatClickSession;
use FluffyDiscord\SyliusHonkersPlugin\Credentials\ChannelCredentialsProviderInterface;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Intl\Currencies;
use Symfony\Contracts\Service\ResetInterface;

class ChatOrderAttributionListener implements ResetInterface
{
    /** @var list<array{siteCredentials: SiteCredentials, order: ChatOrder}> */
    private array $pendingOrders = [];

    public function __construct(
        private readonly ChatClickSession                    $chatClickSession,
        private readonly TelemetryClient                     $telemetryClient,
        private readonly ChannelCredentialsProviderInterface $credentialsProvider,
        private readonly BackendReportGuard                  $backendReportGuard,
        private readonly LoggerInterface                     $logger,
    ) {
    }

    #[AsEventListener(event: 'sylius.order.pre_complete')]
    public function markOrder(ResourceControllerEvent $event): void
    {
        $order = $event->getSubject();
        if (!$order instanceof ChatAttributedOrderInterface) {
            return;
        }

        $clickIdsByProductCode = $this->chatClickSession->getClickIdsByProductCode();
        $revenueByClickId = $this->getSyliusRevenueByClickId($order, $clickIdsByProductCode);
        $chatClickIds = $this->getChatClickIdsInClickOrder($clickIdsByProductCode, $revenueByClickId);

        $order->setChatClickIds($chatClickIds);
    }

    #[AsEventListener(event: 'sylius.order.post_complete')]
    public function attributeOrder(ResourceControllerEvent $event): void
    {
        $clickIdsByProductCode = $this->chatClickSession->getClickIdsByProductCode();
        $this->chatClickSession->clear();

        if ($clickIdsByProductCode === []) {
            return;
        }

        $order = $event->getSubject();
        if (!$order instanceof OrderInterface) {
            return;
        }

        $revenueByClickId = $this->getSyliusRevenueByClickId($order, $clickIdsByProductCode);
        if ($revenueByClickId === []) {
            return;
        }

        $siteCredentials = $this->findSiteCredentials($order);
        if ($siteCredentials === null) {
            return;
        }

        $canReport = $this->backendReportGuard->canReport('chat order');
        if (!$canReport) {
            return;
        }

        $this->queueOrders($order, $revenueByClickId, $siteCredentials);
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function reportOrders(): void
    {
        $pendingOrders = $this->pendingOrders;
        $this->reset();

        foreach ($pendingOrders as $pendingOrder) {
            $this->reportOrder($pendingOrder['siteCredentials'], $pendingOrder['order']);
        }
    }

    public function reset(): void
    {
        $this->pendingOrders = [];
    }

    /**
     * @param array<string, string> $clickIdsByProductCode
     * @param array<string, int>    $revenueByClickId
     *
     * @return list<string>
     */
    private function getChatClickIdsInClickOrder(array $clickIdsByProductCode, array $revenueByClickId): array
    {
        $chatClickIds = [];

        foreach ($clickIdsByProductCode as $clickId) {
            $isInOrder = array_key_exists($clickId, $revenueByClickId);
            if (!$isInOrder) {
                continue;
            }

            $isListed = in_array($clickId, $chatClickIds, true);
            if ($isListed) {
                continue;
            }

            $chatClickIds[] = $clickId;
        }

        return $chatClickIds;
    }

    /**
     * @param array<string, string> $clickIdsByProductCode
     *
     * @return array<string, int>
     */
    private function getSyliusRevenueByClickId(OrderInterface $order, array $clickIdsByProductCode): array
    {
        $revenueByClickId = [];

        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            if ($product === null) {
                continue;
            }

            $productCode = (string) $product->getCode();
            $clickId = $clickIdsByProductCode[$productCode] ?? null;
            if ($clickId === null) {
                continue;
            }

            $revenueByClickId[$clickId] ??= 0;
            $revenueByClickId[$clickId] += $item->getTotal();
        }

        return $revenueByClickId;
    }

    /**
     * @param array<string, int> $revenueByClickId
     */
    private function queueOrders(OrderInterface $order, array $revenueByClickId, SiteCredentials $siteCredentials): void
    {
        $orderNumber = (string) $order->getNumber();
        $currency = (string) $order->getCurrencyCode();

        foreach ($revenueByClickId as $clickId => $syliusRevenue) {
            $revenue = $this->convertToMinorUnits($syliusRevenue, $currency);
            $chatOrder = new ChatOrder((string) $clickId, $orderNumber, $revenue, $currency);
            $this->pendingOrders[] = ['siteCredentials' => $siteCredentials, 'order' => $chatOrder];
        }
    }

    private function convertToMinorUnits(int $syliusAmount, string $currency): int
    {
        $fractionDigits = Currencies::getFractionDigits($currency);
        $minorUnitsPerMajorUnit = 10 ** $fractionDigits;
        $minorUnits = $syliusAmount * $minorUnitsPerMajorUnit / 100;

        return (int) round($minorUnits);
    }

    private function reportOrder(SiteCredentials $siteCredentials, ChatOrder $order): void
    {
        try {
            $this->telemetryClient->reportOrder($siteCredentials, $order);
        } catch (\Throwable $exception) {
            $this->logger->warning('Chatbot: reporting the order from a chat link failed.', [
                'orderNumber' => $order->orderNumber,
                'exception' => $exception,
            ]);
        }
    }

    private function findSiteCredentials(OrderInterface $order): ?SiteCredentials
    {
        $siteCredentials = $this->findOrderSiteCredentials($order);
        if ($siteCredentials === null) {
            return null;
        }

        $hasIngestSecret = $siteCredentials->hasIngestSecret();
        if (!$hasIngestSecret) {
            return null;
        }

        return $siteCredentials;
    }

    private function findOrderSiteCredentials(OrderInterface $order): ?SiteCredentials
    {
        $channel = $order->getChannel();
        if ($channel === null) {
            return $this->credentialsProvider->findCurrentSite();
        }

        return $this->credentialsProvider->findForChannel($channel);
    }
}
