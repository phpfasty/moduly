<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Tests\Unit;

use PhpFasty\Moduly\Manifest\DependencyResolver;
use PhpFasty\Moduly\Manifest\ManifestValidator;
use PhpFasty\Moduly\Scan\StaticScanner;
use PHPUnit\Framework\TestCase;

final class ScannerTest extends TestCase
{
    public function testFlagsDangerousConstructs(): void
    {
        $code = <<<'PHP'
        <?php
        eval($x);
        shell_exec('ls');
        $a = `ls`;
        include $file;
        array_map('exec', $list);
        $y = $_GET['a'];
        $obj->exec();
        Foo::system();
        echo __('System settings');
        PHP;
        $msgs = array_column((new StaticScanner())->scanCode($code), 1);
        self::assertContains('eval()', $msgs);
        self::assertContains('shell_exec()', $msgs);
        self::assertContains('backtick shell execution', $msgs);
        self::assertContains('include/require (modules are autoloaded by the core)', $msgs);
        self::assertContains("callable string 'exec'", $msgs);
        self::assertContains('direct $_GET access', $msgs);
        self::assertCount(6, $msgs, implode(' | ', $msgs));
    }

    public function testRequestHandlerMarkerAllowsSuperglobals(): void
    {
        $code = "<?php\n/** @moduly-request-handler */\n\$id = (int) \$_POST['id'];\n";
        self::assertSame([], (new StaticScanner())->scanCode($code));
    }

    public function testResolverOrdersAndDetectsCycles(): void
    {
        $v = new ManifestValidator();
        $mk = static fn (string $id, array $mods) => $v->fromArray([
            'id' => $id, 'name' => $id, 'version' => '1.0.0', 'publisher' => 'acme', 'license' => 'MIT',
            'entry' => 'Acme\\X\\Module', 'autoload' => ['psr-4' => ['Acme\\X\\' => 'src/']],
            'requires' => ['modules' => $mods],
        ]);
        $r = (new DependencyResolver('0.1.0'))->order([
            'orders' => $mk('orders', ['cart' => '^1.0', 'types' => '^1.0']),
            'cart' => $mk('cart', ['types' => '^1.0']),
            'types' => $mk('types', []),
            'aa' => $mk('aa', ['bb' => '*']),
            'bb' => $mk('bb', ['aa' => '*']),
            'lonely' => $mk('lonely', ['missing' => '^1.0']),
        ]);
        self::assertSame(['types', 'cart', 'orders'], $r['order']);
        self::assertSame('dependency cycle', $r['skipped']['aa']);
        self::assertSame('dependency cycle', $r['skipped']['bb']);
        self::assertStringContainsString('requires module "missing"', $r['skipped']['lonely']);
    }
}
