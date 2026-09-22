<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Fixtures;

/**
 * @implements \ArrayAccess<string, string>
 */
class DataBagDouble implements \ArrayAccess
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(
        private readonly array $values = [],
    ) {
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->values[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->values[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('The widget context is read only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('The widget context is read only.');
    }

    public function __get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }
}
