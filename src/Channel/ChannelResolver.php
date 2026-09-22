<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Channel;

use FluffyDiscord\SyliusHonkersBundle\Exception\InvalidChannelException;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Contracts\Service\ResetInterface;

class ChannelResolver implements ResetInterface
{
    private ?string $overrideCode = null;

    public function __construct(
        private readonly ChannelContextInterface $channelContext,
        private readonly ChannelRepositoryInterface $channelRepository,
    ) {
    }

    public function setOverrideCode(?string $channelCode): void
    {
        $this->overrideCode = $channelCode;
    }

    public function getChannel(): ChannelInterface
    {
        return $this->resolve($this->overrideCode);
    }

    public function resolve(?string $channelCode): ChannelInterface
    {
        if ($channelCode === null || $channelCode === '') {
            return $this->resolveContextChannel();
        }

        $channel = $this->channelRepository->findOneByCode($channelCode);
        if (!$channel instanceof ChannelInterface) {
            throw new InvalidChannelException($channelCode);
        }

        return $channel;
    }

    public function reset(): void
    {
        $this->overrideCode = null;
    }

    private function resolveContextChannel(): ChannelInterface
    {
        try {
            $channel = $this->channelContext->getChannel();
        } catch (ChannelNotFoundException $exception) {
            throw new InvalidChannelException(null, $exception);
        }

        if (!$channel instanceof ChannelInterface) {
            throw new InvalidChannelException($channel->getCode());
        }

        return $channel;
    }
}
