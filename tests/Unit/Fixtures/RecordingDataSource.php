<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures;

use FluffyDiscord\SyliusChatbotBundle\Contract\ChatbotDataSourceInterface;
use FluffyDiscord\SyliusChatbotBundle\DTO\DocumentPage;
use FluffyDiscord\SyliusChatbotBundle\DTO\SourceDefinition;
use FluffyDiscord\SyliusChatbotBundle\DTO\SourceDocument;
use FluffyDiscord\SyliusChatbotBundle\DTO\SourceQuery;

class RecordingDataSource implements ChatbotDataSourceInterface
{
    /** @var list<string> */
    public array $queriedLocales = [];

    /**
     * @param ?list<string>        $locales
     * @param list<SourceDocument> $documents
     */
    public function __construct(
        private readonly string $name,
        private readonly ?array $locales = null,
        private readonly array  $documents = [],
    ) {
    }

    public function getDefinition(): SourceDefinition
    {
        return new SourceDefinition($this->name, 'description', $this->locales);
    }

    public function getDocuments(SourceQuery $query): DocumentPage
    {
        $this->queriedLocales[] = $query->locale;

        return new DocumentPage($this->documents);
    }
}
