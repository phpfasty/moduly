<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Tests\Support;

use PhpFasty\Moduly\Package\Fs;
use PhpFasty\Moduly\Signature\Signer;
use ZipArchive;

/**
 * Builds module folders and zips in a temp dir for tests.
 */
final class Fixture
{
    public readonly string $root;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/moduly-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
    }

    public function cleanup(): void
    {
        Fs::remove($this->root);
    }

    /**
     * @param array<string, mixed> $overrides module.json overrides
     * @param array<string, string> $extraFiles relative path => content
     */
    public function module(string $id, array $overrides = [], array $extraFiles = [], string $body = ''): string
    {
        $studly = str_replace(' ', '', ucwords(str_replace('-', ' ', $id)));
        $ns = "Acme\\Mod{$studly}\\";
        $manifest = array_merge([
            '$schema' => 'https://moduly.phpfasty.dev/schema/module-1.json',
            'id' => $id,
            'name' => ucfirst($id),
            'version' => '1.0.0',
            'publisher' => 'acme',
            'license' => 'AGPL-3.0-or-later',
            'requires' => ['core' => '^0.1', 'php' => '>=8.2'],
            'permissions' => [],
            'entry' => $ns . 'Module',
            'autoload' => ['psr-4' => [$ns => 'src/']],
        ], $overrides);
        $dir = $this->root . '/src-' . bin2hex(random_bytes(4)) . '/' . $id;
        mkdir($dir . '/src', 0755, true);
        file_put_contents($dir . '/module.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $nsDecl = rtrim($ns, '\\');
        file_put_contents($dir . '/src/Module.php', <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$nsDecl};

        use PhpFasty\\Moduly\\Contract\\ModuleInterface;
        use PhpFasty\\Moduly\\Kernel\\ModuleContext;

        final class Module implements ModuleInterface
        {
            public static array \$calls = [];

            public function boot(ModuleContext \$context): void
            {
                self::\$calls[] = 'boot';
                {$body}
            }

            public function activate(ModuleContext \$context): void
            {
                self::\$calls[] = 'activate';
            }

            public function deactivate(ModuleContext \$context): void
            {
                self::\$calls[] = 'deactivate';
            }

            public static function calls(): array
            {
                return self::\$calls;
            }

            public function ping(mixed \$v = null): mixed
            {
                return \$v;
            }
        }

        PHP);
        foreach ($extraFiles as $rel => $content) {
            @mkdir(dirname($dir . '/' . $rel), 0755, true);
            file_put_contents($dir . '/' . $rel, $content);
        }
        return $dir;
    }

    /** @return array{secret: string, public: string} */
    public static function keypair(): array
    {
        $kp = sodium_crypto_sign_keypair();
        return [
            'secret' => base64_encode(sodium_crypto_sign_secretkey($kp)),
            'public' => base64_encode(sodium_crypto_sign_publickey($kp)),
        ];
    }

    public function sign(string $dir, ?string $secret): void
    {
        Signer::sign($dir, $secret);
    }

    /** Zip a module dir (the folder itself becomes the top-level entry). */
    public function zip(string $dir, ?string $name = null): string
    {
        $zipPath = $this->root . '/' . ($name ?? basename($dir) . '-' . bin2hex(random_bytes(3)) . '.zip');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $base = dirname($dir);
        foreach (Fs::files($dir) as $rel) {
            $zip->addFile($dir . '/' . $rel, basename($dir) . '/' . $rel);
        }
        $zip->close();
        return $zipPath;
    }

    /** @param array<string, string> $entries raw entry name => content */
    public function rawZip(array $entries, ?callable $tweak = null): string
    {
        $zipPath = $this->root . '/raw-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        if ($tweak !== null) {
            $tweak($zip);
        }
        $zip->close();
        return $zipPath;
    }
}
