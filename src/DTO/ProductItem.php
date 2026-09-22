<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\DTO;

class ProductItem implements \JsonSerializable
{
    public function __construct(
        public readonly string  $code,
        public readonly string  $productCode,
        public readonly string  $name,
        public readonly string  $url,
        public readonly int     $priceMinor,
        public readonly string  $currency,
        public readonly ?string $imageUrl,
        public readonly bool    $inStock,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code,
            'productCode' => $this->productCode,
            'name' => $this->name,
            'url' => $this->url,
            'priceMinor' => $this->priceMinor,
            'currency' => $this->currency,
            'imageUrl' => $this->imageUrl,
            'inStock' => $this->inStock,
        ];
    }
}
