<?php

declare(strict_types=1);

namespace FraudNova\Tests;

use PHPUnit\Framework\TestCase;

final class ReadmeTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function phpBlocks(): array
    {
        $readme = file_get_contents(dirname(__DIR__).'/README.md');
        self::assertIsString($readme);

        preg_match_all('/^```php\n(.*?)^```$/ms', $readme, $matches);

        return $matches[1];
    }

    public function test_readme_has_php_examples(): void
    {
        self::assertGreaterThanOrEqual(10, count(self::phpBlocks()));
    }

    public function test_every_php_block_lints(): void
    {
        foreach (self::phpBlocks() as $block) {
            $code = str_starts_with($block, '<?php') ? $block : "<?php\n".$block;
            $file = tempnam(sys_get_temp_dir(), 'fn-readme-');
            self::assertIsString($file);
            file_put_contents($file, $code);

            try {
                $process = proc_open(
                    [PHP_BINARY, '-l', $file],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                /** @var array<int, resource> $pipes */
                $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);
            } finally {
                unlink($file);
            }

            $firstLine = strtok($block, "\n");
            self::assertSame(0, $exitCode, sprintf("README block starting \"%s\" does not lint:\n%s", $firstLine, $output));
        }
    }

    public function test_every_named_fraudnova_class_exists(): void
    {
        $names = [];

        foreach (self::phpBlocks() as $block) {
            preg_match_all('/^use\s+(FraudNova\\\\[\w\\\\]+)\s*;/m', $block, $uses);
            preg_match_all('/(?<![\w\\\\])\\\\?(FraudNova(?:\\\\\w+)+)/', $block, $inline);

            foreach ([...$uses[1], ...$inline[1]] as $name) {
                $names[$name] = true;
            }
        }

        self::assertNotSame([], $names);

        foreach (array_keys($names) as $name) {
            self::assertTrue(
                class_exists($name) || interface_exists($name) || trait_exists($name),
                sprintf('README names %s, which does not exist', $name),
            );
        }
    }
}
