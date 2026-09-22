<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit;

use FluffyDiscord\SyliusHonkersBundle\Channel\ChannelResolver;
use FluffyDiscord\SyliusHonkersBundle\Channel\SiteKeyResolver;
use FluffyDiscord\SyliusHonkersBundle\FluffyDiscordSyliusHonkersBundle;
use FluffyDiscord\SyliusHonkersBundle\Ingest\CatalogChangeNotifier;
use FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Fixtures\NamedExtension;
use FluffyDiscord\SyliusHonkersBundle\Twig\ChatbotWidgetExtension;
use FluffyDiscord\SyliusHonkersBundle\Twig\ChatbotWidgetRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Extension\ExtensionInterface;
use Twig\Extension\RuntimeExtensionInterface;

class FluffyDiscordSyliusHonkersBundleTest extends TestCase
{
    public function testTheWidgetIsRegisteredAsATwigHookWhenTheShopHasThem(): void
    {
        $container = $this->createContainer(['sylius_twig_hooks', 'sylius_ui']);

        $this->prependExtension($container);

        $hooks = $container->getExtensionConfig('sylius_twig_hooks');
        $widget = $hooks[0]['hooks']['sylius_shop.base#javascripts']['fluffydiscord_chatbot_widget'];

        self::assertSame('@FluffyDiscordSyliusHonkers/shop/widget.html.twig', $widget['template']);
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

        self::assertSame('@FluffyDiscordSyliusHonkers/shop/widget.html.twig', $widget['template']);
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
        $config = $this->processConfiguration([]);

        self::assertSame([], $config['widget']['channel_site_keys']);
    }

    /**
     * @param array<array-key, string> $channelSiteKeys
     */
    #[DataProvider('provideValidChannelSiteKeys')]
    public function testValidChannelSiteKeysAreKeptVerbatim(array $channelSiteKeys): void
    {
        $config = $this->processConfiguration([
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
        $extension = (new FluffyDiscordSyliusHonkersBundle())->getContainerExtension();

        $extension->load([[
            'widget' => ['site_key' => 'site-key', 'channel_site_keys' => ['CZ_WEB' => 'cz-key']],
        ]], $container);

        self::assertSame(['CZ_WEB' => 'cz-key'], $container->getParameter('fluffydiscord_honkers.widget.channel_site_keys'));
        self::assertSame('site-key', $container->getParameter('fluffydiscord_honkers.widget.site_key'));
    }

    public function testTheSiteKeyServicesWireInACompiledContainer(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->registerForAutoconfiguration(ExtensionInterface::class)->addTag('twig.extension');
        $container->registerForAutoconfiguration(RuntimeExtensionInterface::class)->addTag('twig.runtime');
        $this->registerShopServices($container);

        $bundle = new FluffyDiscordSyliusHonkersBundle();
        $bundle->build($container);
        $bundle->getContainerExtension()->load([[
            'backend_url' => 'https://backend.test',
            'ingest_secret' => 'ingest-secret',
            'widget' => [
                'site_key' => 'site-key',
                'channel_site_keys' => ['CZ_WEB' => 'cz-key', 'SK_WEB' => '%env(CHATBOT_TEST_SITE_KEY_SK)%'],
            ],
        ]], $container);
        $this->keepOnlyWiredBundleServices($container, $this->getWiredServiceIds());
        $_ENV['CHATBOT_TEST_SITE_KEY_SK'] = 'sk-key';

        try {
            $container->compile(resolveEnvPlaceholders: true);
        } finally {
            unset($_ENV['CHATBOT_TEST_SITE_KEY_SK']);
        }

        $this->setShopServices($container);
        $siteKeyResolver = $container->get(SiteKeyResolver::class);

        self::assertInstanceOf(SiteKeyResolver::class, $siteKeyResolver);
        self::assertSame('cz-key', $siteKeyResolver->getSiteKey('CZ_WEB'));
        self::assertSame('sk-key', $siteKeyResolver->getSiteKey('SK_WEB'));
        self::assertSame('site-key', $siteKeyResolver->getSiteKey('DE_WEB'));
        self::assertInstanceOf(CatalogChangeNotifier::class, $container->get(CatalogChangeNotifier::class));
        self::assertInstanceOf(ChatbotWidgetRuntime::class, $container->get(ChatbotWidgetRuntime::class));
        self::assertTrue($container->getDefinition(ChatbotWidgetExtension::class)->hasTag('twig.extension'));
        self::assertTrue($container->getDefinition(ChatbotWidgetRuntime::class)->hasTag('twig.runtime'));
    }

    /**
     * @return list<class-string>
     */
    private function getWiredServiceIds(): array
    {
        return [
            SiteKeyResolver::class,
            CatalogChangeNotifier::class,
            ChannelResolver::class,
            ChatbotWidgetExtension::class,
            ChatbotWidgetRuntime::class,
        ];
    }

    /**
     * @param list<class-string> $wiredServiceIds
     */
    private function keepOnlyWiredBundleServices(ContainerBuilder $container, array $wiredServiceIds): void
    {
        foreach (array_keys($container->getDefinitions()) as $serviceId) {
            $isBundleService = str_starts_with($serviceId, 'FluffyDiscord\\SyliusHonkersBundle\\');
            if (!$isBundleService) {
                continue;
            }

            $isWired = in_array($serviceId, $wiredServiceIds, true);
            if ($isWired) {
                $container->getDefinition($serviceId)->setPublic(true);

                continue;
            }

            $container->removeDefinition($serviceId);
        }
    }

    /**
     * @return array<string, class-string>
     */
    private function getShopServiceClasses(): array
    {
        return [
            HttpClientInterface::class => HttpClientInterface::class,
            LoggerInterface::class => LoggerInterface::class,
            ChannelRepositoryInterface::class => ChannelRepositoryInterface::class,
            ChannelContextInterface::class => ChannelContextInterface::class,
        ];
    }

    private function registerShopServices(ContainerBuilder $container): void
    {
        foreach ($this->getShopServiceClasses() as $serviceId => $serviceClass) {
            $container->register($serviceId, $serviceClass)->setSynthetic(true)->setPublic(true);
        }
    }

    private function setShopServices(ContainerBuilder $container): void
    {
        foreach ($this->getShopServiceClasses() as $serviceId => $serviceClass) {
            $container->set($serviceId, $this->createStub($serviceClass));
        }
    }

    /**
     * @param array<string, mixed> $rawConfig
     *
     * @return array<string, mixed>
     */
    private function processConfiguration(array $rawConfig): array
    {
        $container = new ContainerBuilder();
        $extension = (new FluffyDiscordSyliusHonkersBundle())->getContainerExtension();
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

        $container->prependExtensionConfig('fluffy_discord_sylius_honkers', [
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

        (new FluffyDiscordSyliusHonkersBundle())->prependExtension($configurator, $container);
    }
}
