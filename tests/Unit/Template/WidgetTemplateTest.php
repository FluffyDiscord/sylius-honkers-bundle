<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Template;

use FluffyDiscord\Honkers\Widget\WidgetSnippet;
use FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures\AppVariableDouble;
use FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures\ChannelCredentialsProviderDouble;
use FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures\HookableMetadataDouble;
use FluffyDiscord\SyliusHonkersPlugin\Twig\ChatbotWidgetExtension;
use FluffyDiscord\SyliusHonkersPlugin\Twig\ChatbotWidgetRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

class WidgetTemplateTest extends TestCase
{
    public function testTheWidgetRendersFromTheTwigHookMetadata(): void
    {
        $rendered = $this->render(['hookable_metadata' => new HookableMetadataDouble($this->getWidgetContext())]);

        self::assertSame($this->getExpectedMarkup('site-key'), $rendered);
    }

    public function testTheWidgetRendersFromTemplateBlockVariables(): void
    {
        $rendered = $this->render($this->getWidgetContext());

        self::assertSame($this->getExpectedMarkup('site-key'), $rendered);
    }

    #[DataProvider('provideCurrentSiteKeys')]
    public function testTheWidgetCarriesTheSiteKeyOfTheCurrentSite(string $currentSiteKey): void
    {
        $expectedMarkup = $this->getExpectedMarkup($currentSiteKey);

        $renderedFromHook = $this->render(
            ['hookable_metadata' => new HookableMetadataDouble($this->getWidgetContext())],
            $currentSiteKey,
        );
        $renderedFromTemplateBlock = $this->render($this->getWidgetContext(), $currentSiteKey);
        $renderedFromDirectInclude = $this->renderDirectInclude($currentSiteKey);

        self::assertSame($expectedMarkup, $renderedFromHook);
        self::assertSame($expectedMarkup, $renderedFromTemplateBlock);
        self::assertSame($expectedMarkup, $renderedFromDirectInclude);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCurrentSiteKeys(): iterable
    {
        yield 'current site' => ['sk-key'];
        yield 'no current site renders nothing' => [''];
    }

    public function testTheWidgetRendersWithASiteKeyAndNoIngestSecret(): void
    {
        $loader = new FilesystemLoader(__DIR__ . '/../../../templates');
        $twig = $this->createEnvironment($loader, 'sk-key', '');

        $rendered = trim($twig->render('shop/widget.html.twig', $this->getWidgetContext()));

        self::assertSame($this->getExpectedMarkup('sk-key'), $rendered);
    }

    public function testTheScriptLoadsWithoutDeferWhenDeferIsOff(): void
    {
        $widgetContext = ['defer' => false] + $this->getWidgetContext();

        $renderedFromHook = $this->render(['hookable_metadata' => new HookableMetadataDouble($widgetContext)]);
        $renderedFromTemplateBlock = $this->render($widgetContext);

        self::assertStringStartsWith('<script src="https://cdn.test/chat.js"></script>', $renderedFromHook);
        self::assertStringStartsWith('<script src="https://cdn.test/chat.js"></script>', $renderedFromTemplateBlock);
    }

    /**
     * @return array<string, string|bool>
     */
    private function getWidgetContext(): array
    {
        return [
            'backend_url' => 'https://backend.test',
            'widget_cdn_url' => 'https://cdn.test/chat.js',
            'defer' => true,
        ];
    }

    private function getExpectedMarkup(string $siteKey): string
    {
        if ($siteKey === '') {
            return '';
        }

        return '<script src="https://cdn.test/chat.js" defer></script>' . "\n"
            . '<ai-chat-widget site-key="' . $siteKey . '" locale="cs_CZ" backend-url="https://backend.test"></ai-chat-widget>';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(array $context, string $currentSiteKey = 'site-key'): string
    {
        $twig = $this->createEnvironment(new FilesystemLoader(__DIR__ . '/../../../templates'), $currentSiteKey);

        return trim($twig->render('shop/widget.html.twig', $context));
    }

    private function renderDirectInclude(string $currentSiteKey): string
    {
        $shopLayoutLoader = new ArrayLoader([
            'shop_layout.html.twig' => "{% include 'shop/widget.html.twig' with chatbot_widget only %}",
        ]);
        $loader = new ChainLoader([$shopLayoutLoader, new FilesystemLoader(__DIR__ . '/../../../templates')]);
        $twig = $this->createEnvironment($loader, $currentSiteKey);

        return trim($twig->render('shop_layout.html.twig', ['chatbot_widget' => $this->getWidgetContext()]));
    }

    private function createEnvironment(
        LoaderInterface $loader,
        string $currentSiteKey,
        string $ingestSecret = 'ingest-secret',
    ): Environment {
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addGlobal('app', new AppVariableDouble('cs_CZ'));
        $twig->addExtension(new ChatbotWidgetExtension());

        $credentialsProvider = new ChannelCredentialsProviderDouble(currentSiteKey: $currentSiteKey, ingestSecret: $ingestSecret);
        $runtime = new ChatbotWidgetRuntime($credentialsProvider, new WidgetSnippet());
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            ChatbotWidgetRuntime::class => fn (): ChatbotWidgetRuntime => $runtime,
        ]));

        return $twig;
    }
}
