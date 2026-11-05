<?php

declare(strict_types=1);

namespace FraudNova\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class LeakGuardTest extends TestCase
{
    /**
     * @return list<string> "relative/path:line" for every line naming the upstream provider
     */
    public static function scan(string $root): array
    {
        $forbidden = ['ip'.'quality'.'score', 'ip'.'qs'];
        $skipDirs = ['vendor', '.git', 'node_modules', '.phpunit.cache'];
        $hits = [];

        $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            static fn (SplFileInfo $file): bool => ! ($file->isDir() && in_array($file->getFilename(), $skipDirs, true)),
        );

        foreach (new RecursiveIteratorIterator($filter) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || str_ends_with($file->getFilename(), '.lock')) {
                continue;
            }

            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $n => $line) {
                foreach ($forbidden as $needle) {
                    if (stripos($line, $needle) !== false) {
                        $hits[] = substr($file->getPathname(), strlen($root) + 1).':'.($n + 1);
                    }
                }
            }
        }

        return $hits;
    }

    public function test_repository_never_names_the_upstream_provider(): void
    {
        self::assertSame([], self::scan(dirname(__DIR__)), 'Forbidden upstream references found');
    }

    public function test_scanner_detects_a_forbidden_reference(): void
    {
        $dir = sys_get_temp_dir().'/fn-leak-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/sample.txt', "clean\nuses ".'ip'.'qs'." here\n");

        try {
            self::assertSame(['sample.txt:2'], self::scan($dir));
        } finally {
            unlink($dir.'/sample.txt');
            rmdir($dir);
        }
    }
}
