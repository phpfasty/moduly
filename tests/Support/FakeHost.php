<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Tests\Support;

use PhpFasty\Moduly\Contract\HostInterface;

final class FakeHost implements HostInterface
{
    /** @var list<array{string, string, int}> */
    public array $hooks = [];
    /** @var list<string> */
    public array $logs = [];

    public function name(): string
    {
        return 'test';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function on(string $type, string $hook, callable $callback, int $priority = 10, int $args = 1): void
    {
        $this->hooks[] = [$type, $hook, $priority];
    }

    public function settings(string $moduleId): array
    {
        return [];
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->logs[] = $level . ': ' . $message;
    }
}
