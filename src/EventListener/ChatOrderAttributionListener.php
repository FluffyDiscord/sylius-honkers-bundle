<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\EventListener;

use FluffyDiscord\Honkers\DTO\ChatOrder;
use FluffyDiscord\Honkers\Telemetry\TelemetryClient;
use FluffyDiscord\HonkersBundle\Reporting\BackendReportGuard;
use FluffyDiscord\SyliusHonkersBundle\Attribution\ChatClickSession;
use FluffyDiscord\SyliusHonkersBundle\Channel\SiteKeyResolver;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Intl\Currencies;
use Symfony\Contracts\Service\ResetInterface;

class ChatOrderAttributionListener implements ResetInterface
{
    /** @var list<array{siteKey: string, order: ChatOrder}> */
    private array $pendingOrders = [];

    public function __construct(
        private readonly ChatClickSession   $chatClickSession,
        private readonly TelemetryClient    $telemetryClient,
        private readonly SiteKeyResolver    $siteKeyResolver,
        private readonly BackendReportGuard $backendReportGuard,
        private readonly LoggerInterface    $logger,
    ) {
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

        $siteKey = $this->resolveSiteKey($order);
        $canReport = $this->backendReportGuard->canReport($siteKey, 'chat order');
        if (!$canReport) {
            return;
        }

        $this->queueOrders($order, $revenueByClickId, $siteKey);
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function reportOrders(): void
    {
        $pendingOrders = $this->pendingOrders;
        $this->reset();

        foreach ($pendingOrders as $pendingOrder) {
            $this->reportOrder($pendingOrder['siteKey'], $pendingOrder['order']);
        }
    }

    public function reset(): void
    {
        $this->pendingOrders = [];
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
    private function queueOrders(OrderInterface $order, array $revenueByClickId, string $siteKey): void
    {
        $orderNumber = (string) $order->getNumber();
        $currency = (string) $order->getCurrencyCode();

        foreach ($revenueByClickId as $clickId => $syliusRevenue) {
            $revenue = $this->convertToMinorUnits($syliusRevenue, $currency);
            $chatOrder = new ChatOrder((string) $clickId, $orderNumber, $revenue, $currency);
            $this->pendingOrders[] = ['siteKey' => $siteKey, 'order' => $chatOrder];
        }
    }

    private function convertToMinorUnits(int $syliusAmount, string $currency): int
    {
        $fractionDigits = Currencies::getFractionDigits($currency);
        $minorUnitsPerMajorUnit = 10 ** $fractionDigits;
        $minorUnits = $syliusAmount * $minorUnitsPerMajorUnit / 100;

        return (int) round($minorUnits);
    }

    private function reportOrder(string $siteKey, ChatOrder $order): void
    {
        try {
            $this->telemetryClient->reportOrder($siteKey, $order);
        } catch (\Throwable $exception) {
            $this->logger->warning('Chatbot: reporting the order from a chat link failed.', [
                'orderNumber' => $order->orderNumber,
                'exception' => $exception,
            ]);
        }
    }

    private function resolveSiteKey(OrderInterface $order): string
    {
        $channel = $order->getChannel();
        if ($channel === null) {
            return $this->siteKeyResolver->getDefaultSiteKey();
        }

        $channelCode = (string) $channel->getCode();

        return $this->siteKeyResolver->getSiteKey($channelCode);
    }
}
