<?php

declare(strict_types=1);

namespace ComposerMergeDriver\Tests\Merger;

use ComposerMergeDriver\FileType;
use ComposerMergeDriver\MergeContext;
use ComposerMergeDriver\Merger\ComposerJsonMerger;
use ComposerMergeDriver\Support\ComposerLibrary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for ComposerJsonMerger validation.
 *
 * These tests call the real ConfigValidator against temp files — no network
 * access needed.
 */
final class ComposerJsonMergerIntegrationTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cmd-jsonmerge-int-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
    }

    // ─── Tests ───────────────────────────────────────────────────────────────

    #[Test]
    public function validMergedComposerJsonPassesValidation(): void
    {
        $data = ['name' => 'test/project', 'require' => ['php' => '^8.1']];
        $ctx  = $this->makeContext($data, $data, $data);

        $merger = new ComposerJsonMerger(new ComposerLibrary());
        $clean  = $merger->merge($ctx);

        self::assertTrue($clean);
    }

    #[Test]
    public function invalidMergedComposerJsonFailsValidation(): void
    {
        // "name" must match vendor/package — this will fail schema validation.
        $data = ['name' => 'not_a_valid_name', 'require' => ['php' => '^8.1']];
        $ctx  = $this->makeContext($data, $data, $data);

        $merger = new ComposerJsonMerger(new ComposerLibrary());
        $clean  = $merger->merge($ctx);

        self::assertFalse($clean);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $ours
     * @param array<string, mixed> $theirs
     */
    private function makeContext(array $base, array $ours, array $theirs): MergeContext
    {
        $write = function (string $name, array $data): string {
            $path = $this->tmpDir . '/' . $name;
            file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return $path;
        };

        return new MergeContext(
            basePath:   $write('base.json', $base),
            oursPath:   $write('ours.json', $ours),
            theirsPath: $write('theirs.json', $theirs),
            markerSize: 7,
            pathname:   'composer.json',
            fileType:   FileType::ComposerJson,
            workingDir: $this->tmpDir,
            noResolve:  false,
        );
    }
}
