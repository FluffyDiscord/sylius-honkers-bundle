<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Product;

use FluffyDiscord\SyliusHonkersBundle\Channel\ChannelUrlGenerator;
use FluffyDiscord\SyliusHonkersBundle\Contract\ProductViewFactoryInterface;
use FluffyDiscord\SyliusHonkersBundle\DTO\ProductItem;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;

class ProductViewFactory implements ProductViewFactoryInterface
{
    public function __construct(
        private readonly VariantPriceResolver         $variantPriceResolver,
        private readonly AvailabilityCheckerInterface $availabilityChecker,
        private readonly ChannelUrlGenerator          $channelUrlGenerator,
        private readonly CacheManager                 $imageCacheManager,
        private readonly LoggerInterface              $logger,
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
