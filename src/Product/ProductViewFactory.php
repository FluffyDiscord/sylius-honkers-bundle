<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Product;

use FluffyDiscord\SyliusChatbotBundle\Channel\ChannelUrlGenerator;
use FluffyDiscord\SyliusChatbotBundle\Contract\ProductViewFactoryInterface;
use FluffyDiscord\SyliusChatbotBundle\DTO\ProductItem;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;

readonly class ProductViewFactory implements ProductViewFactoryInterface
{
    public function __construct(
        private VariantPriceResolver         $variantPriceResolver,
        private AvailabilityCheckerInterface $availabilityChecker,
        private ChannelUrlGenerator          $channelUrlGenerator,
        private CacheManager                 $imageCacheManager,
        private LoggerInterface              $logger,
    ) {
    }

    public function createItem(ProductVariantInterface $variant, ChannelInterface $channel, string $locale): ?ProductItem
    {
        $product = $variant->getProduct();
        if (!$product instanceof ProductInterface) {
            return null;
        }

        $priceMinor = $this->variantPriceResolver->findPriceMinor($variant, $channel);
        if ($priceMinor === null) {
            $this->logger->warning('Chatbot: variant has no channel price.', [
                'variant' => $variant->getCode(),
                'channel' => $channel->getCode(),
            ]);

            return null;
        }

        $baseCurrency = $channel->getBaseCurrency();
        if ($baseCurrency === null) {
            $this->logger->warning('Chatbot: channel has no base currency.', ['channel' => $channel->getCode()]);

            return null;
        }

        $translation = $product->getTranslation($locale);
        $slug = $translation->getSlug();
        if ($slug === null || $slug === '') {
            return null;
        }

        return new ProductItem(
            (string) $variant->getCode(),
            (string) $product->getCode(),
            $this->buildName($product, $variant, $locale),
            $this->channelUrlGenerator->generate(
                $channel,
                'sylius_shop_product_show',
                ['slug' => $slug, '_locale' => $locale],
            ),
            $priceMinor,
            (string) $baseCurrency->getCode(),
            $this->resolveImageUrl($product, $variant, $channel),
            $this->availabilityChecker->isStockAvailable($variant),
        );
    }

    private function buildName(ProductInterface $product, ProductVariantInterface $variant, string $locale): string
    {
        $productName = (string) $product->getTranslation($locale)->getName();
        $variantName = $variant->getTranslation($locale)->getName();
        $hasOwnName = $variantName !== null && $variantName !== '' && $variantName !== $productName;

        if (!$hasOwnName) {
            return $productName;
        }

        return sprintf('%s — %s', $productName, $variantName);
    }

    private function resolveImageUrl(
        ProductInterface $product,
        ProductVariantInterface $variant,
        ChannelInterface $channel,
    ): ?string {
        $image = $variant->getImages()->first();

        if ($image === false) {
            $image = $product->getImagesByType('main')->first();
        }
        if ($image === false) {
            $image = $product->getImages()->first();
        }
        if ($image === false) {
            return null;
        }

        $path = $image->getPath();
        if ($path === null || $path === '') {
            return null;
        }

        return $this->channelUrlGenerator->runOnChannelHost(
            $channel,
            fn (): string => $this->imageCacheManager->getBrowserPath($path, $this->getImageFilter()),
        );
    }

    private function getImageFilter(): string
    {
        return 'sylius_shop_product_original';
    }
}
