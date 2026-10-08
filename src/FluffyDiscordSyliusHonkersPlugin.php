<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin;

use BitBag\SyliusCmsPlugin\Entity\Page as BitBagPage;
use FluffyDiscord\SyliusHonkersPlugin\DataSource\BitBagCmsPagesDataSource;
use FluffyDiscord\SyliusHonkersPlugin\DataSource\CmsPagesDataSource;
use FluffyDiscord\SyliusHonkersPlugin\DependencyInjection\Compiler\SyliusContextAliasPass;
use MonsieurBiz\SyliusCmsPagePlugin\Entity\Page;
use Sylius\Bundle\UiBundle\Registry\TemplateBlock;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class FluffyDiscordSyliusHonkersPlugin extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new SyliusContextAliasPass());
    }

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
        $this->prependFromChat($container);
        $this->prependWidget($container);
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

        $isBitBagCmsPluginInstalled = class_exists(BitBagPage::class);
        if ($isBitBagCmsPluginInstalled) {
            $configurator->services()
                ->set(BitBagCmsPagesDataSource::class)
                ->autowire()
                ->autoconfigure()
                ->arg('$pageRepository', service('bitbag_sylius_cms_plugin.repository.page'));
        }
    }

    private function prependFromChat(ContainerBuilder $container): void
    {
        $hasTwigHooks = $container->hasExtension('sylius_twig_hooks');
        if ($hasTwigHooks) {
            $this->prependFromChatHook($container);

            return;
        }

        $hasTemplateEvents = $this->hasTemplateEvents($container);
        if ($hasTemplateEvents) {
            $this->prependFromChatTemplateBlock($container);
        }
    }

    private function prependFromChatHook(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('sylius_twig_hooks', [
            'hooks' => [
                'sylius_admin.order.show.content.sections#right' => [
                    'fluffydiscord_chatbot_from_chat' => [
                        'template' => '@FluffyDiscordSyliusHonkersPlugin/admin/order/show/from_chat.html.twig',
                        'priority' => -100,
                    ],
                ],
            ],
        ]);
    }

    private function prependFromChatTemplateBlock(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('sylius_ui', [
            'events' => [
                'sylius.admin.order.show.sidebar' => [
                    'blocks' => [
                        'fluffydiscord_chatbot_from_chat' => [
                            'template' => '@FluffyDiscordSyliusHonkersPlugin/admin/order/from_chat.html.twig',
                            'priority' => -100,
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function prependWidget(ContainerBuilder $container): void
    {
        $honkersConfig = $this->mergeHonkersConfig($container);
        if ($honkersConfig['widget']['enabled'] === false) {
            return;
        }

        $widgetContext = [
            'backend_url' => (string) $honkersConfig['backend_url'],
            'widget_cdn_url' => (string) $honkersConfig['widget']['cdn_url'],
            'defer' => (bool) $honkersConfig['widget']['defer'],
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

    /**
     * @param array<string, string|bool> $widgetContext
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
     * @param array<string, string|bool> $widgetContext
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
        return '@FluffyDiscordSyliusHonkersPlugin/shop/widget.html.twig';
    }

    private function getHonkersConfigAlias(): string
    {
        return 'fluffy_discord_honkers';
    }

    /**
     * @return array{backend_url: string, widget: array{enabled: bool, cdn_url: string, defer: bool}}
     */
    private function mergeHonkersConfig(ContainerBuilder $container): array
    {
        $mergedConfig = [
            'backend_url' => '',
            'widget' => [
                'enabled' => true,
                'cdn_url' => '',
                'defer' => true,
            ],
        ];

        foreach ($container->getExtensionConfig($this->getHonkersConfigAlias()) as $rawConfig) {
            if (isset($rawConfig['backend_url'])) {
                $mergedConfig['backend_url'] = $rawConfig['backend_url'];
            }
            foreach (['enabled', 'cdn_url', 'defer'] as $key) {
                if (isset($rawConfig['widget'][$key])) {
                    $mergedConfig['widget'][$key] = $rawConfig['widget'][$key];
                }
            }
        }

        return $mergedConfig;
    }
}
