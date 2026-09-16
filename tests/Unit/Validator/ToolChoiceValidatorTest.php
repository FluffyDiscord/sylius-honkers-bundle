<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Validator;

use FluffyDiscord\SyliusChatbotBundle\Registry\ToolChoiceLoaderRegistry;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\RegionArguments;
use FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures\RegionChoiceLoader;
use FluffyDiscord\SyliusChatbotBundle\Validator\ToolChoice;
use FluffyDiscord\SyliusChatbotBundle\Validator\ToolChoiceValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Mapping\ClassMetadata;
use Symfony\Component\Validator\Mapping\Loader\AttributeLoader;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<ToolChoiceValidator>
 */
class ToolChoiceValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ConstraintValidatorInterface
    {
        $registry = new ToolChoiceLoaderRegistry(new ServiceLocator([
            RegionChoiceLoader::class => static fn (): RegionChoiceLoader => new RegionChoiceLoader(),
        ]));

        return new ToolChoiceValidator($registry);
    }

    public function testAcceptsAValueTheLoaderOffers(): void
    {
        $this->validator->validate('Moravskoslezský kraj', $this->createConstraint());

        $this->assertNoViolation();
    }

    public function testAcceptsAMissingValue(): void
    {
        $this->validator->validate(null, $this->createConstraint());

        $this->assertNoViolation();
    }

    #[DataProvider('provideRejectedValues')]
    public function testRejectsAValueTheLoaderDoesNotOffer(mixed $value, string $formattedValue): void
    {
        $this->validator->validate($value, $this->createConstraint('The region is not one of the offered names.'));

        $this->buildViolation('The region is not one of the offered names.')
            ->setParameter('{{ value }}', $formattedValue)
            ->setParameter('{{ choices }}', '"Praha", "Moravskoslezský kraj"')
            ->setCode(Choice::NO_SUCH_CHOICE_ERROR)
            ->assertRaised();
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideRejectedValues(): iterable
    {
        yield 'city instead of a region' => ['Ostrava', '"Ostrava"'];
        yield 'different spelling' => ['moravskoslezský kraj', '"moravskoslezský kraj"'];
        yield 'empty string' => ['', '""'];
        yield 'integer' => [1, '1'];
    }

    public function testUsesTheNativeChoiceMessageByDefault(): void
    {
        $this->validator->validate('Ostrava', $this->createConstraint());

        $this->buildViolation('The value you selected is not a valid choice.')
            ->setParameter('{{ value }}', '"Ostrava"')
            ->setParameter('{{ choices }}', '"Praha", "Moravskoslezský kraj"')
            ->setCode(Choice::NO_SUCH_CHOICE_ERROR)
            ->assertRaised();
    }

    public function testAcceptsSeveralOfferedValuesWhenMultiple(): void
    {
        $constraint = new ToolChoice(loader: RegionChoiceLoader::class, multiple: true);

        $this->validator->validate(['Praha', 'Moravskoslezský kraj'], $constraint);

        $this->assertNoViolation();
    }

    public function testRejectsEachValueTheLoaderDoesNotOfferWhenMultiple(): void
    {
        $constraint = new ToolChoice(loader: RegionChoiceLoader::class, multiple: true);

        $this->validator->validate(['Praha', 'Ostrava'], $constraint);

        $this->buildViolation($constraint->multipleMessage)
            ->setParameter('{{ value }}', '"Ostrava"')
            ->setParameter('{{ choices }}', '"Praha", "Moravskoslezský kraj"')
            ->setCode(Choice::NO_SUCH_CHOICE_ERROR)
            ->setInvalidValue('Ostrava')
            ->assertRaised();
    }

    public function testEnforcesTheMaximumCountWhenMultiple(): void
    {
        $constraint = new ToolChoice(loader: RegionChoiceLoader::class, multiple: true, max: 1);

        $this->validator->validate(['Praha', 'Moravskoslezský kraj'], $constraint);

        $this->buildViolation($constraint->maxMessage)
            ->setParameter('{{ limit }}', '1')
            ->setPlural(1)
            ->setCode(Choice::TOO_MANY_ERROR)
            ->assertRaised();
    }

    public function testLeavesTheAttributeConstraintWithoutLoadedChoices(): void
    {
        $constraint = $this->createConstraint();

        $this->validator->validate('Praha', $constraint);

        self::assertNull($constraint->choices);
    }

    public function testRefusesAnotherConstraint(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate('Praha', new NotBlank());
    }

    public function testRefusesALoaderThatIsNotRegistered(): void
    {
        $this->expectException(\LogicException::class);

        $this->validator->validate('Praha', new ToolChoice(loader: self::class));
    }

    public function testReadsTheLoaderFromThePropertyAttribute(): void
    {
        $metadata = new ClassMetadata(RegionArguments::class);
        (new AttributeLoader())->loadClassMetadata($metadata);

        $constraints = $metadata->getPropertyMetadata('region')[0]->getConstraints();

        self::assertCount(1, $constraints);
        self::assertInstanceOf(ToolChoice::class, $constraints[0]);
        self::assertSame(RegionChoiceLoader::class, $constraints[0]->loader);
    }

    private function createConstraint(?string $message = null): ToolChoice
    {
        return new ToolChoice(loader: RegionChoiceLoader::class, message: $message);
    }
}
