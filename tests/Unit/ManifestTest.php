<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Tests\Unit;

use PhpFasty\Moduly\Exception\ManifestException;
use PhpFasty\Moduly\Manifest\ManifestValidator;
use PHPUnit\Framework\TestCase;

final class ManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            '$schema' => ManifestValidator::SCHEMA,
            'id' => 'cart',
            'name' => 'Cart',
            'version' => '1.0.0',
            'publisher' => 'phpfasty',
            'license' => 'AGPL-3.0-or-later',
            'requires' => ['core' => '^0.1', 'php' => '>=8.2', 'wp' => '>=6.4', 'modules' => ['types' => '^1.0']],
            'provides' => ['features' => ['cart'], 'types' => [], 'capabilities' => ['moduly_cart_manage']],
            'permissions' => ['cookie:write', 'rest:public', 'http:outbound:api.example.com'],
            'entry' => 'PhpFasty\\Module\\Cart\\Module',
            'autoload' => ['psr-4' => ['PhpFasty\\Module\\Cart\\' => 'src/']],
            'hooks' => [['type' => 'action', 'name' => 'init', 'method' => 'registerRoutes', 'priority' => 10]],
            'settings' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['shipping_cents' => ['type' => 'integer', 'minimum' => 0]]],
        ];
    }

    public function testValidManifest(): void
    {
        $m = (new ManifestValidator())->fromArray(self::valid(), 'cart');
        self::assertSame('cart', $m->id);
        self::assertSame('1.0.0', (string) $m->version);
        self::assertSame(['types' => '^1.0'], $m->requiresModules);
        self::assertArrayHasKey('wp', $m->requiresHost);
        self::assertSame('init', $m->hooks[0]['name']);
        self::assertSame(1, $m->hooks[0]['args']);
    }

    public function testShippedSchemaFileIsValidJson(): void
    {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schema/module-1.json'), true);
        self::assertIsArray($schema);
        self::assertSame(ManifestValidator::SCHEMA, $schema['$id']);
        self::assertFalse($schema['additionalProperties']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalid(): array
    {
        $v = self::valid();
        return [
            'bad id' => [['id' => 'Cart!'] + $v, '"id" must match'],
            'folder mismatch' => [['id' => 'orders'] + $v, 'module root folder'],
            'bad version' => [['version' => '1.0'] + $v, 'SemVer'],
            'unknown key' => [$v + ['evil' => true], 'unknown property "evil"'],
            'reserved ns' => [array_merge($v, ['autoload' => ['psr-4' => ['PhpFasty\\Moduly\\X\\' => 'src/']], 'entry' => 'PhpFasty\\Moduly\\X\\Module']), 'reserved'],
            'entry outside' => [array_merge($v, ['entry' => 'Other\\Module']), 'namespace declared in autoload'],
            'dir escape' => [array_merge($v, ['autoload' => ['psr-4' => ['PhpFasty\\Module\\Cart\\' => '../src/']]]), 'relative path'],
            'permission' => [array_merge($v, ['permissions' => ['root:all']]), 'unknown permission'],
            'http conflict' => [array_merge($v, ['permissions' => ['http:none', 'http:outbound:a.com']]), 'conflicts'],
            'constraint' => [array_merge($v, ['requires' => ['core' => 'latest']]), 'bad constraint'],
            'settings open' => [array_merge($v, ['settings' => ['type' => 'object', 'properties' => []]]), 'additionalProperties must be false'],
            'hook' => [array_merge($v, ['hooks' => [['type' => 'eval', 'name' => 'init', 'method' => 'x']]]), 'hooks[0] is invalid'],
            'missing' => [array_diff_key($v, ['entry' => 1]), 'missing required "entry"'],
        ];
    }

    /** @param array<string, mixed> $data */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalidManifest(array $data, string $needle): void
    {
        try {
            (new ManifestValidator())->fromArray($data, 'cart');
            self::fail('expected ManifestException');
        } catch (ManifestException $e) {
            self::assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function testInvalidJson(): void
    {
        $this->expectException(ManifestException::class);
        (new ManifestValidator())->fromJson('{"id":');
    }
}
