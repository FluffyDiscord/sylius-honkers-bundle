<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tool\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class OrderStatusArguments
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 32)]
        public readonly string $orderNumber = '',
        #[Assert\NotBlank]
        #[Assert\Email]
        public readonly string $email = '',
    ) {
    }
}
