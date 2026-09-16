<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures;

use FluffyDiscord\SyliusChatbotBundle\Validator\ToolChoice;

readonly class RegionArguments
{
    public function __construct(
        #[ToolChoice(loader: RegionChoiceLoader::class)]
        public ?string $region = null,
    ) {
    }
}
