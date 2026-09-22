<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit\EventListener;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use FluffyDiscord\SyliusHonkersBundle\Channel\SiteKeyResolver;
use FluffyDiscord\SyliusHonkersBundle\Enum\CatalogSourceName;
use FluffyDiscord\SyliusHonkersBundle\EventListener\CatalogChangeListener;
use FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Fixtures\RecordingCatalogChangeNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTranslationInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Locale\Model\Locale;
use Sylius\Component\Locale\Provider\LocaleCollectionProviderInterface;
use Sylius\Component\Taxonomy\Model\TaxonTranslationInterface;

class CatalogChangeListenerTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function getEveryLocaleCodeInTheDatabase(): array
    {
        return ['cs_CZ', 'de_AT', 'de_DE', 'en_US', 'hr_HR', 'hu_HU', 'pl_PL', 'ro_RO', 'ru_RU', 'sk_SK', 'sl_SI'];
    }

    private function createNotifier(): RecordingCatalogChangeNotifier
    {
        $siteKeyResolver = new SiteKeyResolver($this->createStub(ChannelRepositoryInterface::class), 'site-key', []);

        return new RecordingCatalogChangeNotifier($siteKeyResolver);
    }

    private function createListener(RecordingCatalogChangeNotifier $notifier): CatalogChangeListener
    {
        $localeCollectionProvider = $this->createStub(LocaleCollectionProviderInterface::class);
        $localeCollectionProvider->method('getAll')->willReturn($this->createLocales($this->getEveryLocaleCodeInTheDatabase()));

        return new CatalogChangeListener($notifier, new NullLogger(), $localeCollectionProvider);
    }

    /**
     * @param list<string> $codes
     *
     * @return list<Locale>
     */
    private function createLocales(array $codes): array
    {
        $locales = [];

        foreach ($codes as $code) {
            $locale = new Locale();
            $locale->setCode($code);
            $locales[] = $locale;
        }

        return $locales;
    }

    private function createProduct(string $code = 'T-SHIRT-01'): ProductInterface
    {
        $variant = $this->createStub(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn($code . '_default');

        $product = $this->createStub(ProductInterface::class);
        $product->method('getCode')->willReturn($code);
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $variant->method('getProduct')->willReturn($product);

        return $product;
    }

    private function createUpdateArgs(object $entity): PostUpdateEventArgs
    {
        return new PostUpdateEventArgs($entity, $this->createStub(EntityManagerInterface::class));
    }

    /**
     * @return list<string>
     */
    private function collectNotifiedLocales(
        RecordingCatalogChangeNotifier $notifier,
        CatalogSourceName $expectedSource,
        string $expectedExternalId,
    ): array {
        $locales = [];
        foreach ($notifier->collectedChanges as [$source, $locale, $externalId]) {
            self::assertSame($expectedSource, $source);
            self::assertSame($expectedExternalId, $externalId);
            $locales[] = $locale;
        }

        return $locales;
    }

    public function testProductChangeNotifiesEveryLocaleOfTheLocaleSource(): void
    {
        $notifier = $this->createNotifier();
        $listener = $this->createListener($notifier);

        $listener->postUpdate($this->createUpdateArgs($this->createProduct()));

        $notifiedLocales = $this->collectNotifiedLocales($notifier, CatalogSourceName::Products, 'T-SHIRT-01_default');
        self::assertSame($this->getEveryLocaleCodeInTheDatabase(), $notifiedLocales);
    }

    public function testProductTranslationChangeNotifiesItsOwnLocaleOnly(): void
    {
        $notifier = $this->createNotifier();
        $listener = $this->createListener($notifier);

        $translation = $this->createStub(ProductTranslationInterface::class);
        $translation->method('getLocale')->willReturn('en_US');
        $translation->method('getTranslatable')->willReturn($this->createProduct());

        $listener->postUpdate($this->createUpdateArgs($translation));

        self::assertSame(
            [[CatalogSourceName::Products, 'en_US', 'T-SHIRT-01_default']],
            $notifier->collectedChanges,
        );
    }

    public function testTaxonChangeNotifiesCategoriesForEveryLocaleAndNoProducts(): void
    {
        $notifier = $this->createNotifier();
        $listener = $this->createListener($notifier);

        $taxon = $this->createStub(TaxonInterface::class);
        $taxon->method('getCode')->willReturn('T_SHIRTS');

        $listener->postUpdate($this->createUpdateArgs($taxon));

        $notifiedLocales = $this->collectNotifiedLocales($notifier, CatalogSourceName::Categories, 'T_SHIRTS');
        self::assertSame($this->getEveryLocaleCodeInTheDatabase(), $notifiedLocales);
    }

    public function testTaxonRenameNotifiesCategoriesForTheTranslationLocaleOnly(): void
    {
        $notifier = $this->createNotifier();
        $listener = $this->createListener($notifier);

        $taxon = $this->createStub(TaxonInterface::class);
        $taxon->method('getCode')->willReturn('T_SHIRTS');

        $translation = $this->createStub(TaxonTranslationInterface::class);
        $translation->method('getLocale')->willReturn('sk_SK');
        $translation->method('getTranslatable')->willReturn($taxon);

        $listener->postUpdate($this->createUpdateArgs($translation));

        self::assertSame(
            [[CatalogSourceName::Categories, 'sk_SK', 'T_SHIRTS']],
            $notifier->collectedChanges,
        );
    }

    public function testRemovedProductIsNotifiedByItsCode(): void
    {
        $notifier = $this->createNotifier();
        $listener = $this->createListener($notifier);

        $args = new PostRemoveEventArgs(
            $this->createProduct('GONE-01'),
            $this->createStub(EntityManagerInterface::class),
        );
        $listener->postRemove($args);

        $notifiedLocales = $this->collectNotifiedLocales($notifier, CatalogSourceName::Products, 'GONE-01_default');
        self::assertSame($this->getEveryLocaleCodeInTheDatabase(), $notifiedLocales);
    }

    public function testDisablingAVariantNotifiesThatVariantAlone(): void
    {
        $notifier = $this->createNotifier();
        $listener = $this->createListener($notifier);

        $product = $this->createProduct();

        $listener->postUpdate($this->createUpdateArgs($product->getVariants()->first()));

        $notifiedLocales = $this->collectNotifiedLocales($notifier, CatalogSourceName::Products, 'T-SHIRT-01_default');
        self::assertSame($this->getEveryLocaleCodeInTheDatabase(), $notifiedLocales);
    }

    public function testAPriceChangeNotifiesThePricedVariant(): void
    {
        $notifier = $this->createNotifier();
        $listener = $this->createListener($notifier);

        $product = $this->createProduct();
        $channelPricing = $this->createStub(ChannelPricingInterface::class);
        $channelPricing->method('getProductVariant')->willReturn($product->getVariants()->first());

        $listener->postUpdate($this->createUpdateArgs($channelPricing));

        $notifiedLocales = $this->collectNotifiedLocales($notifier, CatalogSourceName::Products, 'T-SHIRT-01_default');
        self::assertSame($this->getEveryLocaleCodeInTheDatabase(), $notifiedLocales);
    }

    public function testAProductChangeFansOutToEveryVariantItOwns(): void
    {
        $notifier = $this->createNotifier();
        $listener = $this->createListener($notifier);

        $firstVariant = $this->createStub(ProductVariantInterface::class);
        $firstVariant->method('getCode')->willReturn('T-SHIRT-01_black');
        $secondVariant = $this->createStub(ProductVariantInterface::class);
        $secondVariant->method('getCode')->willReturn('T-SHIRT-01_silver');

        $product = $this->createStub(ProductInterface::class);
        $product->method('getCode')->willReturn('T-SHIRT-01');
        $product->method('getVariants')->willReturn(new ArrayCollection([$firstVariant, $secondVariant]));

        $listener->postUpdate($this->createUpdateArgs($product));

        $notifiedIds = [];
        foreach ($notifier->collectedChanges as [, , $externalId]) {
            $notifiedIds[$externalId] = true;
        }

        self::assertSame(['T-SHIRT-01_black', 'T-SHIRT-01_silver'], array_keys($notifiedIds));
    }

    public function testAFailingLocaleLookupDoesNotBreakTheFlush(): void
    {
        $notifier = $this->createNotifier();

        $localeCollectionProvider = $this->createStub(LocaleCollectionProviderInterface::class);
        $localeCollectionProvider->method('getAll')->willThrowException(new \RuntimeException('database gone'));
        $listener = new CatalogChangeListener($notifier, new NullLogger(), $localeCollectionProvider);

        $listener->postUpdate($this->createUpdateArgs($this->createProduct()));

        self::assertSame([], $notifier->collectedChanges);
    }
}
