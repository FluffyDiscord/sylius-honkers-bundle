<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\EventListener;

use Doctrine\Common\Collections\ArrayCollection;
use FluffyDiscord\Honkers\DTO\ChatOrder;
use FluffyDiscord\Honkers\DTO\SiteCredentials;
use FluffyDiscord\Honkers\Exception\TelemetryException;
use FluffyDiscord\Honkers\Telemetry\TelemetryClient;
use FluffyDiscord\HonkersBundle\Reporting\BackendReportGuard;
use FluffyDiscord\SyliusHonkersPlugin\Attribution\ChatClickSession;
use FluffyDiscord\SyliusHonkersPlugin\EventListener\ChatOrderAttributionListener;
use FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures\ChannelCredentialsProviderDouble;
use FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures\ChatAttributedOrder;
use FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\Product;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class ChatOrderAttributionListenerTest extends TestCase
{
    /** @var list<array{siteKey: string, order: ChatOrder}> */
    private array $reports = [];

    private ChatClickSession $chatClickSession;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $request = Request::create('https://shop.example/checkout/complete');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $this->chatClickSession = new ChatClickSession($requestStack);
        $this->logger = new RecordingLogger();
    }

    private function createListener(
        ?TelemetryClient $telemetryClient = null,
        string $ingestSecret = 'ingest-secret',
    ): ChatOrderAttributionListener {
        $credentialsProvider = new ChannelCredentialsProviderDouble(
            ['CZ_WEB' => 'cz-key', 'DE_WEB' => 'de-key'],
            ingestSecret: $ingestSecret,
        );

        return new ChatOrderAttributionListener(
            $this->chatClickSession,
            $telemetryClient ?? $this->createRecordingTelemetryClient(),
            $credentialsProvider,
            new BackendReportGuard($this->logger, 'https://backend.example', 'prod'),
            $this->logger,
        );
    }

    private function createRecordingTelemetryClient(): TelemetryClient
    {
        $telemetryClient = $this->createStub(TelemetryClient::class);
        $telemetryClient->method('reportOrder')->willReturnCallback(function (SiteCredentials $siteCredentials, ChatOrder $order): void {
            $this->reports[] = ['siteKey' => $siteCredentials->siteKey, 'order' => $order];
        });

        return $telemetryClient;
    }

    /**
     * @param array<string, int> $totalsByProductCode
     */
    private function createCompletedOrderEvent(array $totalsByProductCode, string $currency = 'CZK', string $channelCode = 'CZ_WEB'): ResourceControllerEvent
    {
        $items = [];
        foreach ($totalsByProductCode as $productCode => $total) {
            $items[] = $this->createOrderItem((string) $productCode, $total);
        }

        $channel = new Channel();
        $channel->setCode($channelCode);

        $order = $this->createStub(OrderInterface::class);
        $order->method('getItems')->willReturn(new ArrayCollection($items));
        $order->method('getNumber')->willReturn('000042');
        $order->method('getCurrencyCode')->willReturn($currency);
        $order->method('getChannel')->willReturn($channel);

        return new ResourceControllerEvent($order);
    }

    private function createOrderItem(string $productCode, int $total): OrderItemInterface
    {
        $product = new Product();
        $product->setCode($productCode);

        $item = $this->createStub(OrderItemInterface::class);
        $item->method('getProduct')->willReturn($product);
        $item->method('getTotal')->willReturn($total);

        return $item;
    }

    public function testASiteWithoutAnIngestSecretReportsNothing(): void
    {
        $listener = $this->createListener(ingestSecret: '');
        $this->chatClickSession->remember('CLIPPER', 'click-1');

        $listener->attributeOrder($this->createCompletedOrderEvent(['CLIPPER' => 129900]));
        $listener->reportOrders();

        self::assertSame([], $this->reports);
    }

    public function testOnlyTheMatchingItemTotalIsReportedOnTerminate(): void
    {
        $listener = $this->createListener();
        $this->chatClickSession->remember('CLIPPER', 'click-1');

        $listener->attributeOrder($this->createCompletedOrderEvent(['CLIPPER' => 129900, 'BLADE' => 45000]));
        self::assertSame([], $this->reports);
        $listener->reportOrders();

        self::assertCount(1, $this->reports);
        self::assertSame('cz-key', $this->reports[0]['siteKey']);
        self::assertEquals(new ChatOrder('click-1', '000042', 129900, 'CZK'), $this->reports[0]['order']);
    }

    public function testAZeroDecimalCurrencyIsConvertedToMinorUnits(): void
    {
        $listener = $this->createListener();
        $this->chatClickSession->remember('CLIPPER', 'click-1');

        $listener->attributeOrder($this->createCompletedOrderEvent(['CLIPPER' => 50000], 'JPY'));
        $listener->reportOrders();

        self::assertEquals(new ChatOrder('click-1', '000042', 500, 'JPY'), $this->reports[0]['order']);
    }

    public function testEveryClickIsReportedSeparately(): void
    {
        $listener = $this->createListener();
        $this->chatClickSession->remember('CLIPPER', 'click-1');
        $this->chatClickSession->remember('BLADE', 'click-2');
        $this->chatClickSession->remember('OIL', 'click-1');

        $listener->attributeOrder($this->createCompletedOrderEvent(['CLIPPER' => 100000, 'BLADE' => 45000, 'OIL' => 9900]));
        $listener->reportOrders();

        $reportedOrders = array_column($this->reports, 'order');
        self::assertEquals([
            new ChatOrder('click-1', '000042', 109900, 'CZK'),
            new ChatOrder('click-2', '000042', 45000, 'CZK'),
        ], $reportedOrders);
    }

    public function testAnOrderWithoutAChatProductReportsNothing(): void
    {
        $listener = $this->createListener();
        $this->chatClickSession->remember('CLIPPER', 'click-1');

        $listener->attributeOrder($this->createCompletedOrderEvent(['BLADE' => 45000]));
        $listener->reportOrders();

        self::assertSame([], $this->reports);
    }

    public function testAnyCompletedOrderResetsTheAttribution(): void
    {
        $listener = $this->createListener();
        $this->chatClickSession->remember('CLIPPER', 'click-1');

        $listener->attributeOrder($this->createCompletedOrderEvent(['BLADE' => 45000]));

        self::assertSame([], $this->chatClickSession->getClickIdsByProductCode());
    }

    public function testTheOrderChannelSiteKeyIsUsed(): void
    {
        $listener = $this->createListener();
        $this->chatClickSession->remember('CLIPPER', 'click-1');

        $listener->attributeOrder($this->createCompletedOrderEvent(['CLIPPER' => 129900], 'EUR', 'DE_WEB'));
        $listener->reportOrders();

        self::assertSame('de-key', $this->reports[0]['siteKey']);
    }

    public function testAnOrderOfAChannelWithoutASiteIsNotReported(): void
    {
        $listener = $this->createListener();
        $this->chatClickSession->remember('CLIPPER', 'click-1');

        $listener->attributeOrder($this->createCompletedOrderEvent(['CLIPPER' => 129900], 'EUR', 'SK_WEB'));
        $listener->reportOrders();

        self::assertSame([], $this->reports);
    }

    public function testATelemetryFailureIsLoggedAndSwallowed(): void
    {
        $telemetryClient = $this->createStub(TelemetryClient::class);
        $telemetryClient->method('reportOrder')->willThrowException(new TelemetryException('Backend down.', 503));
        $listener = $this->createListener($telemetryClient);
        $this->chatClickSession->remember('CLIPPER', 'click-1');

        $listener->attributeOrder($this->createCompletedOrderEvent(['CLIPPER' => 129900]));
        $listener->reportOrders();

        self::assertSame(LogLevel::WARNING, $this->logger->records[0]['level']);
        self::assertSame('000042', $this->logger->records[0]['context']['orderNumber']);
    }

    public function testQueuedOrdersAreSentOnlyOnce(): void
    {
        $listener = $this->createListener();
        $this->chatClickSession->remember('CLIPPER', 'click-1');

        $listener->attributeOrder($this->createCompletedOrderEvent(['CLIPPER' => 129900]));
        $listener->reportOrders();
        $listener->reportOrders();

        self::assertCount(1, $this->reports);
    }

    public function testAnOrderKeepsTheClickIdsOfItsChatProducts(): void
    {
        $listener = $this->createListener();
        $this->chatClickSession->remember('CLIPPER', 'click-1');
        $this->chatClickSession->remember('BLADE', 'click-2');
        $this->chatClickSession->remember('OIL', 'click-1');
        $this->chatClickSession->remember('BRUSH', 'click-3');
        $order = $this->createChatAttributedOrder(['CLIPPER', 'BLADE', 'OIL', 'COMB']);

        $listener->markOrder(new ResourceControllerEvent($order));

        self::assertSame(['click-1', 'click-2'], $order->getChatClickIds());
    }

    public function testTheClickIdsFollowTheClickOrderNotTheItemOrder(): void
    {
        $listener = $this->createListener();
        $this->chatClickSession->remember('BLADE', 'click-2');
        $this->chatClickSession->remember('CLIPPER', 'click-1');
        $order = $this->createChatAttributedOrder(['CLIPPER', 'BLADE']);

        $listener->markOrder(new ResourceControllerEvent($order));

        self::assertSame(['click-2', 'click-1'], $order->getChatClickIds());
    }

    public function testAnOrderWithoutAChatProductKeepsNoClickIds(): void
    {
        $listener = $this->createListener();
        $this->chatClickSession->remember('CLIPPER', 'click-1');
        $order = $this->createChatAttributedOrder(['BLADE']);
        $order->setChatClickIds(['stale-click']);

        $listener->markOrder(new ResourceControllerEvent($order));

        self::assertSame([], $order->getChatClickIds());
    }

    public function testMarkingKeepsTheClicksForTheReport(): void
    {
        $listener = $this->createListener();
        $this->chatClickSession->remember('CLIPPER', 'click-1');

        $listener->markOrder(new ResourceControllerEvent($this->createChatAttributedOrder(['CLIPPER'])));

        self::assertSame(['CLIPPER' => 'click-1'], $this->chatClickSession->getClickIdsByProductCode());
    }

    /**
     * @param list<string> $productCodes
     */
    private function createChatAttributedOrder(array $productCodes): ChatAttributedOrder
    {
        $order = new ChatAttributedOrder();
        foreach ($productCodes as $productCode) {
            $order->addItem($this->createOrderItem($productCode, 10000));
        }

        return $order;
    }
}
