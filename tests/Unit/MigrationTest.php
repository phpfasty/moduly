<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Tests\Unit;

use PhpFasty\Moduly\Migration\FieldValue;
use PhpFasty\Moduly\Migration\LockInterface;
use PhpFasty\Moduly\Migration\MigrationGuard;
use PhpFasty\Moduly\Migration\MigrationInterface;
use PhpFasty\Moduly\Migration\Migrator;
use PhpFasty\Moduly\Migration\SchemaVersionStoreInterface;
use PhpFasty\Moduly\Tests\Support\Fixture;
use PHPUnit\Framework\TestCase;

final class MigrationTest extends TestCase
{
    public function testRunsPendingInOrderOnceAndInBatches(): void
    {
        $store = new class implements SchemaVersionStoreInterface {
            /** @var array<string, int> */
            public array $v = [];

            public function get(string $scope): int
            {
                return $this->v[$scope] ?? 0;
            }

            public function set(string $scope, int $version): void
            {
                $this->v[$scope] = $version;
            }
        };
        $lock = new class implements LockInterface {
            public bool $held = false;

            public function acquire(string $name, int $ttlSeconds): bool
            {
                if ($this->held) {
                    return false;
                }
                return $this->held = true;
            }

            public function release(string $name): void
            {
                $this->held = false;
            }
        };
        /** @var \ArrayObject<int, int> $log */
        $log = new \ArrayObject();
        $make = static fn (int $v, int $batches) => new class ($v, $batches, $log) implements MigrationInterface {
            private int $left;

            /** @param \ArrayObject<int, int> $log */
            public function __construct(private int $v, int $batches, private \ArrayObject $log)
            {
                $this->left = $batches;
            }

            public function version(): int
            {
                return $this->v;
            }

            public function up(int $batchSize): bool
            {
                $this->log->append($this->v);
                return --$this->left <= 0;
            }
        };
        $m = new Migrator($store, $lock, 10, 50);
        $r = $m->run('latte-shop', [$make(2, 3), $make(1, 1)]);
        self::assertSame(['from' => 0, 'to' => 2, 'done' => true, 'ran' => [1, 2]], $r);
        self::assertSame([1, 2, 2, 2], $log->getArrayCopy());
        // Re-run: nothing pending.
        $r2 = $m->run('latte-shop', [$make(2, 1), $make(1, 1)]);
        self::assertSame([], $r2['ran']);
        // Locked: no work, not done.
        $lock->held = true;
        self::assertFalse($m->run('other', [$make(1, 1)])['done']);
        $lock->held = false;
        // Batch budget: resumes on the next run.
        $limited = new Migrator($store, $lock, 10, 2);
        self::assertFalse($limited->run('budget', [$make(1, 5)])['done']);
        self::assertSame(0, $store->get('budget'));
    }

    public function testGuardFlagsDestructiveMigrations(): void
    {
        $fx = new Fixture();
        $dir = $fx->root . '/mig';
        mkdir($dir . '/src/Migration', 0755, true);
        file_put_contents($dir . '/src/Migration/Migration_0001_AddPrice.php', "<?php\nfunction up() { update_post_meta(1, 'price_v2', 1); }\n");
        file_put_contents($dir . '/src/Migration/Migration_0002_Bad.php', "<?php\nfunction up() { delete_post_meta(1, 'price'); \\delete_option('x'); }\nfunction down() {}\n");
        file_put_contents($dir . '/src/Migration/Migration_0003_Sql.php', "<?php\n\$wpdb->query('DELETE FROM wp_postmeta');\n");
        file_put_contents($dir . '/src/Other.php', "<?php\ndelete_option('fine_outside_migrations');\n");
        $v = MigrationGuard::violations($dir);
        $fx->cleanup();
        self::assertSame([
            'src/Migration/Migration_0002_Bad.php:2 delete_post_meta()',
            'src/Migration/Migration_0002_Bad.php:2 delete_option()',
            'src/Migration/Migration_0002_Bad.php: down() is not allowed (forward-only)',
            'src/Migration/Migration_0003_Sql.php: destructive SQL',
        ], $v);
    }

    public function testFieldValueAliasesAndInvalidDefault(): void
    {
        $db = ['price' => '1999', 'old_stock' => 'many'];
        $get = static fn (string $k): mixed => $db[$k] ?? null;
        $isInt = static fn (mixed $v): bool => is_numeric($v);
        $logged = [];
        $log = static function (string $m, array $c) use (&$logged): void {
            $logged[] = $c['key'];
        };
        self::assertSame('1999', FieldValue::read($get, ['price_v2', 'price'], $isInt, 0, $log));
        self::assertSame(0, FieldValue::read($get, ['stock', 'old_stock'], $isInt, 0, $log));
        self::assertSame(['old_stock'], $logged);
        self::assertSame('many', $db['old_stock'], 'stored value untouched');
        self::assertNull(FieldValue::read($get, ['nothing'], $isInt, null));
    }
}
