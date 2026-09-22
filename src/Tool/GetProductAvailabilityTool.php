<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tool;

use FluffyDiscord\SyliusHonkersBundle\Channel\ChannelResolver;
use FluffyDiscord\Honkers\Contract\ChatbotToolInterface;
use FluffyDiscord\Honkers\DTO\ContentItem;
use FluffyDiscord\SyliusHonkersBundle\DTO\ProductsBlock;
use FluffyDiscord\Honkers\DTO\ToolCallContext;
use FluffyDiscord\Honkers\DTO\ToolDefinition;
use FluffyDiscord\Honkers\DTO\ToolResult;
use FluffyDiscord\SyliusHonkersBundle\Product\ProductViewFactory;
use FluffyDiscord\SyliusHonkersBundle\Tool\DTO\ProductAvailabilityArguments;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

class GetProductAvailabilityTool implements ChatbotToolInterface
{
    public function __construct(
        private readonly ChannelResolver     $channelResolver,
        private readonly ProductViewFactory  $productViewFactory,
        private readonly TranslatorInterface $translator,

        #[Autowire(service: 'sylius.repository.product_variant')]
        private readonly ProductVariantRepositoryInterface $variantRepository,
    ) {
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'get_product_availability',
            'fluffydiscord_honkers.tool.get_product_availability.description',
        );
    }

    public function getArgumentsClass(): string
    {
        return ProductAvailabilityArguments::class;
    }

    public function execute(object $arguments, ToolCallContext $context): ToolResult
    {
        $channel = $this->channelResolver->resolve($context->channelCode);
        $contentLines = [];
        $items = [];

        foreach ($arguments->codes as $code) {
            $variant = $this->variantRepository->findOneBy(['code' => $code]);
            $item = null;
            if ($variant instanceof ProductVariantInterface) {
                $item = $this->productViewFactory->createItem($variant, $channel, $context->locale);
            }
            if ($item === null) {
                $contentLines[] = $this->translator->trans(
                    'fluffydiscord_honkers.tool.get_product_availability.unknown',
                    ['%code%' => $code],
                    'messages',
                    $context->locale,
                );

                continue;
            }

            $items[] = $item;
            $lineKey = $item->inStock
                ? 'fluffydiscord_honkers.tool.get_product_availability.line_in_stock'
                : 'fluffydiscord_honkers.tool.get_product_availability.line_out_of_stock';
            $contentLines[] = $this->translator->trans(
                $lineKey,
                [
                    '%code%' => $item->code,
                    '%name%' => $item->name,
                    '%price%' => number_format($item->priceMinor / 100, 2, '.', ' '),
                    '%currency%' => $item->currency,
                ],
                'messages',
                $context->locale,
            );
        }

        $blocks = [];
        if ($items !== []) {
            $blocks[] = new ProductsBlock($items);
        }

        return new ToolResult(
            [new ContentItem(implode("\n", $contentLines))],
            $blocks,
        );
    }
}
