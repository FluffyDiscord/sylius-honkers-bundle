<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Product;

use Doctrine\Common\Collections\ArrayCollection;
use FluffyDiscord\SyliusChatbotBundle\Channel\ChannelUrlGenerator;
use FluffyDiscord\SyliusChatbotBundle\Product\ProductViewFactory;
use FluffyDiscord\SyliusChatbotBundle\Product\VariantPriceResolver;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ImageInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTranslationInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;
use Sylius\Component\Product\Model\ProductVariantTranslationInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

class ProductViewFactoryTest extends TestCase
{
    private function createFactory(RequestContext $context): ProductViewFactory
    {
        $pricesCalculator = $this->createStub(ProductVariantPricesCalculatorInterface::class);
        $pricesCalculator->method('calculate')->willReturn(149000);

        $availabilityChecker = $this->createStub(AvailabilityCheckerInterface::class);
        $availabilityChecker->method('isStockAvailable')->willReturn(true);

        $router = $this->createStub(RouterInterface::class);
        $router->method('getContext')->willReturn($context);
        $router->method('generate')->willReturnCallback(
            static fn (): string => sprintf('%s://%s/zazitek/bagrovani', $context->getScheme(), $context->getHost()),
        );

        $imageCacheManager = $this->createStub(CacheManager::class);
        $imageCacheManager->method('getBrowserPath')->willReturnCallback(
            static fn (): string => sprintf('%s://%s/media/cache/main.jpg', $context->getScheme(), $context->getHost()),
        );

        return new ProductViewFactory(
            new VariantPriceResolver($pricesCalculator),
            $availabilityChecker,
            new ChannelUrlGenerator($router),
            $imageCacheManager,
            new NullLogger(),
        );
    }

    private function createChannel(?string $hostname): ChannelInterface
    {
        $currency = $this->createStub(CurrencyInterface::class);
        $currency->method('getCode')->willReturn('CZK');

        $channel = $this->createStub(ChannelInterface::class);
        $channel->method('getCode')->willReturn('DEFAULT');
        $channel->method('getHostname')->willReturn($hostname);
        $channel->method('getBaseCurrency')->willReturn($currency);

        return $channel;
    }

    private function createVariant(): ProductVariantInterface
    {
        $productTranslation = $this->createStub(ProductTranslationInterface::class);
        $productTranslation->method('getName')->willReturn('Bagrování');
        $productTranslation->method('getSlug')->willReturn('bagrovani');

        $image = $this->createStub(ImageInterface::class);
        $image->method('getPath')->willReturn('main.jpg');

        $product = $this->createStub(ProductInterface::class);
        $product->method('getCode')->willReturn('BAGROVANI');
        $product->method('getTranslation')->willReturn($productTranslation);
        $product->method('getImagesByType')->willReturn(new ArrayCollection([$image]));
        $product->method('getImages')->willReturn(new ArrayCollection([$image]));

        $variantTranslation = $this->createStub(ProductVariantTranslationInterface::class);
        $variantTranslation->method('getName')->willReturn(null);

        $variant = $this->createStub(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('BAGROVANI_default');
        $variant->method('getProduct')->willReturn($product);
        $variant->method('getTranslation')->willReturn($variantTranslation);
        $variant->method('getImages')->willReturn(new ArrayCollection([$image]));

        return $variant;
    }

    private function createContext(): RequestContext
    {
        $context = new RequestContext();
        $context->setScheme('https');
        $context->setHost('request.example');

        return $context;
    }

    public function testBothTheProductUrlAndTheImageUrlCarryTheChannelHost(): void
    {
        $context = $this->createContext();
        $factory = $this->createFactory($context);

        $item = $factory->createItem($this->createVariant(), $this->createChannel('other-channel.example'), 'cs_CZ');

        self::assertNotNull($item);
        self::assertSame('https://other-channel.example/zazitek/bagrovani', $item->url);
        self::assertSame('https://other-channel.example/media/cache/main.jpg', $item->imageUrl);
        self::assertSame('request.example', $context->getHost());
    }

    public function testTheRequestHostStandsInWhenTheChannelHasNoHostname(): void
    {
        $context = $this->createContext();
        $factory = $this->createFactory($context);

        $item = $factory->createItem($this->createVariant(), $this->createChannel(null), 'cs_CZ');

        self::assertNotNull($item);
        self::assertSame('https://request.example/zazitek/bagrovani', $item->url);
        self::assertSame('https://request.example/media/cache/main.jpg', $item->imageUrl);
    }
}
