<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Channel;

use FluffyDiscord\SyliusHonkersBundle\Channel\ChannelUrlGenerator;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

class ChannelUrlGeneratorTest extends TestCase
{
    private function createRouter(RequestContext $context): RouterInterface
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('getContext')->willReturn($context);
        $router->method('generate')->willReturnCallback(
            static fn (): string => sprintf('%s://%s/zazitky/ostrava', $context->getScheme(), $context->getHost()),
        );

        return $router;
    }

    private function createChannel(?string $hostname): ChannelInterface
    {
        $channel = $this->createStub(ChannelInterface::class);
        $channel->method('getHostname')->willReturn($hostname);

        return $channel;
    }

    private function createContext(): RequestContext
    {
        $context = new RequestContext();
        $context->setScheme('https');
        $context->setHost('request.example');

        return $context;
    }

    public function testTheUrlCarriesTheChannelHostnameInsteadOfTheRequestHost(): void
    {
        $context = $this->createContext();
        $generator = new ChannelUrlGenerator($this->createRouter($context));

        $url = $generator->generate($this->createChannel('other-channel.example'), 'a_route', []);

        self::assertSame('https://other-channel.example/zazitky/ostrava', $url);
    }

    public function testTheRequestHostIsRestoredAfterGenerating(): void
    {
        $context = $this->createContext();
        $generator = new ChannelUrlGenerator($this->createRouter($context));

        $generator->generate($this->createChannel('other-channel.example'), 'a_route', []);

        self::assertSame('request.example', $context->getHost());
    }

    public function testAChannelWithoutAHostnameFallsBackToTheRequestHost(): void
    {
        $context = $this->createContext();
        $generator = new ChannelUrlGenerator($this->createRouter($context));

        $url = $generator->generate($this->createChannel(null), 'a_route', []);

        self::assertSame('https://request.example/zazitky/ostrava', $url);
    }

    public function testAnArbitraryUrlBuilderAlsoRunsOnTheChannelHost(): void
    {
        $context = $this->createContext();
        $generator = new ChannelUrlGenerator($this->createRouter($context));

        $url = $generator->runOnChannelHost(
            $this->createChannel('other-channel.example'),
            static fn (): string => sprintf('%s://%s/media/cache/a.jpg', $context->getScheme(), $context->getHost()),
        );

        self::assertSame('https://other-channel.example/media/cache/a.jpg', $url);
        self::assertSame('request.example', $context->getHost());
    }

    public function testAHostnameTypedAsAUrlIsReducedToItsHost(): void
    {
        $context = $this->createContext();
        $generator = new ChannelUrlGenerator($this->createRouter($context));

        $url = $generator->generate($this->createChannel('https://other-channel.example/cs/'), 'a_route', []);

        self::assertSame('https://other-channel.example/zazitky/ostrava', $url);
    }

    public function testAHostnameThatReducesToNothingFallsBackToTheRequestHost(): void
    {
        $context = $this->createContext();
        $generator = new ChannelUrlGenerator($this->createRouter($context));

        $url = $generator->generate($this->createChannel('   '), 'a_route', []);

        self::assertSame('https://request.example/zazitky/ostrava', $url);
    }

    public function testTheRequestHostIsRestoredWhenTheUrlBuilderThrows(): void
    {
        $context = $this->createContext();
        $generator = new ChannelUrlGenerator($this->createRouter($context));

        try {
            $generator->runOnChannelHost(
                $this->createChannel('other-channel.example'),
                static fn (): string => throw new \RuntimeException('the image could not be resolved'),
            );
            self::fail('The exception should have propagated.');
        } catch (\RuntimeException) {
        }

        self::assertSame('request.example', $context->getHost());
    }
}
