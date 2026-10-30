<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Tests\Unit;

use PhpFasty\Moduly\Exception\SignatureException;
use PhpFasty\Moduly\Manifest\ManifestValidator;
use PhpFasty\Moduly\Signature\Canonical;
use PhpFasty\Moduly\Signature\Keyring;
use PhpFasty\Moduly\Signature\Verifier;
use PhpFasty\Moduly\Tests\Support\Fixture;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
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

    public function testCanonicalJsonSortsKeys(): void
    {
        self::assertSame('{"a":[{"x":1,"y":2}],"b":"/"}', Canonical::encode(['b' => '/', 'a' => [['y' => 2, 'x' => 1]]]));
    }

    public function testSignedModuleVerifies(): void
    {
        $kp = Fixture::keypair();
        $dir = $this->fx->module('sig-ok');
        $this->fx->sign($dir, $kp['secret']);
        $r = $this->verifier(['acme' => [$kp['public']]])->verify($dir, $this->manifest($dir));
        self::assertTrue($r['signed']);
        self::assertSame($kp['public'], $r['key']);
    }

    public function testModifiedFileFails(): void
    {
        $kp = Fixture::keypair();
        $dir = $this->fx->module('sig-mod');
        $this->fx->sign($dir, $kp['secret']);
        file_put_contents($dir . '/src/Module.php', "\n// tampered", FILE_APPEND);
        $this->assertReason('hash_mismatch', $dir, ['acme' => [$kp['public']]]);
    }

    public function testExtraFileFails(): void
    {
        $kp = Fixture::keypair();
        $dir = $this->fx->module('sig-extra');
        $this->fx->sign($dir, $kp['secret']);
        file_put_contents($dir . '/src/Backdoor.php', '<?php');
        $this->assertReason('file_list_mismatch', $dir, ['acme' => [$kp['public']]]);
    }

    public function testManifestChangeFails(): void
    {
        $kp = Fixture::keypair();
        $dir = $this->fx->module('sig-manifest');
        $this->fx->sign($dir, $kp['secret']);
        // Re-hash files with a different manifest but keep the old signature → the signature must not verify.
        $sig = json_decode((string) file_get_contents($dir . '/SIGNATURE'), true);
        self::assertIsArray($sig);
        self::assertIsArray($sig['files']);
        $json = json_decode((string) file_get_contents($dir . '/module.json'), true);
        self::assertIsArray($json);
        $json['permissions'] = ['db:custom-table'];
        file_put_contents($dir . '/module.json', json_encode($json));
        $sig['files']['module.json'] = hash_file('sha256', $dir . '/module.json');
        file_put_contents($dir . '/SIGNATURE', json_encode($sig));
        $this->assertReason('bad_signature', $dir, ['acme' => [$kp['public']]]);
    }

    public function testUntrustedKeyFails(): void
    {
        $kp = Fixture::keypair();
        $other = Fixture::keypair();
        $dir = $this->fx->module('sig-untrusted');
        $this->fx->sign($dir, $kp['secret']);
        $this->assertReason('untrusted_key', $dir, ['acme' => [$other['public']]]);
        $this->assertReason('untrusted_key', $dir, ['other' => [$kp['public']]]);
    }

    public function testRevokedKeyFails(): void
    {
        $kp = Fixture::keypair();
        $dir = $this->fx->module('sig-revoked');
        $this->fx->sign($dir, $kp['secret']);
        try {
            (new Verifier(new Keyring(['acme' => [$kp['public']]], [$kp['public']])))->verify($dir, $this->manifest($dir));
            self::fail('expected untrusted');
        } catch (SignatureException $e) {
            self::assertSame('untrusted_key', $e->reason);
        }
    }

    public function testUnsignedOnlyWhenAllowed(): void
    {
        $dir = $this->fx->module('sig-none');
        $this->fx->sign($dir, null);
        $this->assertReason('unsigned', $dir, []);
        $r = (new Verifier(new Keyring([], [], true)))->verify($dir, $this->manifest($dir));
        self::assertFalse($r['signed']);
    }

    public function testMissingSignatureFails(): void
    {
        $dir = $this->fx->module('sig-missing');
        $this->assertReason('missing_signature', $dir, []);
    }

    /** @param array<string, list<string>> $trusted */
    private function verifier(array $trusted): Verifier
    {
        return new Verifier(new Keyring($trusted));
    }

    private function manifest(string $dir): \PhpFasty\Moduly\Manifest\Manifest
    {
        return (new ManifestValidator())->fromJson((string) file_get_contents($dir . '/module.json'), basename($dir));
    }

    /** @param array<string, list<string>> $trusted */
    private function assertReason(string $reason, string $dir, array $trusted): void
    {
        try {
            $this->verifier($trusted)->verify($dir, $this->manifest($dir));
            self::fail('expected ' . $reason);
        } catch (SignatureException $e) {
            self::assertSame($reason, $e->reason);
        }
    }
}
