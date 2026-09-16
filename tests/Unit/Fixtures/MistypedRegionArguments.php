<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures;

use FluffyDiscord\SyliusChatbotBundle\Validator\ToolChoice;

readonly class MistypedRegionArguments
{
    public function __construct(
        #[ToolChoice(loader: RegionChoiceLoader::class)]
        public int $region = 0,
    ) {
    }
}
