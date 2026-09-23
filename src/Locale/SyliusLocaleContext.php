<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Locale;

use FluffyDiscord\Honkers\Contract\ChatbotLocaleContextInterface;
use FluffyDiscord\Honkers\Locale\LocaleMatcher;
use FluffyDiscord\SyliusHonkersBundle\Channel\ChannelResolver;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Locale\Model\LocaleInterface;

class SyliusLocaleContext implements ChatbotLocaleContextInterface
{
    public function __construct(
        private readonly ChannelResolver        $channelResolver,
        private readonly LocaleContextInterface $localeContext,
        private readonly LocaleMatcher          $localeMatcher,
    ) {
    }

    public function applyChannel(?string $channelCode): void
    {
        $this->channelResolver->setOverrideCode($channelCode);
    }

    public function getCurrentLocale(): string
    {
        return $this->localeContext->getLocaleCode();
    }

    /**
     * @return list<string>
     */
    public function getChannelLocales(): array
    {
        return $this->collectLocaleCodes($this->channelResolver->getChannel()->getLocales());
    }

    /**
     * @return list<string>
     */
    public function getAllChannelLocales(): array
    {
        $locales = [];

        foreach ($this->channelResolver->getEnabledChannels() as $channel) {
            $channelLocales = $this->collectLocaleCodes($channel->getLocales());

            foreach ($channelLocales as $localeCode) {
                $isCollected = in_array($localeCode, $locales, true);

                if ($isCollected) {
                    continue;
                }

                $locales[] = $localeCode;
            }
        }

        return $locales;
    }

    public function resolveForChannel(string $requestedLocale): ?string
    {
        return $this->localeMatcher->resolveServedLocale($requestedLocale, $this->getChannelLocales());
    }

    /**
     * @param iterable<array-key, LocaleInterface> $channelLocales
     *
     * @return list<string>
     */
    private function collectLocaleCodes(iterable $channelLocales): array
    {
        $locales = [];

        foreach ($channelLocales as $channelLocale) {
            $localeCode = $channelLocale->getCode();

            if ($localeCode === null || $localeCode === '') {
                continue;
            }

            $locales[] = $localeCode;
        }

        return $locales;
    }
}
