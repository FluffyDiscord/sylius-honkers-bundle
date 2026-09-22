<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Twig;

use FluffyDiscord\SyliusHonkersBundle\Channel\SiteKeyResolver;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Twig\Extension\RuntimeExtensionInterface;

class ChatbotWidgetRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly ChannelContextInterface $channelContext,
        private readonly SiteKeyResolver         $siteKeyResolver,
    ) {
    }

    public function getSiteKey(?string $fallbackSiteKey): string
    {
        $fallback = (string) $fallbackSiteKey;

        $channelCode = $this->findChannelCode();
        if ($channelCode === null) {
            return $fallback;
        }

        return $this->siteKeyResolver->findChannelSiteKey($channelCode) ?? $fallback;
    }

    private function findChannelCode(): ?string
    {
        try {
            $channel = $this->channelContext->getChannel();
        } catch (ChannelNotFoundException) {
            return null;
        }

        return $channel->getCode();
    }
}
