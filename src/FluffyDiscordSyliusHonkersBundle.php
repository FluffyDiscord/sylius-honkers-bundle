<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle;

use FluffyDiscord\SyliusHonkersBundle\DataSource\CmsPagesDataSource;
use MonsieurBiz\SyliusCmsPagePlugin\Entity\Page;
use Sylius\Bundle\UiBundle\Registry\TemplateBlock;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class FluffyDiscordSyliusHonkersBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
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
            ->end();
    }

    public function prependExtension(ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $honkersConfig = $this->mergeHonkersConfig($container);
        if ($honkersConfig['widget']['enabled'] === false) {
            return;
        }

        $backendUrl = (string) $honkersConfig['backend_url'];
        $widgetContext = [
            'backend_url' => $backendUrl,
            'site_key' => (string) $honkersConfig['widget']['site_key'],
            'widget_cdn_url' => $this->resolveWidgetCdnUrl($honkersConfig, $backendUrl),
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
        $configurator->parameters()
            ->set('fluffydiscord_sylius_honkers.channel_site_keys', $config['channel_site_keys']);

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
        return '@FluffyDiscordSyliusHonkers/shop/widget.html.twig';
    }

    private function getHonkersConfigAlias(): string
    {
        return 'fluffy_discord_honkers';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveWidgetCdnUrl(array $config, string $backendUrl): string
    {
        $cdnUrl = (string) ($config['widget']['cdn_url'] ?? '');
        if ($cdnUrl !== '') {
            return $cdnUrl;
        }

        return $backendUrl . '/widget/v1/chat.js';
    }

    /**
     * @return array{backend_url: string, widget: array{enabled: bool, site_key: string, cdn_url: string}}
     */
    private function mergeHonkersConfig(ContainerBuilder $container): array
    {
        $mergedConfig = [
            'backend_url' => '',
            'widget' => [
                'enabled' => true,
                'site_key' => '',
                'cdn_url' => '',
            ],
        ];

        foreach ($container->getExtensionConfig($this->getHonkersConfigAlias()) as $rawConfig) {
            if (isset($rawConfig['backend_url'])) {
                $mergedConfig['backend_url'] = $rawConfig['backend_url'];
            }
            foreach (['enabled', 'site_key', 'cdn_url'] as $key) {
                if (isset($rawConfig['widget'][$key])) {
                    $mergedConfig['widget'][$key] = $rawConfig['widget'][$key];
                }
            }
        }

        return $mergedConfig;
    }
}
