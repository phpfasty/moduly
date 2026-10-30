<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Tests\Support;

use PhpFasty\Moduly\Kernel\ModuleRecord;
use PhpFasty\Moduly\Kernel\StateStoreInterface;

final class MemoryState implements StateStoreInterface
{
    /** @param array<string, ModuleRecord> $records */
    public function __construct(public array $records = [])
    {
    }

    public function load(): array
    {
        return $this->records;
    }

    public function save(array $records): void
    {
        $this->records = $records;
    }
}
