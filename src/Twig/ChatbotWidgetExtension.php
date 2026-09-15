<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ChatbotWidgetExtension extends AbstractExtension
{
    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('fluffydiscord_chatbot_site_key', [ChatbotWidgetRuntime::class, 'getSiteKey']),
        ];
    }
}
