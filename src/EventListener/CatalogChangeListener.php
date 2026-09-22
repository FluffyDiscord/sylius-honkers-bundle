<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use FluffyDiscord\SyliusHonkersBundle\Enum\CatalogSourceName;
use FluffyDiscord\SyliusHonkersBundle\Ingest\CatalogChangeNotifier;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Locale\Provider\LocaleCollectionProviderInterface;
use Sylius\Component\Product\Model\ProductInterface;
use Sylius\Component\Product\Model\ProductTranslationInterface;
use Sylius\Component\Product\Model\ProductVariantInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface;
use Sylius\Component\Taxonomy\Model\TaxonTranslationInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
class CatalogChangeListener
{
    public function __construct(
        private readonly CatalogChangeNotifier $catalogChangeNotifier,
        private readonly LoggerInterface       $logger,

        #[Autowire(service: 'sylius.provider.locale_collection')]
        private readonly LocaleCollectionProviderInterface $localeCollectionProvider,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->collectChange($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->collectChange($args->getObject());
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->collectChange($args->getObject());
    }

    private function collectChange(object $entity): void
    {
        try {
            $this->dispatchChange($entity);
        } catch (\Throwable $exception) {
            $this->logger->warning('Chatbot: collecting a catalog change failed.', [
                'entity' => $entity::class,
                'exception' => $exception,
            ]);
        }
    }

    private function dispatchChange(object $entity): void
    {
        if ($entity instanceof ProductTranslationInterface) {
            $this->collectProductTranslationChange($entity);

            return;
        }

        if ($entity instanceof TaxonTranslationInterface) {
            $this->collectTaxonTranslationChange($entity);

            return;
        }

        if ($entity instanceof ChannelPricingInterface) {
            $this->collectChannelPricingChange($entity);

            return;
        }

        if ($entity instanceof ProductVariantInterface) {
            $this->collectVariantChange($entity);

            return;
        }

        if ($entity instanceof ProductInterface) {
            $this->collectProductChange($entity);

            return;
        }

        if ($entity instanceof TaxonInterface) {
            $this->collectTaxonChange($entity);
        }
    }

    private function collectChannelPricingChange(ChannelPricingInterface $channelPricing): void
    {
        $variant = $channelPricing->getProductVariant();

        if (!$variant instanceof ProductVariantInterface) {
            return;
        }

        $this->collectVariantChange($variant);
    }

    private function collectVariantChange(ProductVariantInterface $variant): void
    {
        $code = $variant->getCode();
        if ($code === null || $code === '') {
            return;
        }

        foreach ($this->getAllLocaleCodes() as $locale) {
            $this->catalogChangeNotifier->collect(CatalogSourceName::Products, $locale, $code);
        }
    }

    private function collectProductTranslationChange(ProductTranslationInterface $translation): void
    {
        $locale = $translation->getLocale();
        if ($locale === null || $locale === '') {
            return;
        }

        $product = $translation->getTranslatable();
        if (!$product instanceof ProductInterface) {
            return;
        }

        foreach ($this->readVariantCodes($product) as $variantCode) {
            $this->catalogChangeNotifier->collect(CatalogSourceName::Products, $locale, $variantCode);
        }
    }

    private function collectTaxonTranslationChange(TaxonTranslationInterface $translation): void
    {
        $locale = $translation->getLocale();
        if ($locale === null || $locale === '') {
            return;
        }

        $taxon = $translation->getTranslatable();
        if (!$taxon instanceof TaxonInterface) {
            return;
        }

        $code = $taxon->getCode();
        if ($code === null || $code === '') {
            return;
        }

        $this->catalogChangeNotifier->collect(CatalogSourceName::Categories, $locale, $code);
    }

    private function collectProductChange(ProductInterface $product): void
    {
        $variantCodes = $this->readVariantCodes($product);

        foreach ($this->getAllLocaleCodes() as $locale) {
            foreach ($variantCodes as $variantCode) {
                $this->catalogChangeNotifier->collect(CatalogSourceName::Products, $locale, $variantCode);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function readVariantCodes(ProductInterface $product): array
    {
        $codes = [];

        foreach ($product->getVariants() as $variant) {
            $code = $variant->getCode();

            if ($code !== null && $code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    private function collectTaxonChange(TaxonInterface $taxon): void
    {
        $code = $taxon->getCode();
        if ($code === null || $code === '') {
            return;
        }

        foreach ($this->getAllLocaleCodes() as $locale) {
            $this->catalogChangeNotifier->collect(CatalogSourceName::Categories, $locale, $code);
        }
    }

    /**
     * @return list<string>
     */
    private function getAllLocaleCodes(): array
    {
        $codes = [];

        foreach ($this->localeCollectionProvider->getAll() as $locale) {
            $code = $locale->getCode();

            if ($code !== null && $code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
