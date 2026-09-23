<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\DataSource;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use FluffyDiscord\SyliusHonkersBundle\Channel\ChannelResolver;
use FluffyDiscord\Honkers\Contract\ChatbotDataSourceInterface;
use FluffyDiscord\SyliusHonkersBundle\Contract\ProductIndexabilityInterface;
use FluffyDiscord\SyliusHonkersBundle\Contract\ProductViewFactoryInterface;
use FluffyDiscord\Honkers\Cursor\CursorCodec;
use FluffyDiscord\Honkers\DTO\DocumentPage;
use FluffyDiscord\Honkers\DTO\SourceDefinition;
use FluffyDiscord\Honkers\DTO\SourceDocument;
use FluffyDiscord\Honkers\DTO\SourceQuery;
use FluffyDiscord\Honkers\Enum\CatalogSourceName;
use FluffyDiscord\Honkers\Enum\DocumentKind;
use FluffyDiscord\Honkers\Text\HtmlToText;
use Psr\Log\LoggerInterface;
use Sylius\Component\Attribute\Model\AttributeValueInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProductsDataSource implements ChatbotDataSourceInterface
{
    public function __construct(
        private readonly ChannelResolver              $channelResolver,
        private readonly ProductViewFactoryInterface  $productViewFactory,
        private readonly ProductIndexabilityInterface $productIndexability,
        private readonly CursorCodec                  $cursorCodec,
        private readonly HtmlToText                   $htmlToText,
        private readonly TranslatorInterface          $translator,
        private readonly LoggerInterface              $logger,

        #[Autowire(service: 'sylius.repository.product_variant')]
        private readonly ProductVariantRepositoryInterface $variantRepository,
    ) {
    }

    public function getDefinition(): SourceDefinition
    {
        return new SourceDefinition(
            CatalogSourceName::Products->value,
            'fluffydiscord_honkers.source.products.description',
        );
    }

    public function getDocuments(SourceQuery $query): DocumentPage
    {
        $repository = $this->variantRepository;
        if (!$repository instanceof EntityRepository) {
            throw new \LogicException(sprintf(
                'The product variant repository must be a Doctrine EntityRepository to build the chatbot query, got "%s".',
                $repository::class,
            ));
        }

        $locale = $query->locale;
        $channel = $this->channelResolver->getChannel();
        $isIdLookup = $query->hasIds();

        $queryBuilder = $repository->createQueryBuilder('variant')
            ->innerJoin('variant.product', 'product')
            ->innerJoin('product.translations', 'productTranslation', Join::WITH, 'productTranslation.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('variant.id', Criteria::ASC);

        if ($isIdLookup) {
            $queryBuilder->andWhere('variant.code IN (:codes)')->setParameter('codes', $query->ids);
        } else {
            $queryBuilder->setMaxResults($this->getPageSize());
            $lastId = $this->cursorCodec->decodeNumericId($query->cursor);
            if ($lastId !== null) {
                $queryBuilder->andWhere('variant.id > :lastId')->setParameter('lastId', $lastId);
            }
        }

        $variants = $queryBuilder->getQuery()->getResult();

        $documents = [];
        $lastFetchedId = null;
        foreach ($variants as $variant) {
            $lastFetchedId = $variant->getId();
            try {
                $document = $this->buildDocument($variant, $channel, $locale);
            } catch (\RuntimeException $exception) {
                $this->logger->error('Chatbot: building a product document failed, skipping it.', [
                    'variant' => $variant->getCode(),
                    'exception' => $exception,
                ]);

                continue;
            }
            if ($document !== null) {
                $documents[] = $document;
            }
        }

        if ($isIdLookup) {
            return new DocumentPage($documents, null);
        }

        $fetchedCount = count($variants);
        $nextCursor = null;
        if ($fetchedCount === $this->getPageSize() && $lastFetchedId !== null) {
            $nextCursor = $this->cursorCodec->encode((string) $lastFetchedId);
        }

        return new DocumentPage($documents, $nextCursor);
    }

    private function getPageSize(): int
    {
        return 200;
    }

    protected function buildDocument(ProductVariantInterface $variant, ChannelInterface $channel, string $locale): ?SourceDocument
    {
        $isIndexable = $this->productIndexability->isIndexable($variant, $channel);
        if (!$isIndexable) {
            return null;
        }

        $product = $variant->getProduct();
        if (!$product instanceof ProductInterface) {
            return null;
        }

        $item = $this->productViewFactory->createItem($variant, $channel, $locale);
        if ($item === null) {
            return null;
        }

        $attributes = $this->collectAttributes($product, $locale);
        $taxonNames = $this->collectTaxonNames($product, $locale);
        $optionValues = $this->collectOptionValues($variant, $locale);

        $metadata = [
            'code' => $item->code,
            'productCode' => $item->productCode,
            'name' => $item->name,
            'url' => $item->url,
            'imageUrl' => $item->imageUrl,
            'priceMinor' => $item->priceMinor,
            'currency' => $item->currency,
            'inStock' => $item->inStock,
            'taxons' => $this->collectTaxonCodes($product),
            'taxonNames' => $taxonNames,
            'attributes' => $attributes,
            'options' => $optionValues,
        ];

        $text = $this->buildText($product, $locale, $item->name, $attributes, $taxonNames, $optionValues);
        $updatedAt = $variant->getUpdatedAt() ?? $product->getUpdatedAt() ?? $product->getCreatedAt() ?? new \DateTimeImmutable();

        return new SourceDocument(
            $item->code,
            $item->url,
            $item->name,
            $text,
            DocumentKind::Product,
            $metadata,
            $updatedAt->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * @param array<string, string> $attributes
     * @param list<string>          $taxonNames
     * @param array<string, string> $optionValues
     */
    private function buildText(
        ProductInterface $product,
        string $locale,
        string $name,
        array $attributes,
        array $taxonNames,
        array $optionValues,
    ): string {
        $translation = $product->getTranslation($locale);
        $sections = [$name];

        $shortDescription = $translation->getShortDescription();
        if ($shortDescription !== null && $shortDescription !== '') {
            $sections[] = $this->htmlToText->convert($shortDescription);
        }

        $description = $translation->getDescription();
        if ($description !== null && $description !== '') {
            $sections[] = $this->htmlToText->convert($description);
        }

        $attributeLines = [];
        foreach ($attributes as $attributeName => $attributeValue) {
            $attributeLines[] = sprintf('%s: %s', $attributeName, $attributeValue);
        }
        if ($attributeLines !== []) {
            $sections[] = implode("\n", $attributeLines);
        }

        $optionLines = [];
        foreach ($optionValues as $optionName => $optionValue) {
            $optionLines[] = sprintf('%s: %s', $optionName, $optionValue);
        }
        if ($optionLines !== []) {
            $sections[] = implode("\n", $optionLines);
        }

        if ($taxonNames !== []) {
            $sections[] = implode(', ', $taxonNames);
        }

        $taxonPath = $this->buildMainTaxonPath($product, $locale);
        if ($taxonPath !== '') {
            $sections[] = $taxonPath;
        }

        return implode("\n\n", $sections);
    }

    /**
     * @return array<string, string>
     */
    private function collectAttributes(ProductInterface $product, string $locale): array
    {
        $attributes = [];
        foreach ($product->getAttributesByLocale($locale, $locale) as $attributeValue) {
            $attribute = $attributeValue->getAttribute();
            if ($attribute === null) {
                continue;
            }

            $name = $attribute->getTranslation($locale)->getName();
            $formattedValue = $this->formatAttributeValue($attributeValue, $locale);
            if ($name === null || $name === '' || $formattedValue === null || $formattedValue === '') {
                continue;
            }

            $attributes[$name] = $formattedValue;
        }

        return $attributes;
    }

    private function formatAttributeValue(AttributeValueInterface $attributeValue, string $locale): ?string
    {
        $value = $attributeValue->getValue();
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            $translationKey = $value
                ? 'fluffydiscord_honkers.attribute_value.yes'
                : 'fluffydiscord_honkers.attribute_value.no';

            return $this->translator->trans($translationKey, [], 'messages', $locale);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_array($value)) {
            $attribute = $attributeValue->getAttribute();
            $choices = [];
            if ($attribute !== null) {
                $choices = $attribute->getConfiguration()['choices'] ?? [];
            }
            $labels = [];
            foreach ($value as $choiceKey) {
                $labels[] = (string) ($choices[$choiceKey][$locale] ?? $choiceKey);
            }

            return implode(', ', $labels);
        }

        return (string) $value;
    }

    /**
     * @return array<string, string>
     */
    private function collectOptionValues(ProductVariantInterface $variant, string $locale): array
    {
        $options = [];
        foreach ($variant->getOptionValues() as $optionValue) {
            $option = $optionValue->getOption();
            if ($option === null) {
                continue;
            }

            $optionName = $option->getTranslation($locale)->getName();
            $value = $optionValue->getTranslation($locale)->getValue();
            if ($optionName === null || $optionName === '' || $value === null || $value === '') {
                continue;
            }

            $options[$optionName] = $value;
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private function collectTaxonNames(ProductInterface $product, string $locale): array
    {
        $names = [];
        foreach ($product->getTaxons() as $taxon) {
            $name = $taxon->getTranslation($locale)->getName();
            if ($name !== null && $name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private function collectTaxonCodes(ProductInterface $product): array
    {
        $codes = [];
        foreach ($product->getTaxons() as $taxon) {
            $code = $taxon->getCode();
            if ($code !== null && $code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    private function buildMainTaxonPath(ProductInterface $product, string $locale): string
    {
        $taxon = $product->getMainTaxon();
        if ($taxon === null) {
            return '';
        }

        $names = [];
        $current = $taxon;
        while ($current instanceof TaxonInterface) {
            $name = $current->getTranslation($locale)->getName();
            if ($name !== null && $name !== '') {
                $names[] = $name;
            }
            $current = $current->getParent();
        }

        return implode(' > ', array_reverse($names));
    }
}
