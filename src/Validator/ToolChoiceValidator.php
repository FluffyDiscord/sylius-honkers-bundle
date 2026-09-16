<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Validator;

use FluffyDiscord\SyliusChatbotBundle\Registry\ToolChoiceLoaderRegistry;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ToolChoiceValidator extends ConstraintValidator
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
        $isChoice = in_array($value, $choices, true);
        if ($isChoice) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ value }}', $this->formatValue($value))
            ->setParameter('{{ choices }}', $this->formatValues($choices))
            ->setCode(ToolChoice::NO_SUCH_CHOICE_ERROR)
            ->addViolation();
    }
}
