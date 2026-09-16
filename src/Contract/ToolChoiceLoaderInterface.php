<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('fluffydiscord_chatbot.tool_choice_loader')]
interface ToolChoiceLoaderInterface
{
    /**
     * @return list<string>
     */
    public function loadChoices(): array;
}
