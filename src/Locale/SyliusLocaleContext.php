<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Locale;

use FluffyDiscord\Honkers\Contract\ChatbotLocaleContextInterface;
use FluffyDiscord\Honkers\Locale\LocaleMatcher;
use FluffyDiscord\SyliusHonkersBundle\Channel\ChannelResolver;
use Sylius\Component\Locale\Context\LocaleContextInterface;

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
        $locales = [];

        foreach ($this->channelResolver->getChannel()->getLocales() as $channelLocale) {
            $localeCode = $channelLocale->getCode();

            if ($localeCode === null || $localeCode === '') {
                continue;
            }

            $locales[] = $localeCode;
        }

        return $locales;
    }

    public function resolveForChannel(string $requestedLocale): ?string
    {
        return $this->localeMatcher->resolveServedLocale($requestedLocale, $this->getChannelLocales());
    }
}
