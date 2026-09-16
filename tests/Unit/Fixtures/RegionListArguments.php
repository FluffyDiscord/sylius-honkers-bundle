<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures;

use FluffyDiscord\SyliusChatbotBundle\Validator\ToolChoice;

readonly class RegionListArguments
{
    /**
     * @param list<string> $regions
     */
    public function __construct(
        #[ToolChoice(loader: RegionChoiceLoader::class, multiple: true, min: 1, max: 2)]
        public array $regions = [],
    ) {
    }
}
