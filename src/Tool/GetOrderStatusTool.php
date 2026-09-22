<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tool;

use FluffyDiscord\Honkers\Contract\ChatbotToolInterface;
use FluffyDiscord\Honkers\DTO\ContentItem;
use FluffyDiscord\Honkers\DTO\FormDefinition;
use FluffyDiscord\Honkers\DTO\FormField;
use FluffyDiscord\Honkers\DTO\ToolCallContext;
use FluffyDiscord\Honkers\DTO\ToolDefinition;
use FluffyDiscord\Honkers\DTO\ToolResult;
use FluffyDiscord\Honkers\DTO\ToolUiHints;
use FluffyDiscord\Honkers\Enum\FormFieldType;
use FluffyDiscord\SyliusHonkersBundle\Tool\DTO\OrderStatusArguments;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class GetOrderStatusTool implements ChatbotToolInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'get_order_status',
            'fluffydiscord_honkers.tool.get_order_status.description',
            new ToolUiHints(new FormDefinition(
                'get_order_status',
                'fluffydiscord_honkers.tool.get_order_status.form.title',
                [
                    new FormField(
                        'orderNumber',
                        'fluffydiscord_honkers.tool.get_order_status.form.order_number',
                        FormFieldType::Text,
                        true,
                    ),
                    new FormField(
                        'email',
                        'fluffydiscord_honkers.tool.get_order_status.form.email',
                        FormFieldType::Email,
                        true,
                    ),
                ],
                'fluffydiscord_honkers.tool.get_order_status.form.submit',
            )),
        );
    }

    public function getArgumentsClass(): string
    {
        return OrderStatusArguments::class;
    }

    public function execute(object $arguments, ToolCallContext $context): ToolResult
    {
        $order = $this->orderRepository->findOneByNumber($arguments->orderNumber);
        $isVisible = $order instanceof OrderInterface && $this->isOwnedBy($order, $arguments->email);
        if (!$isVisible) {
            return new ToolResult([new ContentItem($this->translator->trans(
                'fluffydiscord_honkers.tool.get_order_status.not_found',
                ['%number%' => $arguments->orderNumber],
                'messages',
                $context->locale,
            ))]);
        }

        return new ToolResult([new ContentItem($this->translator->trans(
            'fluffydiscord_honkers.tool.get_order_status.summary',
            [
                '%number%' => (string) $order->getNumber(),
                '%state%' => (string) $order->getState(),
                '%paymentState%' => (string) $order->getPaymentState(),
                '%shippingState%' => (string) $order->getShippingState(),
                '%items%' => $order->getTotalQuantity(),
                '%total%' => $this->formatTotal($order),
                '%tracking%' => $this->formatTracking($order, $context->locale),
            ],
            'messages',
            $context->locale,
        ))]);
    }

    private function isOwnedBy(OrderInterface $order, string $email): bool
    {
        $isPlaced = $order->getState() !== BaseOrderInterface::STATE_CART;
        if (!$isPlaced) {
            return false;
        }

        $customer = $order->getCustomer();
        if ($customer === null) {
            return false;
        }

        $customerEmail = $customer->getEmail();
        if ($customerEmail === null) {
            return false;
        }

        return strcasecmp($customerEmail, $email) === 0;
    }

    private function formatTotal(OrderInterface $order): string
    {
        return sprintf(
            '%s %s',
            number_format($order->getTotal() / 100, 2, '.', ' '),
            (string) $order->getCurrencyCode(),
        );
    }

    private function formatTracking(OrderInterface $order, string $locale): string
    {
        $trackingCodes = [];
        foreach ($order->getShipments() as $shipment) {
            if (!$shipment instanceof ShipmentInterface) {
                continue;
            }
            $trackingCode = $shipment->getTracking();
            if ($trackingCode !== null && $trackingCode !== '') {
                $trackingCodes[] = $trackingCode;
            }
        }

        if ($trackingCodes === []) {
            return '';
        }

        return $this->translator->trans(
            'fluffydiscord_honkers.tool.get_order_status.tracking',
            ['%codes%' => implode(', ', $trackingCodes)],
            'messages',
            $locale,
        );
    }
}
