<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Tests\Unit;

use PhpFasty\Moduly\Version\Constraint;
use PhpFasty\Moduly\Version\Semver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function testSemverOrdering(): void
    {
        $order = ['1.0.0-alpha', '1.0.0-alpha.1', '1.0.0-alpha.beta', '1.0.0-beta', '1.0.0-beta.2', '1.0.0-beta.11', '1.0.0-rc.1', '1.0.0', '1.0.1', '1.1.0', '2.0.0'];
        for ($i = 1; $i < count($order); $i++) {
            self::assertSame(-1, Semver::parse($order[$i - 1])->compare(Semver::parse($order[$i])), $order[$i - 1] . ' < ' . $order[$i]);
            self::assertSame(1, Semver::parse($order[$i])->compare(Semver::parse($order[$i - 1])));
        }
        self::assertFalse(Semver::isValid('1.0'));
        self::assertFalse(Semver::isValid('01.0.0'));
        self::assertSame('8.4.23', (string) Semver::coerce('8.4.23'));
        self::assertSame('6.8.0', (string) Semver::coerce('6.8'));
    }

    /** @return list<array{string, string, bool}> */
    public static function constraints(): array
    {
        return [
            ['^1.2', '1.9.9', true],
            ['^1.2', '2.0.0', false],
            ['^1.2', '1.1.0', false],
            ['^0.1', '0.1.5', true],
            ['^0.1', '0.2.0', false],
            ['~1.2.3', '1.2.9', true],
            ['~1.2.3', '1.3.0', false],
            ['>=8.2', '8.4.23', true],
            ['>=8.2', '8.1.0', false],
            ['>=6.4 <8', '7.1.3', true],
            ['>=6.4, <7', '7.1.3', false],
            ['1.2.*', '1.2.7', true],
            ['1.2.*', '1.3.0', false],
            ['^1 || ^2', '2.5.0', true],
            ['*', '0.0.1', true],
            ['1.0.0', '1.0.0', true],
            ['^1.0', '2.0.0-rc.1', false],
        ];
    }

    #[DataProvider('constraints')]
    public function testConstraint(string $c, string $v, bool $expected): void
    {
        self::assertSame($expected, Constraint::parse($c)->allows(Semver::coerce($v)));
    }

    public function testInvalidConstraints(): void
    {
        foreach (['', 'abc', '>>1', '^', '1.2.3.4'] as $bad) {
            self::assertFalse(Constraint::isValid($bad), $bad);
        }
    }
}
