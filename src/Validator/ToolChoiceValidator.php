<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Validator;

use FluffyDiscord\SyliusChatbotBundle\Registry\ToolChoiceLoaderRegistry;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\ChoiceValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ToolChoiceValidator extends ChoiceValidator
{
    public function __construct(
        private readonly ToolChoiceLoaderRegistry $choiceLoaderRegistry,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ToolChoice) {
            throw new UnexpectedTypeException($constraint, ToolChoice::class);
        }

        if ($value === null) {
            return;
        }

        $choices = $this->choiceLoaderRegistry->getChoices($constraint->loader);

        parent::validate($value, $constraint->withChoices($choices));
    }
}
