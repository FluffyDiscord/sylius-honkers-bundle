<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Validator;

use FluffyDiscord\SyliusChatbotBundle\Contract\ToolChoiceLoaderInterface;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraints\Choice;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class ToolChoice extends Choice
{
    public string $loader;

    /**
     * @param class-string<ToolChoiceLoaderInterface> $loader
     * @param list<string>|null                       $groups
     */
    #[HasNamedArguments]
    public function __construct(
        string  $loader,
        ?bool   $multiple = null,
        ?int    $min = null,
        ?int    $max = null,
        ?string $message = null,
        ?string $multipleMessage = null,
        ?string $minMessage = null,
        ?string $maxMessage = null,
        ?bool   $match = null,
        ?array  $groups = null,
        mixed   $payload = null,
    ) {
        parent::__construct(
            multiple: $multiple,
            min: $min,
            max: $max,
            message: $message,
            multipleMessage: $multipleMessage,
            minMessage: $minMessage,
            maxMessage: $maxMessage,
            groups: $groups,
            payload: $payload,
            match: $match,
        );

        $this->loader = $loader;
    }

    /**
     * @param list<string> $choices
     */
    public function withChoices(array $choices): self
    {
        $loadedConstraint = clone $this;
        $loadedConstraint->choices = $choices;

        return $loadedConstraint;
    }
}
