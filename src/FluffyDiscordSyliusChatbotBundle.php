<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle;

use MonsieurBiz\SyliusCmsPagePlugin\Entity\Page;
use Sylius\Bundle\UiBundle\Registry\TemplateBlock;
use FluffyDiscord\SyliusChatbotBundle\DataSource\CmsPagesDataSource;
use FluffyDiscord\SyliusChatbotBundle\DependencyInjection\Compiler\ChatbotDefinitionNamePass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class FluffyDiscordSyliusChatbotBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ChatbotDefinitionNamePass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('api_secret')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('backend_url')->defaultValue('')->end()
                ->scalarNode('ingest_secret')->defaultValue('')->end()
                ->arrayNode('widget')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('backend_url')
                            ->defaultValue('')
                            ->setDeprecated(
                                'fluffydiscord/sylius-chatbot-bundle',
                                '0.2',
                                'The "%path%.%node%" option is deprecated, configure "fluffy_discord_sylius_chatbot.backend_url" instead.',
                            )
                        ->end()
                        ->scalarNode('site_key')->defaultValue('')->end()
                        ->arrayNode('channel_site_keys')
                            ->useAttributeAsKey('channel')
                            ->normalizeKeys(false)
                            ->validate()
                                ->ifTrue(fn (array $channelSiteKeys): bool => $this->hasEmptyChannelCode($channelSiteKeys))
                                ->thenInvalid('Every channel site key must be keyed by a non-empty channel code, got %s.')
                            ->end()
                            ->scalarPrototype()
                                ->validate()
                                    ->ifTrue(fn (mixed $siteKey): bool => !is_string($siteKey))
                                    ->thenInvalid('Every channel site key must be a string, got %s.')
                                ->end()
                            ->end()
                        ->end()
                        ->scalarNode('cdn_url')->defaultValue('')->end()
                    ->end()
                ->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $rawConfig = $this->mergeRawConfig($container);
        if ($rawConfig['widget']['enabled'] === false) {
            return;
        }

        $backendUrl = $this->resolveBackendUrl($rawConfig);
        $widgetContext = [
            'backend_url' => $backendUrl,
            'site_key' => $rawConfig['widget']['site_key'],
            'widget_cdn_url' => $this->resolveWidgetCdnUrl($rawConfig, $backendUrl),
        ];

        $hasTwigHooks = $container->hasExtension('sylius_twig_hooks');
        if ($hasTwigHooks) {
            $this->prependWidgetHook($container, $widgetContext);

            return;
        }

        $hasTemplateEvents = $this->hasTemplateEvents($container);
        if ($hasTemplateEvents) {
            $this->prependWidgetTemplateBlock($container, $widgetContext);
        }
    }

    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $backendUrl = $this->resolveBackendUrl($config);

        $configurator->parameters()
            ->set('fluffydiscord_sylius_chatbot.api_secret', $config['api_secret'])
            ->set('fluffydiscord_sylius_chatbot.backend_url', $backendUrl)
            ->set('fluffydiscord_sylius_chatbot.ingest_secret', $config['ingest_secret'])
            ->set('fluffydiscord_sylius_chatbot.widget.enabled', $config['widget']['enabled'])
            ->set('fluffydiscord_sylius_chatbot.widget.backend_url', $backendUrl)
            ->set('fluffydiscord_sylius_chatbot.widget.site_key', $config['widget']['site_key'])
            ->set('fluffydiscord_sylius_chatbot.widget.channel_site_keys', $config['widget']['channel_site_keys']);

        $configurator->import(__DIR__ . '/../config/services.php');

        $isCmsPagePluginInstalled = class_exists(Page::class);
        if ($isCmsPagePluginInstalled) {
            $configurator->services()
                ->set(CmsPagesDataSource::class)
                ->autowire()
                ->autoconfigure()
                ->arg('$pageRepository', service('monsieurbiz_cms_page.repository.page'));
        }
    }

    /**
     * @param array<string, string> $widgetContext
     */
    private function prependWidgetHook(ContainerBuilder $container, array $widgetContext): void
    {
        $container->prependExtensionConfig('sylius_twig_hooks', [
            'hooks' => [
                'sylius_shop.base#javascripts' => [
                    'fluffydiscord_chatbot_widget' => [
                        'template' => $this->getWidgetTemplate(),
                        'priority' => 0,
                        'context' => $widgetContext,
                    ],
                ],
            ],
        ]);
    }

    private function hasTemplateEvents(ContainerBuilder $container): bool
    {
        $hasUiExtension = $container->hasExtension('sylius_ui');

        if (!$hasUiExtension) {
            return false;
        }

        return class_exists(TemplateBlock::class);
    }

    /**
     * @param array<string, string> $widgetContext
     */
    private function prependWidgetTemplateBlock(ContainerBuilder $container, array $widgetContext): void
    {
        $container->prependExtensionConfig('sylius_ui', [
            'events' => [
                'sylius.shop.layout.javascripts' => [
                    'blocks' => [
                        'fluffydiscord_chatbot_widget' => [
                            'template' => $this->getWidgetTemplate(),
                            'priority' => 0,
                            'context' => $widgetContext,
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<array-key, mixed> $channelSiteKeys
     */
    private function hasEmptyChannelCode(array $channelSiteKeys): bool
    {
        $channelCodes = array_map(strval(...), array_keys($channelSiteKeys));

        return in_array('', $channelCodes, true);
    }

    private function getWidgetTemplate(): string
    {
        return '@FluffyDiscordSyliusChatbot/shop/widget.html.twig';
    }

    private function getConfigAlias(): string
    {
        $extension = $this->getContainerExtension();

        return (string) $extension?->getAlias();
    }

    private function resolveBackendUrl(array $config): string
    {
        $rootBackendUrl = (string) ($config['backend_url'] ?? '');
        if ($rootBackendUrl !== '') {
            return $rootBackendUrl;
        }

        return (string) ($config['widget']['backend_url'] ?? '');
    }

    private function resolveWidgetCdnUrl(array $config, string $backendUrl): string
    {
        $cdnUrl = (string) ($config['widget']['cdn_url'] ?? '');
        if ($cdnUrl !== '') {
            return $cdnUrl;
        }

        return $backendUrl . '/widget/v1/chat.js';
    }

    private function mergeRawConfig(ContainerBuilder $container): array
    {
        $mergedConfig = [
            'backend_url' => '',
            'widget' => [
                'enabled' => true,
                'backend_url' => '',
                'site_key' => '',
                'cdn_url' => '',
            ],
        ];

        foreach ($container->getExtensionConfig($this->getConfigAlias()) as $rawConfig) {
            if (isset($rawConfig['backend_url'])) {
                $mergedConfig['backend_url'] = $rawConfig['backend_url'];
            }
            foreach (['enabled', 'backend_url', 'site_key', 'cdn_url'] as $key) {
                if (isset($rawConfig['widget'][$key])) {
                    $mergedConfig['widget'][$key] = $rawConfig['widget'][$key];
                }
            }
        }

        return $mergedConfig;
    }
}
