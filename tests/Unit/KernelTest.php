<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Tests\Unit;

use PhpFasty\Moduly\Exception\ModulyException;
use PhpFasty\Moduly\Kernel\Kernel;
use PhpFasty\Moduly\Kernel\ModuleRecord;
use PhpFasty\Moduly\Manifest\DependencyResolver;
use PhpFasty\Moduly\Moduly;
use PhpFasty\Moduly\Package\Installer;
use PhpFasty\Moduly\Signature\Keyring;
use PhpFasty\Moduly\Signature\Verifier;
use PhpFasty\Moduly\Tests\Support\FakeHost;
use PhpFasty\Moduly\Tests\Support\Fixture;
use PhpFasty\Moduly\Tests\Support\MemoryState;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    private Fixture $fx;
    private MemoryState $state;
    private FakeHost $host;

    protected function setUp(): void
    {
        $this->fx = new Fixture();
        $this->state = new MemoryState();
        $this->host = new FakeHost();
    }

    protected function tearDown(): void
    {
        $this->fx->cleanup();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function install(string $id, array $overrides = [], string $body = '', string $status = ModuleRecord::ENABLED): void
    {
        $dir = $this->fx->module($id, $overrides, [], $body);
        $this->fx->sign($dir, null);
        $installer = new Installer(
            $this->fx->root . '/modules',
            $this->fx->root . '/q',
            new Verifier(new Keyring([], [], true)),
            new DependencyResolver(Moduly::VERSION),
        );
        $r = $installer->install($this->fx->zip($dir), array_map(
            fn (ModuleRecord $rec) => $this->kernel()->manifest($rec->id, $rec->version),
            $this->state->records
        ));
        $this->state->records[$id] = new ModuleRecord($id, (string) $r->manifest->version, $status);
    }

    private function kernel(): Kernel
    {
        return new Kernel($this->fx->root . '/modules', $this->state, $this->host, new DependencyResolver(Moduly::VERSION, PHP_VERSION, ['test' => '1.0.0']));
    }

    public function testBootsInDependencyOrderAndRegistersHooks(): void
    {
        $this->install('k-base', [], '$context->container->singleton("base.svc", static fn () => 42);');
        $this->install(
            'k-top',
            ['requires' => ['modules' => ['k-base' => '^1.0']], 'hooks' => [['type' => 'filter', 'name' => 'the_title', 'method' => 'ping', 'priority' => 5, 'args' => 1]]],
            '$context->container->singleton("top.svc", static fn ($c) => $c->get("base.svc") + 1);'
        );
        $k = $this->kernel();
        $r = $k->boot();
        self::assertSame(['k-base', 'k-top'], $r['booted']);
        self::assertSame([], $r['failed']);
        self::assertSame(43, $k->container()->get('top.svc'));
        self::assertSame([['filter', 'the_title', 5]], $this->host->hooks);
    }

    public function testThrowingModuleIsMarkedFailedOthersBoot(): void
    {
        $this->install('k-ok');
        $this->install('k-bad', [], 'throw new \\RuntimeException("boom in /var/www/secret/path.php");');
        $r = $this->kernel()->boot();
        self::assertSame(['k-ok'], $r['booted']);
        self::assertArrayHasKey('k-bad', $r['failed']);
        self::assertSame(ModuleRecord::FAILED, $this->state->records['k-bad']->status);
        self::assertStringNotContainsString('/var/www', $this->state->records['k-bad']->error, 'no paths leak');
        // Next request: failed module is not booted again.
        self::assertSame(['k-ok'], $this->kernel()->boot()['booted']);
    }

    public function testDependentOfFailedModuleIsSkipped(): void
    {
        $this->install('k-dep-base', [], 'throw new \\RuntimeException("x");');
        $this->install('k-dep-top', ['requires' => ['modules' => ['k-dep-base' => '^1.0']]]);
        $r = $this->kernel()->boot();
        self::assertSame([], $r['booted']);
        self::assertSame('dependency "k-dep-base" failed', $r['failed']['k-dep-top']);
        self::assertSame(ModuleRecord::FAILED, $this->state->records['k-dep-base']->status);
    }

    public function testSafeModeBootsNothing(): void
    {
        $this->install('k-safe');
        self::assertSame(['booted' => [], 'failed' => []], $this->kernel()->boot(true));
    }

    public function testEnableRunsActivateAndDisableGuardsDependents(): void
    {
        $this->install('k-en', [], '', ModuleRecord::INSTALLED);
        $k = $this->kernel();
        self::assertSame([], $k->boot()['booted']);
        $k->enable('k-en');
        self::assertSame(ModuleRecord::ENABLED, $this->state->records['k-en']->status);
        $class = 'Acme\\ModKEn\\Module';
        self::assertTrue(class_exists($class, false));
        $calls = [$class, 'calls'];
        self::assertIsCallable($calls);
        self::assertSame(['boot', 'activate'], $calls());

        $this->install('k-en-top', ['requires' => ['modules' => ['k-en' => '^1.0']]]);
        $k2 = $this->kernel();
        $k2->boot();
        try {
            $k2->disable('k-en');
            self::fail('disabled a required module');
        } catch (ModulyException $e) {
            self::assertSame('required_by', $e->reason);
        }
        $k2->disable('k-en-top');
        $k2->disable('k-en');
        self::assertSame(ModuleRecord::INSTALLED, $this->state->records['k-en']->status);
    }

    public function testScopedContainerDeniesUndeclaredDependency(): void
    {
        $this->install('k-own', [], '$context->container->singleton("own.svc", static fn () => 1);');
        $this->install('k-thief', [], '$context->container->get("own.svc");');
        $r = $this->kernel()->boot();
        self::assertSame(['k-own'], $r['booted']);
        self::assertStringContainsString('must require', $r['failed']['k-thief']);
    }

    public function testModuleForFile(): void
    {
        $k = $this->kernel();
        self::assertSame('cart', $k->moduleForFile($this->fx->root . '/modules/cart/1.0.0/src/Cart.php'));
        self::assertNull($k->moduleForFile('/elsewhere/cart/src/Cart.php'));
    }
}
