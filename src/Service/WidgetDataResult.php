<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Service;

/**
 * Immutable value object for widget data query results.
 */
class WidgetDataResult
{
    /** @var array<array<string,mixed>> Bare rows array — the canonical widget
     *  data form delivered directly to the frontend (never an envelope). */
    public readonly array $rows;
    public readonly bool $cached;
    public readonly ?int $executionTimeMs;
    public readonly string $sql;

    private function __construct(
        array $rows,
        string $sql,
        bool $cached,
        ?int $execution_time_ms
    ) {
        $this->rows = $rows;
        $this->sql = $sql;
        $this->cached = $cached;
        $this->executionTimeMs = $execution_time_ms;
    }

    public static function fromRawQuery(array $rows, string $sql, ?int $execution_time_ms = null): self
    {
        return new self($rows, $sql, false, $execution_time_ms);
    }

    public static function fromCache(array $rows, string $sql): self
    {
        return new self($rows, $sql, true, null);
    }
}
