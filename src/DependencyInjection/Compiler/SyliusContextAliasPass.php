<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\DependencyInjection\Compiler;

use FluffyDiscord\Honkers\Contract\ChatbotLocaleContextInterface;
use FluffyDiscord\HonkersBundle\Contract\CredentialsProviderInterface;
use FluffyDiscord\HonkersBundle\Contract\CredentialsWriterInterface;
use FluffyDiscord\HonkersBundle\Credentials\EnvCredentialsProvider;
use FluffyDiscord\SyliusHonkersPlugin\Credentials\ChannelCredentialsProviderInterface;
use FluffyDiscord\SyliusHonkersPlugin\Credentials\HonkersChannelInterface;
use FluffyDiscord\SyliusHonkersPlugin\Credentials\SyliusCredentialsProvider;
use FluffyDiscord\SyliusHonkersPlugin\Locale\SyliusLocaleContext;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class SyliusContextAliasPass implements CompilerPassInterface
{
    private const CHANNEL_CLASS_PARAMETER = 'sylius.model.channel.class';

    private const BUNDLE_PROVIDER_SERVICE_IDS = [EnvCredentialsProvider::class, SyliusCredentialsProvider::class];

    public function process(ContainerBuilder $container): void
    {
        $container->setAlias(ChatbotLocaleContextInterface::class, SyliusLocaleContext::class);

        $providerServiceId = $this->getChannelProviderServiceId($container);
        $container->setAlias(ChannelCredentialsProviderInterface::class, $providerServiceId);
        $container->setAlias(CredentialsProviderInterface::class, $providerServiceId);

        $isSyliusProvider = $providerServiceId === SyliusCredentialsProvider::class;
        if (!$isSyliusProvider) {
            return;
        }

        $hasWriter = $container->has(CredentialsWriterInterface::class);
        if ($hasWriter) {
            return;
        }

        $canChannelStoreCredentials = $this->canChannelStoreCredentials($container);
        if ($canChannelStoreCredentials) {
            $container->setAlias(CredentialsWriterInterface::class, SyliusCredentialsProvider::class);
        }
    }

    private function getChannelProviderServiceId(ContainerBuilder $container): string
    {
        $hasAppChannelProvider = $container->hasAlias(ChannelCredentialsProviderInterface::class);
        if ($hasAppChannelProvider) {
            return (string) $container->getAlias(ChannelCredentialsProviderInterface::class);
        }

        $hasAppProvider = $this->hasAppCredentialsProvider($container);
        if ($hasAppProvider) {
            throw new \LogicException(sprintf(
                'The app aliases "%s" without aliasing "%s". Sylius resolves credentials per channel: alias "%s" to your provider instead.',
                CredentialsProviderInterface::class,
                ChannelCredentialsProviderInterface::class,
                ChannelCredentialsProviderInterface::class,
            ));
        }

        return SyliusCredentialsProvider::class;
    }

    private function hasAppCredentialsProvider(ContainerBuilder $container): bool
    {
        $hasProvider = $container->hasAlias(CredentialsProviderInterface::class);
        if (!$hasProvider) {
            return false;
        }

        $providerServiceId = (string) $container->getAlias(CredentialsProviderInterface::class);
        $isBundleProvider = in_array($providerServiceId, self::BUNDLE_PROVIDER_SERVICE_IDS, true);

        return !$isBundleProvider;
    }

    private function canChannelStoreCredentials(ContainerBuilder $container): bool
    {
        $hasChannelClass = $container->hasParameter(self::CHANNEL_CLASS_PARAMETER);
        if (!$hasChannelClass) {
            return false;
        }

        $channelClass = $container->getParameter(self::CHANNEL_CLASS_PARAMETER);
        if (!is_string($channelClass)) {
            return false;
        }

        return is_a($channelClass, HonkersChannelInterface::class, true);
    }
}
