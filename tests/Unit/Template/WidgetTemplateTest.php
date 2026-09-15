<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Template;

use FluffyDiscord\SyliusChatbotBundle\Channel\SiteKeyResolver;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\AppVariableDouble;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\ChannelFixtureFactory;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\HookableMetadataDouble;
use FluffyDiscord\SyliusChatbotBundle\Twig\ChatbotWidgetExtension;
use FluffyDiscord\SyliusChatbotBundle\Twig\ChatbotWidgetRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
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

    /**
     * @param array<string, string> $channelSiteKeys
     */
    #[DataProvider('provideChannelSiteKeys')]
    public function testTheWidgetCarriesTheSiteKeyOfTheCurrentChannel(
        ?string $currentChannelCode,
        array $channelSiteKeys,
        string $expectedSiteKey,
    ): void {
        $expectedMarkup = $this->getExpectedMarkup($expectedSiteKey);

        $renderedFromHook = $this->render(
            ['hookable_metadata' => new HookableMetadataDouble($this->getWidgetContext())],
            $currentChannelCode,
            $channelSiteKeys,
        );
        $renderedFromTemplateBlock = $this->render($this->getWidgetContext(), $currentChannelCode, $channelSiteKeys);
        $renderedFromDirectInclude = $this->renderDirectInclude($currentChannelCode, $channelSiteKeys);

        self::assertSame($expectedMarkup, $renderedFromHook);
        self::assertSame($expectedMarkup, $renderedFromTemplateBlock);
        self::assertSame($expectedMarkup, $renderedFromDirectInclude);
    }

    /**
     * @return iterable<string, array{?string, array<string, string>, string}>
     */
    public static function provideChannelSiteKeys(): iterable
    {
        $channelSiteKeys = ['CZ' => 'cz-key', 'SK' => 'sk-key'];

        yield 'mapped channel' => ['SK', $channelSiteKeys, 'sk-key'];
        yield 'another mapped channel' => ['CZ', $channelSiteKeys, 'cz-key'];
        yield 'unmapped channel falls back to the context key' => ['DE', $channelSiteKeys, 'site-key'];
        yield 'no channel keys' => ['SK', [], 'site-key'];
        yield 'no resolvable channel falls back to the context key' => [null, $channelSiteKeys, 'site-key'];
        yield 'channel mapped to an empty key renders nothing' => ['SK', ['CZ' => 'cz-key', 'SK' => ''], ''];
    }

    /**
     * @return array<string, string>
     */
    private function getWidgetContext(): array
    {
        return [
            'backend_url' => 'https://backend.test',
            'site_key' => 'site-key',
            'widget_cdn_url' => 'https://cdn.test/chat.js',
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
     * @param array<string, mixed>  $context
     * @param array<string, string> $channelSiteKeys
     */
    private function render(array $context, ?string $currentChannelCode = 'CZ', array $channelSiteKeys = []): string
    {
        $twig = $this->createEnvironment(new FilesystemLoader(__DIR__ . '/../../../templates'), $currentChannelCode, $channelSiteKeys);

        return trim($twig->render('shop/widget.html.twig', $context));
    }

    /**
     * @param array<string, string> $channelSiteKeys
     */
    private function renderDirectInclude(?string $currentChannelCode, array $channelSiteKeys): string
    {
        $shopLayoutLoader = new ArrayLoader([
            'shop_layout.html.twig' => "{% include 'shop/widget.html.twig' with chatbot_widget only %}",
        ]);
        $loader = new ChainLoader([$shopLayoutLoader, new FilesystemLoader(__DIR__ . '/../../../templates')]);
        $twig = $this->createEnvironment($loader, $currentChannelCode, $channelSiteKeys);

        return trim($twig->render('shop_layout.html.twig', ['chatbot_widget' => $this->getWidgetContext()]));
    }

    /**
     * @param array<string, string> $channelSiteKeys
     */
    private function createEnvironment(
        LoaderInterface $loader,
        ?string $currentChannelCode,
        array $channelSiteKeys,
    ): Environment {
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addGlobal('app', new AppVariableDouble('cs_CZ'));
        $twig->addExtension(new ChatbotWidgetExtension());

        $runtime = $this->createRuntime($currentChannelCode, $channelSiteKeys);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            ChatbotWidgetRuntime::class => fn (): ChatbotWidgetRuntime => $runtime,
        ]));

        return $twig;
    }

    /**
     * @param array<string, string> $channelSiteKeys
     */
    private function createRuntime(?string $currentChannelCode, array $channelSiteKeys): ChatbotWidgetRuntime
    {
        $channelContext = $this->createStub(ChannelContextInterface::class);
        if ($currentChannelCode === null) {
            $channelContext->method('getChannel')->willThrowException(new ChannelNotFoundException());
        } else {
            $channelContext->method('getChannel')->willReturn((new ChannelFixtureFactory())->createChannel($currentChannelCode, ['cs_CZ']));
        }

        $siteKeyResolver = new SiteKeyResolver(
            $this->createStub(ChannelRepositoryInterface::class),
            'config-default-key',
            $channelSiteKeys,
        );

        return new ChatbotWidgetRuntime($channelContext, $siteKeyResolver);
    }
}
