<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusChatbotBundle\Tests\Unit\Fixtures;

use Psr\Log\AbstractLogger;

class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<mixed>
     */
    public function getContextValues(string $contextKey): array
    {
        $values = [];

        foreach ($this->records as $record) {
            $hasContextKey = array_key_exists($contextKey, $record['context']);
            if ($hasContextKey) {
                $values[] = $record['context'][$contextKey];
            }
        }

        return $values;
    }
}
