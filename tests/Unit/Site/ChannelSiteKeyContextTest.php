<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Site;

use FluffyDiscord\SyliusHonkersBundle\Channel\SiteKeyResolver;
use FluffyDiscord\SyliusHonkersBundle\Site\ChannelSiteKeyContext;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\Channel;

class ChannelSiteKeyContextTest extends TestCase
{
    private function createSiteKeyResolver(): SiteKeyResolver
    {
        return new SiteKeyResolver($this->createStub(ChannelRepositoryInterface::class), 'default-key', ['CZ_WEB' => 'cz-key']);
    }

    public function testTheCurrentChannelSiteKeyIsUsed(): void
    {
        $channel = new Channel();
        $channel->setCode('CZ_WEB');
        $channelContext = $this->createStub(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($channel);

        $siteKeyContext = new ChannelSiteKeyContext($channelContext, $this->createSiteKeyResolver());

        self::assertSame('cz-key', $siteKeyContext->getSiteKey());
    }

    public function testAChannelWithoutItsOwnKeyFallsBackToTheDefault(): void
    {
        $channel = new Channel();
        $channel->setCode('DE_WEB');
        $channelContext = $this->createStub(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($channel);

        $siteKeyContext = new ChannelSiteKeyContext($channelContext, $this->createSiteKeyResolver());

        self::assertSame('default-key', $siteKeyContext->getSiteKey());
    }

    public function testNoCurrentChannelFallsBackToTheDefault(): void
    {
        $channelContext = $this->createStub(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willThrowException(new ChannelNotFoundException());

        $siteKeyContext = new ChannelSiteKeyContext($channelContext, $this->createSiteKeyResolver());

        self::assertSame('default-key', $siteKeyContext->getSiteKey());
    }
}
