<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit\DataSource;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use MonsieurBiz\SyliusCmsPagePlugin\Entity\Page;
use MonsieurBiz\SyliusCmsPagePlugin\Entity\PageInterface;
use MonsieurBiz\SyliusCmsPagePlugin\Entity\PageTranslationInterface;
use MonsieurBiz\SyliusCmsPagePlugin\Repository\PageRepository;
use FluffyDiscord\SyliusHonkersBundle\Channel\ChannelResolver;
use FluffyDiscord\SyliusHonkersBundle\Channel\ChannelUrlGenerator;
use FluffyDiscord\Honkers\Cursor\CursorCodec;
use FluffyDiscord\SyliusHonkersBundle\DataSource\CmsPagesDataSource;
use FluffyDiscord\Honkers\DTO\SourceQuery;
use FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Fixtures\ThrowingHtmlToText;
use FluffyDiscord\Honkers\Text\HtmlToText;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;
use MonsieurBiz\SyliusRichEditorPlugin\Twig\RichEditorExtension;
use MonsieurBiz\SyliusRichEditorPlugin\UiElement\Metadata;
use MonsieurBiz\SyliusRichEditorPlugin\UiElement\Registry;
use MonsieurBiz\SyliusRichEditorPlugin\UiElement\UiElement;
use Symfony\Bridge\Twig\AppVariable;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class CmsPagesDataSourceTest extends TestCase
{
    private ?string $capturedDql = null;

    private function createDataSource(
        array $pages,
        RouterInterface $router,
        ?HtmlToText $htmlToText = null,
        ?LoggerInterface $logger = null,
        ?string $channelHostname = null,
    ): CmsPagesDataSource {
        $query = $this->createStub(Query::class);
        $query->method('setParameters')->willReturnSelf();
        $query->method('setFirstResult')->willReturnSelf();
        $query->method('setMaxResults')->willReturnSelf();
        $query->method('getResult')->willReturn($pages);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('createQuery')->willReturnCallback(
            function (string $dql) use ($query): Query {
                $this->capturedDql = $dql;

                return $query;
            },
        );

        $pageRepository = $this->createStub(PageRepository::class);
        $pageRepository->method('createQueryBuilder')->willReturnCallback(
            fn (string $alias): QueryBuilder => (new QueryBuilder($entityManager))
                ->select($alias)
                ->from(Page::class, $alias),
        );

        $channel = $this->createStub(ChannelInterface::class);
        $channel->method('getHostname')->willReturn($channelHostname);

        $channelResolver = $this->createStub(ChannelResolver::class);
        $channelResolver->method('getChannel')->willReturn($channel);

        return new CmsPagesDataSource(
            $pageRepository,
            $channelResolver,
            new CursorCodec(),
            $htmlToText ?? new HtmlToText(),
            new ChannelUrlGenerator($router),
            $this->createRichEditor(),
            $logger ?? new NullLogger(),
        );
    }

    private function createRichEditor(): RichEditorExtension
    {
        $loader = new FilesystemLoader();
        $loader->addPath(
            dirname(__DIR__, 3) . '/vendor/monsieurbiz/sylius-rich-editor-plugin/src/Resources/views',
            'MonsieurBizSyliusRichEditorPlugin',
        );
        $twig = new Environment($loader, ['strict_variables' => true]);
        $app = new AppVariable();
        $app->setRequestStack(new RequestStack());
        $twig->addGlobal('app', $app);

        $registry = new Registry();
        $registry->addUiElement($this->createUiElement('monsieurbiz.html', 'html.html.twig'));
        $registry->addUiElement($this->createUiElement('broken.element', 'missing.html.twig'));

        $richEditor = new RichEditorExtension(
            $registry,
            $twig,
            'monsieurbiz.html',
            'content',
            '/media/',
            sys_get_temp_dir(),
        );
        $twig->addExtension($richEditor);

        return $richEditor;
    }

    private function createUiElement(string $code, string $frontTemplate): UiElement
    {
        $uiElement = new UiElement();
        $uiElement->setMetadata(Metadata::fromCodeAndConfiguration($code, [
            'classes' => ['form' => 'unused'],
            'templates' => [
                'admin_render' => '@MonsieurBizSyliusRichEditorPlugin/admin/ui_element/html.html.twig',
                'front_render' => '@MonsieurBizSyliusRichEditorPlugin/shop/ui_element/' . $frontTemplate,
            ],
            'enabled' => true,
        ]));

        return $uiElement;
    }

    private function createPage(
        int $id = 7,
        string $code = 'about',
        string $slug = 'about-us',
        string $title = 'About us',
        ?string $content = '<p>Hello</p>',
    ): PageInterface {
        $translation = $this->createStub(PageTranslationInterface::class);
        $translation->method('getSlug')->willReturn($slug);
        $translation->method('getTitle')->willReturn($title);
        $translation->method('getContent')->willReturn($content);

        $page = $this->createStub(PageInterface::class);
        $page->method('getId')->willReturn($id);
        $page->method('getCode')->willReturn($code);
        $page->method('getTranslation')->willReturn($translation);
        $page->method('getUpdatedAt')->willReturn(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        return $page;
    }

    public function testQueryFiltersUnpublishedAndExpiredPages(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturn('https://shop.example/about-us');
        $dataSource = $this->createDataSource([], $router);

        $dataSource->getDocuments(new SourceQuery('cs_CZ'));

        self::assertNotNull($this->capturedDql);
        self::assertStringContainsString('page.enabled = :enabled', $this->capturedDql);
        self::assertStringContainsString('page.publishAt IS NULL OR page.publishAt <= :now', $this->capturedDql);
        self::assertStringContainsString('page.unpublishAt IS NULL OR page.unpublishAt >= :now', $this->capturedDql);
        self::assertStringContainsString('MEMBER OF page.channels', $this->capturedDql);
        self::assertStringContainsString('pageTranslation.locale = :locale', $this->capturedDql);
    }

    public function testUrlIsGeneratedWithoutLocaleParameter(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with('monsieurbiz_cms_page_show', ['slug' => 'about-us'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://shop.example/about-us');
        $dataSource = $this->createDataSource([$this->createPage()], $router);

        $page = $dataSource->getDocuments(new SourceQuery('cs_CZ'));

        self::assertCount(1, $page->documents);
        $document = $page->documents[0];
        self::assertSame('about', $document->id);
        self::assertSame('https://shop.example/about-us', $document->url);
        self::assertSame('About us', $document->title);
        self::assertStringContainsString('Hello', $document->text);
        self::assertNull($page->nextCursor);
    }

    public function testRichEditorContentIsIndexedAsItsRenderedText(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturn('https://shop.example/sharpening');
        $richEditorContent = json_encode([
            ['code' => 'monsieurbiz.html', 'data' => ['content' => '<h2>Sharpening</h2><p>Send it blunt, get it sharp.</p>']],
        ]);
        $dataSource = $this->createDataSource(
            [$this->createPage(3, 'sharpening', 'sharpening', 'Sharpening', $richEditorContent)],
            $router,
        );

        $page = $dataSource->getDocuments(new SourceQuery('cs_CZ'));

        $text = $page->documents[0]->text;
        self::assertStringContainsString('Send it blunt, get it sharp.', $text);
        self::assertStringNotContainsString('"code":', $text);
    }

    public function testARichEditorElementThatFailsToRenderSkipsOnlyItsPage(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturn('https://shop.example/page');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::isString(), self::callback(
                fn (array $context): bool => $context['page'] === 'broken',
            ));
        $brokenContent = json_encode([['code' => 'broken.element', 'data' => ['content' => 'x']]]);
        $brokenPage = $this->createPage(1, 'broken', 'broken-page', 'Broken page', $brokenContent);
        $plainPage = $this->createPage(2, 'plain', 'plain-page', 'Plain page');
        $dataSource = $this->createDataSource([$brokenPage, $plainPage], $router, null, $logger);

        $page = $dataSource->getDocuments(new SourceQuery('cs_CZ'));

        self::assertCount(1, $page->documents);
        self::assertSame('plain', $page->documents[0]->id);
    }

    public function testTheUrlIsBuiltOnTheResolvedChannelsHostname(): void
    {
        $context = new RequestContext();
        $context->setScheme('https');
        $context->setHost('request.example');

        $router = $this->createStub(RouterInterface::class);
        $router->method('getContext')->willReturn($context);
        $router->method('generate')->willReturnCallback(
            static fn (): string => sprintf('%s://%s/about-us', $context->getScheme(), $context->getHost()),
        );

        $dataSource = $this->createDataSource(
            [$this->createPage()],
            $router,
            null,
            null,
            'other-channel.example',
        );

        $page = $dataSource->getDocuments(new SourceQuery('cs_CZ'));

        self::assertSame('https://other-channel.example/about-us', $page->documents[0]->url);
    }

    public function testConversionFailureSkipsOnlyTheAffectedDocument(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturn('https://shop.example/page');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::isString(), self::callback(
                fn (array $context): bool => $context['page'] === 'broken',
            ));

        $brokenPage = $this->createPage(1, 'broken', 'broken-page', 'Broken page');
        $plainPage = $this->createPage(2, 'plain', 'plain-page', 'Plain page', null);
        $dataSource = $this->createDataSource(
            [$brokenPage, $plainPage],
            $router,
            new ThrowingHtmlToText(),
            $logger,
        );

        $page = $dataSource->getDocuments(new SourceQuery('cs_CZ'));

        self::assertCount(1, $page->documents);
        self::assertSame('plain', $page->documents[0]->id);
    }
}
