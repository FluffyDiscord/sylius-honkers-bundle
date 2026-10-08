<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Widget;

use FluffyDiscord\Honkers\Widget\WidgetSnippet;
use FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures\ChannelCredentialsProviderDouble;
use FluffyDiscord\SyliusHonkersPlugin\Twig\ChatbotWidgetExtension;
use FluffyDiscord\SyliusHonkersPlugin\Twig\ChatbotWidgetRuntime;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

class WidgetTemplateTest extends TestCase
{
    public function testFallsBackToTheHonkersCdnWhenCdnUrlIsEmpty(): void
    {
        $html = $this->renderWidget('');

        self::assertStringContainsString('src="https://honkers.b-cdn.net/widget/v1/chat.js"', $html);
    }

    public function testUsesCdnUrlWhenConfigured(): void
    {
        $html = $this->renderWidget('https://cdn.example.com/chat.js');

        self::assertStringContainsString('src="https://cdn.example.com/chat.js"', $html);
    }

    public function testNeverRendersAnEmptyScriptSource(): void
    {
        $html = $this->renderWidget('');

        self::assertStringNotContainsString('src=""', $html);
    }

    private function renderWidget(string $widgetCdnUrl): string
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 3) . '/templates');
        $twig = new Environment($loader);
        $twig->addExtension(new ChatbotWidgetExtension());

        $credentialsProvider = new ChannelCredentialsProviderDouble(currentSiteKey: 'pk_test');
        $runtime = new ChatbotWidgetRuntime($credentialsProvider, new WidgetSnippet());
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            ChatbotWidgetRuntime::class => fn (): ChatbotWidgetRuntime => $runtime,
        ]));

        return $twig->render('shop/widget.html.twig', [
            'app' => ['locale' => 'cs_CZ'],
            'backend_url' => 'https://chat.example.com',
            'widget_cdn_url' => $widgetCdnUrl,
        ]);
    }
}
