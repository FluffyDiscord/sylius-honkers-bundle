<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Registry;

use FluffyDiscord\SyliusChatbotBundle\Contract\ToolChoiceLoaderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

readonly class ToolChoiceLoaderRegistry
{
    /**
     * @param ServiceLocator<ToolChoiceLoaderInterface> $loaders
     */
    public function __construct(
        #[AutowireLocator('fluffydiscord_chatbot.tool_choice_loader')]
        private ServiceLocator $loaders,
    ) {
    }

    /**
     * @param class-string<ToolChoiceLoaderInterface> $loaderClass
     *
     * @return list<string>
     */
    public function getChoices(string $loaderClass): array
    {
        $isRegistered = $this->loaders->has($loaderClass);
        if (!$isRegistered) {
            throw new \LogicException(sprintf(
                'The tool choice loader "%s" is not a service implementing %s.',
                $loaderClass,
                ToolChoiceLoaderInterface::class,
            ));
        }

        $loader = $this->loaders->get($loaderClass);
        $choices = $loader->loadChoices();

        return array_values(array_unique($choices));
    }
}
