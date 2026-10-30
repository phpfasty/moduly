<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Tests\Unit;

use PhpFasty\Moduly\Exception\PackageException;
use PhpFasty\Moduly\Package\Fs;
use PhpFasty\Moduly\Package\Limits;
use PhpFasty\Moduly\Package\SafeZipExtractor;
use PhpFasty\Moduly\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ZipTest extends TestCase
{
    private Fixture $fx;

    protected function setUp(): void
    {
        $this->fx = new Fixture();
    }

    protected function tearDown(): void
    {
        $this->fx->cleanup();
    }

    public function testExtractsSafePackage(): void
    {
        $zip = $this->fx->rawZip(['m/module.json' => '{}', 'm/src/A.php' => '<?php', 'm/assets/a.css' => 'a{}']);
        $dir = (new SafeZipExtractor())->extract($zip, $this->fx->root . '/q');
        self::assertSame(['m/assets/a.css', 'm/module.json', 'm/src/A.php'], Fs::files($dir));
        self::assertStringStartsWith(realpath($this->fx->root . '/q') . '/q-', $dir);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function evil(): array
    {
        return [
            'zip-slip' => [['m/../../x.php' => '<?php'], 'bad_path'],
            'absolute' => [['/etc/x.json' => '{}'], 'bad_path'],
            'backslash' => [['m\\..\\x.json' => '{}'], 'bad_path'],
            'drive' => [['C:/x.json' => '{}'], 'bad_path'],
            'php outside src' => [['m/x.php' => '<?php'], 'php_location'],
            'php in assets' => [['m/assets/x.php' => '<?php'], 'php_location'],
            'phar' => [['m/src/x.phar' => 'x'], 'file_type'],
            'htaccess' => [['m/.htaccess' => 'x'], 'hidden_file'],
            'no ext' => [['m/run' => 'x'], 'file_type'],
            'dot segment' => [['m/./src/a.php' => 'x'], 'bad_path'],
        ];
    }

    /** @param array<string, string> $entries */
    #[DataProvider('evil')]
    public function testRejectsEvilEntries(array $entries, string $reason): void
    {
        $zip = $this->fx->rawZip($entries);
        try {
            (new SafeZipExtractor())->extract($zip, $this->fx->root . '/q');
            self::fail('expected rejection');
        } catch (PackageException $e) {
            self::assertSame($reason, $e->reason);
        }
        self::assertSame([], glob($this->fx->root . '/q/q-*') ?: [], 'quarantine is cleaned up');
    }

    public function testRejectsSymlinkEntry(): void
    {
        $zip = $this->fx->rawZip(['m/link.txt' => '/etc/passwd'], static function (ZipArchive $z): void {
            $z->setExternalAttributesName('m/link.txt', ZipArchive::OPSYS_UNIX, (0120777 << 16));
        });
        $this->assertReason('symlink', $zip);
    }

    public function testRejectsNonZipWithZipName(): void
    {
        $f = $this->fx->root . '/fake.zip';
        file_put_contents($f, "<?php echo 'hi';");
        $this->assertReason('not_zip', $f);
    }

    public function testRejectsZipBomb(): void
    {
        $zip = $this->fx->rawZip(['m/big.txt' => str_repeat('0', 5_000_000)]);
        $this->assertReason('zip_bomb', $zip);
    }

    public function testRejectsTooManyEntries(): void
    {
        $entries = [];
        for ($i = 0; $i < 6; $i++) {
            $entries["m/f{$i}.txt"] = 'x';
        }
        $zip = $this->fx->rawZip($entries);
        $this->assertReason('too_many_entries', $zip, new Limits(maxEntries: 5));
    }

    public function testRejectsTooLargeZip(): void
    {
        $zip = $this->fx->rawZip(['m/a.txt' => random_bytes(4000)]);
        $this->assertReason('too_large', $zip, new Limits(maxZipBytes: 1000));
    }

    private function assertReason(string $reason, string $zip, ?Limits $limits = null): void
    {
        try {
            (new SafeZipExtractor($limits ?? new Limits()))->extract($zip, $this->fx->root . '/q');
            self::fail('expected ' . $reason);
        } catch (PackageException $e) {
            self::assertSame($reason, $e->reason);
        }
    }
}
