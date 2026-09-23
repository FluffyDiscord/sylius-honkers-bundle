<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Locale;

use Doctrine\Common\Collections\ArrayCollection;
use FluffyDiscord\Honkers\Locale\LocaleMatcher;
use FluffyDiscord\SyliusHonkersBundle\Channel\ChannelResolver;
use FluffyDiscord\SyliusHonkersBundle\Locale\SyliusLocaleContext;
use FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Fixtures\ChannelFixtureFactory;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Locale\Model\Locale;

class SyliusLocaleContextTest extends TestCase
{
    public function testChannelLocalesDropBlankCodes(): void
    {
        $context = $this->createContext(['cs_CZ', '']);

        self::assertSame(['cs_CZ'], $context->getChannelLocales());
    }

    public function testAllChannelLocalesUnionEveryEnabledChannel(): void
    {
        $context = $this->createContextOverChannels([
            'CZ_WEB' => ['cs_CZ', 'en_US'],
            'AT_WEB' => ['de_AT'],
        ]);

        self::assertSame(['cs_CZ', 'en_US', 'de_AT'], $context->getAllChannelLocales());
    }

    public function testAllChannelLocalesReportALocaleSharedByTwoChannelsOnce(): void
    {
        $context = $this->createContextOverChannels([
            'DE_WEB' => ['de_DE'],
            'AT_WEB' => ['de_DE'],
        ]);

        self::assertSame(['de_DE'], $context->getAllChannelLocales());
    }

    public function testAllChannelLocalesDropBlankCodes(): void
    {
        $context = $this->createContextOverChannels(['CZ_WEB' => ['cs_CZ', '']]);

        self::assertSame(['cs_CZ'], $context->getAllChannelLocales());
    }

    public function testResolveForChannelMatchesAgainstTheChannelLocales(): void
    {
        $context = $this->createContext(['cs_CZ', 'en_US']);

        self::assertSame('cs_CZ', $context->resolveForChannel('cs'));
        self::assertSame('en_US', $context->resolveForChannel('en-GB'));
        self::assertNull($context->resolveForChannel('de_AT'));
    }

    public function testCurrentLocaleComesFromTheSyliusLocaleContext(): void
    {
        $context = $this->createContext(['cs_CZ'], 'cs_CZ');

        self::assertSame('cs_CZ', $context->getCurrentLocale());
    }

    public function testApplyChannelOverridesTheChannelResolver(): void
    {
        $channelResolver = $this->createMock(ChannelResolver::class);
        $channelResolver->expects(self::once())->method('setOverrideCode')->with('SK_WEB');
        $localeContext = $this->createStub(LocaleContextInterface::class);

        $context = new SyliusLocaleContext($channelResolver, $localeContext, new LocaleMatcher());

        $context->applyChannel('SK_WEB');
    }

    /**
     * @param array<string, list<string>> $localesByChannel
     */
    private function createContextOverChannels(array $localesByChannel): SyliusLocaleContext
    {
        $channelFactory = new ChannelFixtureFactory();
        $channels = [];

        foreach ($localesByChannel as $code => $localeCodes) {
            $channels[] = $channelFactory->createChannel($code, $localeCodes);
        }

        $channelResolver = $this->createStub(ChannelResolver::class);
        $channelResolver->method('getEnabledChannels')->willReturn($channels);

        $localeContext = $this->createStub(LocaleContextInterface::class);

        return new SyliusLocaleContext($channelResolver, $localeContext, new LocaleMatcher());
    }

    /**
     * @param list<string> $channelLocales
     */
    private function createContext(array $channelLocales, string $currentLocale = 'cs_CZ'): SyliusLocaleContext
    {
        $locales = [];

        foreach ($channelLocales as $code) {
            $locale = new Locale();
            $locale->setCode($code);
            $locales[] = $locale;
        }

        $channel = $this->createStub(ChannelInterface::class);
        $channel->method('getLocales')->willReturn(new ArrayCollection($locales));

        $channelResolver = $this->createStub(ChannelResolver::class);
        $channelResolver->method('getChannel')->willReturn($channel);

        $localeContext = $this->createStub(LocaleContextInterface::class);
        $localeContext->method('getLocaleCode')->willReturn($currentLocale);

        return new SyliusLocaleContext($channelResolver, $localeContext, new LocaleMatcher());
    }
}
