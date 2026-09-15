<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Tests\Unit;

use FluffyDiscord\SyliusChatbotBundle\FluffyDiscordSyliusChatbotBundle;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\NamedExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class FluffyDiscordSyliusChatbotBundleTest extends TestCase
{
    public function testTheWidgetIsRegisteredAsATwigHookWhenTheShopHasThem(): void
    {
        $container = $this->createContainer(['sylius_twig_hooks', 'sylius_ui']);

        $this->prependExtension($container);

        $hooks = $container->getExtensionConfig('sylius_twig_hooks');
        $widget = $hooks[0]['hooks']['sylius_shop.base#javascripts']['fluffydiscord_chatbot_widget'];

        self::assertSame('@FluffyDiscordSyliusChatbot/shop/widget.html.twig', $widget['template']);
        self::assertSame($this->getExpectedWidgetContext(), $widget['context']);
        self::assertSame([], $container->getExtensionConfig('sylius_ui'));
    }

    public function testTheWidgetIsRegisteredAsATemplateBlockOnSyliusWithoutTwigHooks(): void
    {
        require_once __DIR__ . '/Fixtures/sylius_1_template_block.php';

        $container = $this->createContainer(['sylius_ui']);

        $this->prependExtension($container);

        $events = $container->getExtensionConfig('sylius_ui');
        $widget = $events[0]['events']['sylius.shop.layout.javascripts']['blocks']['fluffydiscord_chatbot_widget'];

        self::assertSame('@FluffyDiscordSyliusChatbot/shop/widget.html.twig', $widget['template']);
        self::assertSame($this->getExpectedWidgetContext(), $widget['context']);
        self::assertSame([], $container->getExtensionConfig('sylius_twig_hooks'));
    }

    public function testADisabledWidgetIsRegisteredNowhere(): void
    {
        $container = $this->createContainer(['sylius_ui'], ['enabled' => false]);

        $this->prependExtension($container);

        self::assertSame([], $container->getExtensionConfig('sylius_ui'));
        self::assertSame([], $container->getExtensionConfig('sylius_twig_hooks'));
    }

    public function testTheChannelSiteKeysDefaultToNone(): void
    {
        $config = $this->processConfiguration(['api_secret' => 'secret']);

        self::assertSame([], $config['widget']['channel_site_keys']);
    }

    /**
     * @param array<array-key, string> $channelSiteKeys
     */
    #[DataProvider('provideValidChannelSiteKeys')]
    public function testValidChannelSiteKeysAreKeptVerbatim(array $channelSiteKeys): void
    {
        $config = $this->processConfiguration([
            'api_secret' => 'secret',
            'widget' => ['channel_site_keys' => $channelSiteKeys],
        ]);

        self::assertSame($channelSiteKeys, $config['widget']['channel_site_keys']);
    }

    /**
     * @return iterable<string, array{array<array-key, string>}>
     */
    public static function provideValidChannelSiteKeys(): iterable
    {
        yield 'literal keys' => [['CZ_WEB' => 'cz-key', 'SK_WEB' => 'sk-key']];
        yield 'env placeholders' => [['CZ_WEB' => '%env(CHATBOT_SITE_KEY_CZ)%']];
        yield 'hyphenated channel codes are not normalized' => [['cz-web' => 'cz-key']];
        yield 'numeric channel codes' => [[123 => 'numeric-key']];
        yield 'empty site key' => [['CZ_WEB' => '']];
    }

    /**
     * @param array<array-key, mixed> $channelSiteKeys
     */
    #[DataProvider('provideInvalidChannelSiteKeys')]
    public function testInvalidChannelSiteKeysAreRejected(array $channelSiteKeys): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processConfiguration([
            'api_secret' => 'secret',
            'widget' => ['channel_site_keys' => $channelSiteKeys],
        ]);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function provideInvalidChannelSiteKeys(): iterable
    {
        yield 'integer site key' => [['CZ_WEB' => 123]];
        yield 'boolean site key' => [['CZ_WEB' => true]];
        yield 'null site key' => [['CZ_WEB' => null]];
        yield 'nested site key' => [['CZ_WEB' => ['cz-key']]];
        yield 'empty channel code' => [['' => 'cz-key']];
    }

    public function testTheChannelSiteKeysBecomeAContainerParameter(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $extension = (new FluffyDiscordSyliusChatbotBundle())->getContainerExtension();

        $extension->load([[
            'api_secret' => 'secret',
            'widget' => ['site_key' => 'site-key', 'channel_site_keys' => ['CZ_WEB' => 'cz-key']],
        ]], $container);

        self::assertSame(['CZ_WEB' => 'cz-key'], $container->getParameter('fluffydiscord_sylius_chatbot.widget.channel_site_keys'));
        self::assertSame('site-key', $container->getParameter('fluffydiscord_sylius_chatbot.widget.site_key'));
    }

    /**
     * @param array<string, mixed> $rawConfig
     *
     * @return array<string, mixed>
     */
    private function processConfiguration(array $rawConfig): array
    {
        $container = new ContainerBuilder();
        $extension = (new FluffyDiscordSyliusChatbotBundle())->getContainerExtension();
        $configuration = $extension->getConfiguration([], $container);

        return (new Processor())->processConfiguration($configuration, [$rawConfig]);
    }

    /**
     * @return array<string, string>
     */
    private function getExpectedWidgetContext(): array
    {
        return [
            'backend_url' => 'https://backend.test',
            'site_key' => 'site-key',
            'widget_cdn_url' => 'https://backend.test/widget/v1/chat.js',
        ];
    }

    /**
     * @param list<string>         $extensionAliases
     * @param array<string, mixed> $widgetConfig
     */
    private function createContainer(array $extensionAliases, array $widgetConfig = []): ContainerBuilder
    {
        $container = new ContainerBuilder();

        foreach ($extensionAliases as $alias) {
            $container->registerExtension(new NamedExtension($alias));
        }

        $container->prependExtensionConfig('fluffy_discord_sylius_chatbot', [
            'backend_url' => 'https://backend.test',
            'widget' => $widgetConfig + ['site_key' => 'site-key'],
        ]);

        return $container;
    }

    private function prependExtension(ContainerBuilder $container): void
    {
        $instanceof = [];
        $configurator = new ContainerConfigurator(
            $container,
            new PhpFileLoader($container, new FileLocator(__DIR__)),
            $instanceof,
            __FILE__,
            __FILE__,
        );

        (new FluffyDiscordSyliusChatbotBundle())->prependExtension($configurator, $container);
    }
}
