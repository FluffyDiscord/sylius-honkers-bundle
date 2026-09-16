<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Validator;

use FluffyDiscord\SyliusChatbotBundle\Contract\ToolChoiceLoaderInterface;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class ToolChoice extends Constraint
{
    public const NO_SUCH_CHOICE_ERROR = '5c1e5c0e-3a1f-4f5e-9d0b-6a3c8f1b2e47';

    protected const ERROR_NAMES = [
        self::NO_SUCH_CHOICE_ERROR => 'NO_SUCH_CHOICE_ERROR',
    ];

    public string $loader;
    public string $message = 'The value you selected is not a valid choice.';

    /**
     * @param class-string<ToolChoiceLoaderInterface> $loader
     * @param list<string>|null                       $groups
     */
    #[HasNamedArguments]
    public function __construct(
        string  $loader,
        ?string $message = null,
        ?array  $groups = null,
        mixed   $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);

        $this->loader = $loader;
        $this->message = $message ?? $this->message;
    }
}
