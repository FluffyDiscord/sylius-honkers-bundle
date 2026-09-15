<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Widget;

use FluffyDiscord\SyliusChatbotBundle\Channel\SiteKeyResolver;
use FluffyDiscord\SyliusChatbotBundle\Twig\ChatbotWidgetExtension;
use FluffyDiscord\SyliusChatbotBundle\Twig\ChatbotWidgetRuntime;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

class WidgetTemplateTest extends TestCase
{
    public function testFallsBackToBackendServedScriptWhenCdnUrlIsEmpty(): void
    {
        $html = $this->renderWidget('');

        self::assertStringContainsString('src="https://chat.example.com/widget/v1/chat.js"', $html);
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

        $channelContext = $this->createStub(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willThrowException(new ChannelNotFoundException());
        $siteKeyResolver = new SiteKeyResolver($this->createStub(ChannelRepositoryInterface::class), 'pk_test', []);
        $runtime = new ChatbotWidgetRuntime($channelContext, $siteKeyResolver);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            ChatbotWidgetRuntime::class => fn (): ChatbotWidgetRuntime => $runtime,
        ]));

        return $twig->render('shop/widget.html.twig', [
            'app' => ['locale' => 'cs_CZ'],
            'backend_url' => 'https://chat.example.com',
            'site_key' => 'pk_test',
            'widget_cdn_url' => $widgetCdnUrl,
        ]);
    }
}
