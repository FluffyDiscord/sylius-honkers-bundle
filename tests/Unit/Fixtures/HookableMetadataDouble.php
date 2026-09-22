<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Fixtures;

class HookableMetadataDouble
{
    public DataBagDouble $context;

    /**
     * @param array<string, string> $context
     */
    public function __construct(array $context)
    {
        $this->context = new DataBagDouble($context);
    }
}
