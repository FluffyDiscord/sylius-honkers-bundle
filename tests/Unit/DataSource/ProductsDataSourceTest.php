<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit\DataSource;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use FluffyDiscord\SyliusHonkersBundle\Channel\ChannelResolver;
use FluffyDiscord\SyliusHonkersBundle\Contract\ProductIndexabilityInterface;
use FluffyDiscord\SyliusHonkersBundle\Contract\ProductViewFactoryInterface;
use FluffyDiscord\Honkers\Cursor\CursorCodec;
use FluffyDiscord\SyliusHonkersBundle\DataSource\ProductsDataSource;
use FluffyDiscord\SyliusHonkersBundle\DTO\ProductItem;
use FluffyDiscord\Honkers\DTO\SourceQuery;
use FluffyDiscord\Honkers\Enum\DocumentKind;
use FluffyDiscord\Honkers\Text\HtmlToText;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductVariantRepository;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTranslationInterface;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Product\Model\ProductVariantTranslationInterface;
use Sylius\Component\Taxonomy\Model\TaxonTranslationInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProductsDataSourceTest extends TestCase
{
    private ?string $capturedDql = null;

    private function createDataSource(array $variants, ?\Closure $isIndexable = null): ProductsDataSource
    {
        $query = $this->createStub(Query::class);
        $query->method('setParameters')->willReturnSelf();
        $query->method('setFirstResult')->willReturnSelf();
        $query->method('setMaxResults')->willReturnSelf();
        $query->method('getResult')->willReturn($variants);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('createQuery')->willReturnCallback(
            function (string $dql) use ($query): Query {
                $this->capturedDql = $dql;

                return $query;
            },
        );

        $variantRepository = $this->createStub(ProductVariantRepository::class);
        $variantRepository->method('createQueryBuilder')->willReturnCallback(
            fn (string $alias): QueryBuilder => (new QueryBuilder($entityManager))
                ->select($alias)
                ->from(ProductVariant::class, $alias),
        );

        $channelResolver = $this->createStub(ChannelResolver::class);
        $channelResolver->method('getChannel')->willReturn($this->createStub(ChannelInterface::class));

        $productViewFactory = $this->createStub(ProductViewFactoryInterface::class);
        $productViewFactory->method('createItem')->willReturn(new ProductItem(
            'T-SHIRT-01_black',
            'T-SHIRT-01',
            'T-Shirt',
            'https://shop.example/products/t-shirt',
            249900,
            'CZK',
            null,
            true,
        ));

        $productIndexability = $this->createStub(ProductIndexabilityInterface::class);
        $productIndexability->method('isIndexable')->willReturnCallback(
            $isIndexable ?? static fn (): bool => true,
        );

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new ProductsDataSource(
            $channelResolver,
            $productViewFactory,
            $productIndexability,
            new CursorCodec(),
            new HtmlToText(),
            $translator,
            new NullLogger(),
            $variantRepository,
        );
    }

    private function createTaxon(string $code, string $name): TaxonInterface
    {
        $translation = $this->createStub(TaxonTranslationInterface::class);
        $translation->method('getName')->willReturn($name);

        $taxon = $this->createStub(TaxonInterface::class);
        $taxon->method('getCode')->willReturn($code);
        $taxon->method('getTranslation')->willReturn($translation);
        $taxon->method('getParent')->willReturn(null);

        return $taxon;
    }

    private function createProduct(): ProductInterface
    {
        $translation = $this->createStub(ProductTranslationInterface::class);
        $translation->method('getName')->willReturn('T-Shirt');
        $translation->method('getShortDescription')->willReturn(null);
        $translation->method('getDescription')->willReturn(null);

        $mainTaxon = $this->createTaxon('CLOTHING', 'Clothing');

        $product = $this->createStub(ProductInterface::class);
        $product->method('getId')->willReturn(5);
        $product->method('getCode')->willReturn('T-SHIRT-01');
        $product->method('getTranslation')->willReturn($translation);
        $product->method('getAttributesByLocale')->willReturn(new ArrayCollection());
        $product->method('getTaxons')->willReturn(new ArrayCollection([
            $mainTaxon,
            $this->createTaxon('T_SHIRTS', 'T-Shirts'),
        ]));
        $product->method('getMainTaxon')->willReturn($mainTaxon);
        $product->method('getUpdatedAt')->willReturn(new \DateTime('2026-01-01T00:00:00+00:00'));

        return $product;
    }

    private function createVariant(int $id = 5): ProductVariantInterface
    {
        $translation = $this->createStub(ProductVariantTranslationInterface::class);
        $translation->method('getName')->willReturn(null);

        $variant = $this->createStub(ProductVariantInterface::class);
        $variant->method('getId')->willReturn($id);
        $variant->method('getCode')->willReturn('T-SHIRT-01_black');
        $variant->method('getProduct')->willReturn($this->createProduct());
        $variant->method('getTranslation')->willReturn($translation);
        $variant->method('getOptionValues')->willReturn(new ArrayCollection());
        $variant->method('getUpdatedAt')->willReturn(new \DateTime('2026-01-01T00:00:00+00:00'));

        return $variant;
    }

    public function testDocumentIsKeyedByVariantCodeAndCarriesItsProductCode(): void
    {
        $dataSource = $this->createDataSource([$this->createVariant()]);

        $page = $dataSource->getDocuments(new SourceQuery('cs_CZ'));

        self::assertCount(1, $page->documents);
        $document = $page->documents[0];
        self::assertSame('T-SHIRT-01_black', $document->id);
        self::assertSame('T-SHIRT-01_black', $document->metadata['code']);
        self::assertSame('T-SHIRT-01', $document->metadata['productCode']);
    }

    public function testMetadataTaxonsAreCodesAndNamesStayInTheText(): void
    {
        $dataSource = $this->createDataSource([$this->createVariant()]);

        $page = $dataSource->getDocuments(new SourceQuery('cs_CZ'));

        self::assertCount(1, $page->documents);
        $document = $page->documents[0];
        self::assertSame(DocumentKind::Product, $document->kind);
        self::assertSame(['CLOTHING', 'T_SHIRTS'], $document->metadata['taxons']);
        self::assertStringContainsString('Clothing, T-Shirts', $document->text);
        self::assertStringContainsString('Clothing', $document->text);
    }

    public function testDocumentCarriesNoContentHash(): void
    {
        $dataSource = $this->createDataSource([$this->createVariant()]);

        $page = $dataSource->getDocuments(new SourceQuery('cs_CZ'));

        self::assertArrayNotHasKey('contentHash', $page->documents[0]->jsonSerialize());
    }

    public function testIdLookupFiltersByVariantCodeAndIgnoresTheCursor(): void
    {
        $dataSource = $this->createDataSource([$this->createVariant()]);

        $page = $dataSource->getDocuments(new SourceQuery(
            locale: 'cs_CZ',
            cursor: base64_encode('1'),
            ids: ['T-SHIRT-01_black'],
        ));

        self::assertNotNull($this->capturedDql);
        self::assertStringContainsString('variant.code IN (:codes)', $this->capturedDql);
        self::assertStringNotContainsString('variant.id > :lastId', $this->capturedDql);
        self::assertNull($page->nextCursor);
        self::assertCount(1, $page->documents);
    }

    public function testCursorPaginationStillFiltersByLastId(): void
    {
        $dataSource = $this->createDataSource([$this->createVariant()]);

        $dataSource->getDocuments(new SourceQuery(locale: 'cs_CZ', cursor: base64_encode('1')));

        self::assertNotNull($this->capturedDql);
        self::assertStringContainsString('variant.id > :lastId', $this->capturedDql);
        self::assertStringNotContainsString('variant.code IN (:codes)', $this->capturedDql);
    }

    public function testNonIndexableVariantsAreSkipped(): void
    {
        $dataSource = $this->createDataSource([$this->createVariant()], static fn (): bool => false);

        $page = $dataSource->getDocuments(new SourceQuery('cs_CZ'));

        self::assertSame([], $page->documents);
    }

    public function testCursorAdvancesFromTheLastFetchedRowEvenWhenItIsNotIndexable(): void
    {
        $pageSize = 200;
        $variants = [];
        for ($id = 1; $id <= $pageSize; ++$id) {
            $variants[] = $this->createVariant($id);
        }

        $lastVariant = $variants[$pageSize - 1];
        $dataSource = $this->createDataSource(
            $variants,
            static fn (ProductVariantInterface $variant): bool => $variant !== $lastVariant,
        );

        $page = $dataSource->getDocuments(new SourceQuery('cs_CZ'));

        self::assertCount($pageSize - 1, $page->documents);
        self::assertSame(base64_encode((string) $pageSize), $page->nextCursor);
    }
}
