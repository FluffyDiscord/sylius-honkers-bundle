<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ChatClickExtension extends AbstractExtension
{
    public function __construct(
        #[Autowire(param: 'fluffydiscord_honkers.backend_url')]
        private readonly string $backendUrl,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('fluffydiscord_chatbot_click_url', $this->getClickUrl(...)),
        ];
    }

    public function getClickUrl(string $clickId): string
    {
        $backendUrl = rtrim($this->backendUrl, '/');
        $encodedClickId = rawurlencode($clickId);

        return $backendUrl . '/clicks/' . $encodedClickId;
    }
}
