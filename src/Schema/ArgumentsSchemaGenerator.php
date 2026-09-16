<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Schema;

use FluffyDiscord\SyliusChatbotBundle\Registry\ToolChoiceLoaderRegistry;
use FluffyDiscord\SyliusChatbotBundle\Validator\ToolChoice;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;

readonly class ArgumentsSchemaGenerator
{
    public function __construct(
        private ToolChoiceLoaderRegistry $choiceLoaderRegistry,
    ) {
    }

    public function generate(string $argumentsClass): array
    {
        $reflection = new \ReflectionClass($argumentsClass);
        $properties = [];
        $required = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $propertySchema = $this->buildPropertySchema($property);
            if ($propertySchema === null) {
                continue;
            }

            $properties[$property->getName()] = $propertySchema;
            $isRequired = $property->getAttributes(NotBlank::class) !== [];
            if ($isRequired) {
                $required[] = $property->getName();
            }
        }

        $schema = ['type' => 'object'];
        if ($properties !== []) {
            $schema['properties'] = $properties;
        }
        if ($required !== []) {
            $schema['required'] = $required;
        }
        $schema['additionalProperties'] = false;

        return $schema;
    }

    private function buildPropertySchema(\ReflectionProperty $property): ?array
    {
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return null;
        }

        $schema = match ($type->getName()) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'array' => $this->buildArraySchema($property),
            default => null,
        };
        if ($schema === null) {
            return null;
        }

        $hasEmailConstraint = $property->getAttributes(Email::class) !== [];
        if ($hasEmailConstraint) {
            $schema['format'] = 'email';
        }

        $choiceAttributes = $property->getAttributes(Choice::class);
        if ($choiceAttributes !== []) {
            $schema['enum'] = $choiceAttributes[0]->newInstance()->choices;
        }

        $toolChoiceAttributes = $property->getAttributes(ToolChoice::class);
        if ($toolChoiceAttributes !== []) {
            $toolChoice = $toolChoiceAttributes[0]->newInstance();
            $schema = $this->applyToolChoices($schema, $toolChoice, $property);
        }

        if ($schema === null) {
            return null;
        }

        $lengthAttributes = $property->getAttributes(Length::class);
        if ($lengthAttributes !== []) {
            $maxLength = $lengthAttributes[0]->newInstance()->max;
            if ($maxLength !== null) {
                $schema['maxLength'] = $maxLength;
            }
        }

        return $schema;
    }

    private function applyToolChoices(array $schema, ToolChoice $toolChoice, \ReflectionProperty $property): ?array
    {
        $expectedType = $toolChoice->multiple ? 'array' : 'string';
        $isExpectedType = $schema['type'] === $expectedType;
        if (!$isExpectedType) {
            throw new \LogicException(sprintf(
                'The #[ToolChoice] property %s::$%s must be typed %s.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                $expectedType,
            ));
        }

        $loadedChoices = $this->choiceLoaderRegistry->getChoices($toolChoice->loader);
        if ($loadedChoices === []) {
            return null;
        }

        if (!$toolChoice->multiple) {
            $schema['enum'] = $loadedChoices;

            return $schema;
        }

        $schema['items'] = ['type' => 'string', 'enum' => $loadedChoices];

        $minimumItems = $toolChoice->min;
        if ($minimumItems !== null) {
            $schema['minItems'] = $minimumItems;
        }

        $maximumItems = $toolChoice->max;
        if ($maximumItems !== null) {
            $schema['maxItems'] = $maximumItems;
        }

        return $schema;
    }

    private function buildArraySchema(\ReflectionProperty $property): array
    {
        $itemsType = 'string';
        $allAttributes = $property->getAttributes(All::class);
        if ($allAttributes !== []) {
            foreach ($allAttributes[0]->newInstance()->constraints as $constraint) {
                if ($constraint instanceof Type && is_string($constraint->type)) {
                    $itemsType = match ($constraint->type) {
                        'integer', 'int' => 'integer',
                        'float', 'numeric' => 'number',
                        'bool', 'boolean' => 'boolean',
                        default => 'string',
                    };
                }
            }
        }

        return ['type' => 'array', 'items' => ['type' => $itemsType]];
    }
}
