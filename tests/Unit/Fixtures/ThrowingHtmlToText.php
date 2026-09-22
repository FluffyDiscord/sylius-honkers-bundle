<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersBundle\Tests\Unit\Fixtures;

use FluffyDiscord\Honkers\Text\HtmlToText;

class ThrowingHtmlToText extends HtmlToText
{
    public function convert(string $html): string
    {
        throw new \RuntimeException('Conversion failed.');
    }
}
