<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\DTO;

class ProductsBlock implements \JsonSerializable
{
    public function __construct(
        public readonly array $items,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'products',
            'items' => array_map(
                fn (ProductItem $item): array => $item->jsonSerialize(),
                $this->items,
            ),
        ];
    }
}
