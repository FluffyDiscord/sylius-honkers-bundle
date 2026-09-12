<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Channel;

use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

readonly class ChannelUrlGenerator
{
    public function __construct(
        private RouterInterface $router,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function generate(ChannelInterface $channel, string $route, array $parameters): string
    {
        return $this->runOnChannelHost(
            $channel,
            fn (): string => $this->router->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }

    /**
     * @param callable(): string $buildUrl
     */
    public function runOnChannelHost(ChannelInterface $channel, callable $buildUrl): string
    {
        $host = $this->readHost($channel);
        if ($host === null) {
            return $buildUrl();
        }

        $context = $this->router->getContext();
        $previousHost = $context->getHost();
        $context->setHost($host);

        try {
            return $buildUrl();
        } finally {
            $context->setHost($previousHost);
        }
    }

    private function readHost(ChannelInterface $channel): ?string
    {
        $hostname = $channel->getHostname();
        if ($hostname === null) {
            return null;
        }

        $withoutScheme = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', trim($hostname));
        if ($withoutScheme === '') {
            return null;
        }

        $host = parse_url('//' . ltrim($withoutScheme, '/'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
