<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Tests\Unit;

use PhpFasty\Moduly\Exception\ModulyException;
use PhpFasty\Moduly\Manifest\DependencyResolver;
use PhpFasty\Moduly\Moduly;
use PhpFasty\Moduly\Package\Installer;
use PhpFasty\Moduly\Signature\Keyring;
use PhpFasty\Moduly\Signature\Verifier;
use PhpFasty\Moduly\Tests\Support\Fixture;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    private Fixture $fx;
    /** @var array{secret: string, public: string} */
    private array $kp;

    protected function setUp(): void
    {
        $this->fx = new Fixture();
        $this->kp = Fixture::keypair();
    }

    protected function tearDown(): void
    {
        $this->fx->cleanup();
    }

    private function installer(): Installer
    {
        return new Installer(
            $this->fx->root . '/modules',
            $this->fx->root . '/quarantine',
            new Verifier(new Keyring(['acme' => [$this->kp['public']]])),
            new DependencyResolver(Moduly::VERSION, PHP_VERSION, ['wp' => '6.8.1']),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, string> $files
     */
    private function signedZip(string $id, array $overrides = [], array $files = []): string
    {
        $dir = $this->fx->module($id, $overrides, $files);
        $this->fx->sign($dir, $this->kp['secret']);
        return $this->fx->zip($dir);
    }

    public function testInstallsIntoVersionFolderWithoutExecuting(): void
    {
        $r = $this->installer()->install($this->signedZip('inst-ok'));
        self::assertTrue($r->signed);
        self::assertSame($this->fx->root . '/modules/inst-ok/1.0.0', $r->path);
        self::assertFileExists($r->path . '/module.json');
        self::assertFileExists($r->path . '/SIGNATURE');
        self::assertFalse(class_exists('Acme\\ModInstOk\\Module', false), 'nothing was included');
        self::assertSame([], glob($this->fx->root . '/quarantine/q-*') ?: []);
    }

    public function testForbiddenCodeIsRejected(): void
    {
        $zip = $this->signedZip('inst-eval', [], ['src/Evil.php' => "<?php\neval(\$_GET['x']);\n"]);
        $this->expectReason('forbidden_code', $zip);
        self::assertDirectoryDoesNotExist($this->fx->root . '/modules/inst-eval');
    }

    public function testUnmetDependencyIsRejected(): void
    {
        $this->expectReason('unmet_dependency', $this->signedZip('inst-dep', ['requires' => ['modules' => ['types' => '^1.0']]]));
        $this->expectReason('unmet_dependency', $this->signedZip('inst-wp', ['requires' => ['wp' => '>=9.0']]));
        $this->expectReason('unmet_dependency', $this->signedZip('inst-core', ['requires' => ['core' => '^9.0']]));
    }

    public function testFolderMustMatchId(): void
    {
        $dir = $this->fx->module('inst-name');
        $json = json_decode((string) file_get_contents($dir . '/module.json'), true);
        self::assertIsArray($json);
        $json['id'] = 'other-name';
        file_put_contents($dir . '/module.json', json_encode($json));
        $this->expectReason('invalid_manifest', $this->fx->zip($dir));
    }

    public function testTwoTopLevelFoldersRejected(): void
    {
        $zip = $this->fx->rawZip(['a/module.json' => '{}', 'b/module.json' => '{}']);
        $this->expectReason('bad_layout', $zip);
    }

    public function testDowngradeRefusedAndSameVersionReplaced(): void
    {
        $inst = $this->installer();
        $v2 = $inst->install($this->signedZip('inst-ver', ['version' => '2.0.0']));
        $installed = ['inst-ver' => $v2->manifest];
        try {
            $inst->install($this->signedZip('inst-ver', ['version' => '1.0.0']), $installed);
            self::fail('downgrade accepted');
        } catch (ModulyException $e) {
            self::assertSame('downgrade', $e->reason);
        }
        $again = $inst->install($this->signedZip('inst-ver', ['version' => '2.0.0']), $installed);
        self::assertTrue($again->replaced);
        self::assertDirectoryExists($again->path);
        self::assertSame(['2.0.0'], array_values(array_diff(scandir($this->fx->root . '/modules/inst-ver') ?: [], ['.', '..'])));
    }

    public function testTamperedZipRejected(): void
    {
        $dir = $this->fx->module('inst-tamper');
        $this->fx->sign($dir, $this->kp['secret']);
        file_put_contents($dir . '/src/Module.php', "\n", FILE_APPEND);
        $this->expectReason('hash_mismatch', $this->fx->zip($dir));
    }

    private function expectReason(string $reason, string $zip): void
    {
        try {
            $this->installer()->install($zip);
            self::fail('expected ' . $reason);
        } catch (ModulyException $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
        }
        self::assertSame([], glob($this->fx->root . '/quarantine/q-*') ?: [], 'quarantine cleaned');
    }
}
