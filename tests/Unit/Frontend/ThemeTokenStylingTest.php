<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The core imports this module's stylesheet in a layer below its own utilities, so light/dark colour
 * pairs lose in dark mode. Only theme tokens (values that flip through CSS variables) are allowed.
 */
final class ThemeTokenStylingTest extends TestCase
{
    private const array FORBIDDEN = [
        'dark: variant' => '/(?<![\w-])(?:[\w-]+:)*dark:[\w\/\[\]\.%-]+/',
        'neutral palette colour' => '/(?<![\w-])(?:[\w-]+:)*(?:bg|text|border|divide|ring|ring-offset|from|to|via)-(?:gray|stone|neutral|slate|zinc)-\d+/',
        'bg-white without opacity' => '/(?<![\w-])(?:[\w-]+:)*bg-white(?![\w\/-])/',
        // Fixed hues ignore the site palette and the dark theme; status colours have tokens (error, info…)
        'fixed hue palette colour' => '/(?<![\w-])(?:[\w-]+:)*(?:bg|text|border|divide|ring|ring-offset|from|to|via)-(?:red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d+/',
    ];

    public function test_no_vue_or_ts_file_uses_dark_pairs_or_neutral_palette_colours(): void
    {
        $root = dirname(__DIR__, 3).'/resources/js';
        $this->assertDirectoryExists($root);

        $violations = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! in_array($file->getExtension(), ['vue', 'ts'], true)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            $relative = substr($file->getPathname(), strlen($root) + 1);

            foreach (self::FORBIDDEN as $label => $pattern) {
                if (preg_match_all($pattern, $contents, $matches) > 0) {
                    foreach (array_unique($matches[0]) as $class) {
                        $violations[] = "{$relative}: {$class} ({$label})";
                    }
                }
            }
        }

        sort($violations);
        $this->assertSame([], $violations, "Use theme tokens instead:\n".implode("\n", $violations));
    }
}
