<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\DependencyInjection\Compiler;

use FluffyDiscord\Honkers\Contract\ChatbotLocaleContextInterface;
use FluffyDiscord\HonkersBundle\Contract\SiteKeyContextInterface;
use FluffyDiscord\SyliusHonkersBundle\Locale\SyliusLocaleContext;
use FluffyDiscord\SyliusHonkersBundle\Site\ChannelSiteKeyContext;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class SyliusContextAliasPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->setAlias(ChatbotLocaleContextInterface::class, SyliusLocaleContext::class);
        $container->setAlias(SiteKeyContextInterface::class, ChannelSiteKeyContext::class);
    }
}
