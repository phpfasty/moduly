<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The core must stay CMS-agnostic: no WordPress function calls in src/.
 */
final class NoWordPressInSrcTest extends TestCase
{
    private const FORBIDDEN_PREFIXES = ['wp_', 'is_wp_', 'esc_', 'sanitize_', 'register_', '_e', '__'];

    private const FORBIDDEN_FUNCTIONS = [
        'add_action', 'add_filter', 'do_action', 'apply_filters', 'remove_action', 'remove_filter',
        'get_option', 'update_option', 'add_option', 'delete_option',
        'get_post_meta', 'update_post_meta', 'add_post_meta', 'delete_post_meta',
        'get_transient', 'set_transient', 'current_user_can', 'check_admin_referer',
        'get_post', 'get_posts', 'home_url', 'site_url', 'plugin_dir_path', 'plugins_url',
    ];

    public function testSrcHasNoWordPressFunctionCalls(): void
    {
        $violations = [];
        foreach ($this->phpFiles(dirname(__DIR__, 2) . '/src') as $file) {
            $code = file_get_contents($file);
            self::assertIsString($code);
            foreach ($this->calledFunctions($code) as [$name, $line]) {
                if ($this->isForbidden($name)) {
                    $violations[] = sprintf('%s:%d %s()', $file, $line, $name);
                }
            }
        }
        self::assertSame([], $violations, "WordPress calls in core src/:\n" . implode("\n", $violations));
    }

    public function testDetectorCatchesWordPressCalls(): void
    {
        $names = array_column($this->calledFunctions('<?php add_action("init", "f"); \wp_die(); $o->get_option();'), 0);
        self::assertSame(['add_action', 'wp_die'], array_values(array_filter($names, $this->isForbidden(...))));
    }

    private function isForbidden(string $name): bool
    {
        $name = strtolower(ltrim($name, '\\'));
        if (in_array($name, self::FORBIDDEN_FUNCTIONS, true)) {
            return true;
        }
        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Global function calls (not methods, not declarations) found via tokenizer.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function calledFunctions(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $calls = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            if (($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }
            $prev = $tokens[$i - 1] ?? null;
            if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                continue;
            }
            $calls[] = [ltrim($token[1], '\\'), $token[2]];
        }
        return $calls;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
            if ($f instanceof \SplFileInfo && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        return $files;
    }
}
