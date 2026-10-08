<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures;

class HookableMetadataDouble
{
    public DataBagDouble $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(array $context)
    {
        $this->context = new DataBagDouble($context);
    }
}
