<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Tool;

use Doctrine\Common\Collections\ArrayCollection;
use FluffyDiscord\Honkers\DTO\ToolCallContext;
use FluffyDiscord\SyliusHonkersBundle\Tool\DTO\OrderStatusArguments;
use FluffyDiscord\SyliusHonkersBundle\Tool\GetOrderStatusTool;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class GetOrderStatusToolTest extends TestCase
{
    private function createTool(?OrderInterface $order): GetOrderStatusTool
    {
        $orderRepository = $this->createStub(OrderRepositoryInterface::class);
        $orderRepository->method('findOneByNumber')->willReturn($order);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            fn (string $id, array $parameters = []): string => $id . '|' . json_encode($parameters),
        );

        return new GetOrderStatusTool($orderRepository, $translator);
    }

    private function createOrder(string $customerEmail, string $state = OrderInterface::STATE_NEW): OrderInterface
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn($customerEmail);

        $shipment = $this->createStub(ShipmentInterface::class);
        $shipment->method('getTracking')->willReturn('TRACK123');

        $order = $this->createStub(OrderInterface::class);
        $order->method('getState')->willReturn($state);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getNumber')->willReturn('000000042');
        $order->method('getPaymentState')->willReturn('paid');
        $order->method('getShippingState')->willReturn('shipped');
        $order->method('getTotalQuantity')->willReturn(3);
        $order->method('getTotal')->willReturn(123456);
        $order->method('getCurrencyCode')->willReturn('CZK');
        $order->method('getShipments')->willReturn(new ArrayCollection([$shipment]));

        return $order;
    }

    private function createContext(): ToolCallContext
    {
        return new ToolCallContext('0b9cdd42-4b1f-4f1f-9a55-111111111111', 'cs_CZ');
    }

    public function testMatchingEmailReturnsSummary(): void
    {
        $tool = $this->createTool($this->createOrder('customer@example.com'));

        $result = $tool->execute(
            new OrderStatusArguments('000000042', 'customer@example.com'),
            $this->createContext(),
        );

        self::assertFalse($result->isError);
        $text = $result->content[0]->text;
        self::assertStringContainsString('get_order_status.summary', $text);
        self::assertStringContainsString('paid', $text);
        self::assertStringContainsString('shipped', $text);
        self::assertStringContainsString('TRACK123', $text);
        self::assertSame([], $result->blocks);
    }

    public function testGuestOrderEmailMatchesCaseInsensitively(): void
    {
        $tool = $this->createTool($this->createOrder('Guest@Example.COM'));

        $result = $tool->execute(
            new OrderStatusArguments('000000042', 'guest@example.com'),
            $this->createContext(),
        );

        self::assertFalse($result->isError);
        self::assertStringContainsString('get_order_status.summary', $result->content[0]->text);
    }

    public function testEmailMismatchReturnsNotFoundWithoutError(): void
    {
        $tool = $this->createTool($this->createOrder('customer@example.com'));

        $result = $tool->execute(
            new OrderStatusArguments('000000042', 'attacker@example.com'),
            $this->createContext(),
        );

        self::assertFalse($result->isError);
        self::assertStringContainsString('get_order_status.not_found', $result->content[0]->text);
    }

    public function testCartOrderIsReportedAsNotFound(): void
    {
        $tool = $this->createTool($this->createOrder('customer@example.com', OrderInterface::STATE_CART));

        $result = $tool->execute(
            new OrderStatusArguments('000000042', 'customer@example.com'),
            $this->createContext(),
        );

        self::assertFalse($result->isError);
        self::assertStringContainsString('get_order_status.not_found', $result->content[0]->text);
    }

    public function testMissingOrderIsReportedAsNotFound(): void
    {
        $tool = $this->createTool(null);

        $result = $tool->execute(
            new OrderStatusArguments('000000042', 'customer@example.com'),
            $this->createContext(),
        );

        self::assertFalse($result->isError);
        self::assertStringContainsString('get_order_status.not_found', $result->content[0]->text);
    }
}
