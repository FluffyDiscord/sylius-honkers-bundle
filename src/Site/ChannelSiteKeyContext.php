<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Site;

use FluffyDiscord\HonkersBundle\Contract\SiteKeyContextInterface;
use FluffyDiscord\SyliusHonkersBundle\Channel\SiteKeyResolver;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;

class ChannelSiteKeyContext implements SiteKeyContextInterface
{
    public function __construct(
        private readonly ChannelContextInterface $channelContext,
        private readonly SiteKeyResolver         $siteKeyResolver,
    ) {
    }

    public function getSiteKey(): string
    {
        try {
            $channel = $this->channelContext->getChannel();
        } catch (ChannelNotFoundException) {
            return $this->siteKeyResolver->getDefaultSiteKey();
        }

        $channelCode = (string) $channel->getCode();

        return $this->siteKeyResolver->getSiteKey($channelCode);
    }
}
