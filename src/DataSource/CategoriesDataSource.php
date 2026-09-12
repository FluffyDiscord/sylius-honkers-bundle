<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\DataSource;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use FluffyDiscord\SyliusChatbotBundle\Channel\ChannelResolver;
use FluffyDiscord\SyliusChatbotBundle\Contract\ChatbotDataSourceInterface;
use FluffyDiscord\SyliusChatbotBundle\Contract\ProductIndexabilityInterface;
use FluffyDiscord\SyliusChatbotBundle\Cursor\CursorCodec;
use FluffyDiscord\SyliusChatbotBundle\DTO\DocumentPage;
use FluffyDiscord\SyliusChatbotBundle\DTO\SourceDefinition;
use FluffyDiscord\SyliusChatbotBundle\DTO\SourceDocument;
use FluffyDiscord\SyliusChatbotBundle\DTO\SourceQuery;
use FluffyDiscord\SyliusChatbotBundle\Enum\CatalogSourceName;
use FluffyDiscord\SyliusChatbotBundle\Enum\DocumentKind;
use FluffyDiscord\SyliusChatbotBundle\Text\HtmlToText;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Sylius\Resource\Model\TimestampableInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

readonly class CategoriesDataSource implements ChatbotDataSourceInterface
{
    public function __construct(
        private TaxonRepositoryInterface     $taxonRepository,
        private ProductRepositoryInterface   $productRepository,
        private ProductIndexabilityInterface $productIndexability,
        private ChannelResolver              $channelResolver,
        private CursorCodec                  $cursorCodec,
        private HtmlToText                   $htmlToText,
        private RouterInterface              $router,
        private LoggerInterface              $logger,
    ) {
    }

    public function getDefinition(): SourceDefinition
    {
        return new SourceDefinition(
            CatalogSourceName::Categories->value,
            'fluffydiscord_sylius_chatbot.source.categories.description',
        );
    }

    public function getDocuments(SourceQuery $query): DocumentPage
    {
        $repository = $this->taxonRepository;
        if (!$repository instanceof EntityRepository) {
            throw new \LogicException(sprintf(
                'The taxon repository must be a Doctrine EntityRepository to build the chatbot query, got "%s".',
                $repository::class,
            ));
        }

        $locale = $query->locale;
        $channel = $this->channelResolver->getChannel();
        $isIdLookup = $query->hasIds();

        $queryBuilder = $repository->createQueryBuilder('taxon')
            ->innerJoin('taxon.translations', 'taxonTranslation', Join::WITH, 'taxonTranslation.locale = :locale')
            ->andWhere('taxon.enabled = :enabled')
            ->setParameter('locale', $locale)
            ->setParameter('enabled', true)
            ->orderBy('taxon.id', Criteria::ASC);

        $this->restrictToChannelTree($queryBuilder, $channel);

        if ($isIdLookup) {
            $queryBuilder->andWhere('taxon.code IN (:codes)')->setParameter('codes', $query->ids);
        } else {
            $queryBuilder->setMaxResults($this->getPageSize());
            $lastId = $this->cursorCodec->decodeNumericId($query->cursor);
            if ($lastId !== null) {
                $queryBuilder->andWhere('taxon.id > :lastId')->setParameter('lastId', $lastId);
            }
        }

        $taxons = $queryBuilder->getQuery()->getResult();
        $productCounts = $this->countProductsByTaxonCode($taxons, $channel, $repository->getClassName());

        $documents = [];
        $lastFetchedId = null;
        foreach ($taxons as $taxon) {
            $lastFetchedId = $taxon->getId();
            $productCount = $productCounts[(string) $taxon->getCode()] ?? 0;
            try {
                $document = $this->buildDocument($taxon, $locale, $productCount);
            } catch (\RuntimeException $exception) {
                $this->logger->error('Chatbot: building a category document failed, skipping it.', [
                    'taxon' => $taxon->getCode(),
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

        $fetchedCount = count($taxons);
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

    private function getTaxonRouteName(): string
    {
        return 'sylius_shop_product_index';
    }

    private function restrictToChannelTree(QueryBuilder $queryBuilder, ChannelInterface $channel): void
    {
        $menuTaxon = $channel->getMenuTaxon();
        if (!$menuTaxon instanceof TaxonInterface) {
            $queryBuilder->andWhere('taxon.parent IS NOT NULL');

            return;
        }

        $queryBuilder
            ->andWhere('taxon.root = :treeRoot')
            ->andWhere('taxon.left >= :treeLeft')
            ->andWhere('taxon.right <= :treeRight')
            ->setParameter('treeRoot', $menuTaxon->getRoot())
            ->setParameter('treeLeft', $menuTaxon->getLeft())
            ->setParameter('treeRight', $menuTaxon->getRight());
    }

    private function getSubtreeContainmentCondition(): string
    {
        return 'ancestorTaxon.root = descendantTaxon.root'
            . ' AND ancestorTaxon.left <= descendantTaxon.left'
            . ' AND ancestorTaxon.right >= descendantTaxon.right';
    }

    /**
     * @param list<TaxonInterface> $taxons
     * @param class-string         $taxonClassName
     *
     * @return array<string, int>
     */
    private function countProductsByTaxonCode(array $taxons, ChannelInterface $channel, string $taxonClassName): array
    {
        $repository = $this->productRepository;
        if (!$repository instanceof EntityRepository || $taxons === []) {
            return [];
        }

        $taxonCodes = [];
        foreach ($taxons as $taxon) {
            $taxonCodes[] = $taxon->getCode();
        }

        $pairs = $repository->createQueryBuilder('product')
            ->select('ancestorTaxon.code AS taxonCode', 'product.id AS productId')
            ->distinct()
            ->innerJoin('product.productTaxons', 'productTaxon')
            ->innerJoin('productTaxon.taxon', 'descendantTaxon')
            ->innerJoin($taxonClassName, 'ancestorTaxon', Join::WITH, $this->getSubtreeContainmentCondition())
            ->andWhere('ancestorTaxon.code IN (:taxonCodes)')
            ->andWhere('product.enabled = :enabled')
            ->andWhere(':channel MEMBER OF product.channels')
            ->setParameter('taxonCodes', $taxonCodes)
            ->setParameter('enabled', true)
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getResult();

        $indexableProductIds = $this->findIndexableProductIds($repository, $pairs);

        $countsByCode = [];
        foreach ($pairs as $pair) {
            $productId = (string) $pair['productId'];
            $isIndexable = isset($indexableProductIds[$productId]);
            if (!$isIndexable) {
                continue;
            }

            $taxonCode = (string) $pair['taxonCode'];
            $previousCount = $countsByCode[$taxonCode] ?? 0;
            $countsByCode[$taxonCode] = $previousCount + 1;
        }

        return $countsByCode;
    }

    /**
     * @param list<array{taxonCode: string, productId: int|string}> $pairs
     *
     * @return array<string, true>
     */
    private function findIndexableProductIds(EntityRepository $repository, array $pairs): array
    {
        $productIds = [];
        foreach ($pairs as $pair) {
            $productIds[(string) $pair['productId']] = true;
        }

        if ($productIds === []) {
            return [];
        }

        $products = $repository->createQueryBuilder('product')
            ->andWhere('product.id IN (:productIds)')
            ->setParameter('productIds', array_keys($productIds))
            ->getQuery()
            ->getResult();

        $channel = $this->channelResolver->getChannel();
        $indexableProductIds = [];
        foreach ($products as $product) {
            $hasIndexableVariant = $this->hasIndexableVariant($product, $channel);
            if ($hasIndexableVariant) {
                $indexableProductIds[(string) $product->getId()] = true;
            }
        }

        return $indexableProductIds;
    }

    private function hasIndexableVariant(ProductInterface $product, ChannelInterface $channel): bool
    {
        foreach ($product->getVariants() as $variant) {
            $isIndexable = $this->productIndexability->isIndexable($variant, $channel);

            if ($isIndexable) {
                return true;
            }
        }

        return false;
    }

    private function buildDocument(TaxonInterface $taxon, string $locale, int $productCount): ?SourceDocument
    {
        $code = $taxon->getCode();
        $translation = $taxon->getTranslation($locale);
        $name = $translation->getName();
        $slug = $translation->getSlug();
        if ($code === null || $code === '' || $name === null || $name === '' || $slug === null || $slug === '') {
            return null;
        }

        $url = $this->router->generate(
            $this->getTaxonRouteName(),
            ['slug' => $slug, '_locale' => $locale],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $path = $this->buildPath($taxon, $locale);
        $text = $path;

        $description = $translation->getDescription();
        if ($description !== null && $description !== '') {
            $text = $path . "\n\n" . $this->htmlToText->convert($description);
        }

        $metadata = [
            'code' => $code,
            'name' => $name,
            'path' => $path,
            'url' => $url,
            'productCount' => $productCount,
        ];

        return new SourceDocument(
            $code,
            $url,
            $name,
            $text,
            DocumentKind::Category,
            $metadata,
            $this->resolveUpdatedAt($taxon)->format(\DateTimeInterface::ATOM),
        );
    }

    private function buildPath(TaxonInterface $taxon, string $locale): string
    {
        $names = [];
        $current = $taxon;
        while ($current instanceof TaxonInterface) {
            $name = $current->getTranslation($locale)->getName();
            if ($name !== null && $name !== '') {
                $names[] = $name;
            }
            $current = $current->getParent();
        }

        return implode(' / ', array_reverse($names));
    }

    private function resolveUpdatedAt(TaxonInterface $taxon): \DateTimeInterface
    {
        if (!$taxon instanceof TimestampableInterface) {
            return new \DateTimeImmutable();
        }

        $updatedAt = $taxon->getUpdatedAt();
        if ($updatedAt !== null) {
            return $updatedAt;
        }

        $createdAt = $taxon->getCreatedAt();
        if ($createdAt !== null) {
            return $createdAt;
        }

        return new \DateTimeImmutable();
    }
}
