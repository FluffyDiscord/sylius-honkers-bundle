<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures;

use FluffyDiscord\SyliusChatbotBundle\Contract\ToolChoiceLoaderInterface;

class RegionChoiceLoader implements ToolChoiceLoaderInterface
{
    public function loadChoices(): array
    {
        return ['Praha', 'Moravskoslezský kraj'];
    }
}
