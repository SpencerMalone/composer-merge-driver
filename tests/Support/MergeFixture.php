<?php

declare(strict_types=1);

namespace ComposerMergeDriver\Tests\Support;

use ComposerMergeDriver\FileType;
use ComposerMergeDriver\MergeContext;

/**
 * Test helper that writes three JSON fixtures to temp files and builds a MergeContext.
 * Call cleanup() (or let tearDown handle it) to remove temp files.
 */
final class MergeFixture
{
    private string $tmpDir;

    /** @var list<string> */
    private array $tempFiles = [];

    public function __construct()
    {
        $dir = sys_get_temp_dir() . '/composer-merge-driver-tests-' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0700, true)) {
            throw new \RuntimeException("Cannot create temp dir: {$dir}");
        }
        $this->tmpDir = $dir;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $ours
     * @param array<string, mixed> $theirs
     */
    public function make(
        array $base,
        array $ours,
        array $theirs,
        string $pathname = 'composer.json',
        string $workingDir = '',
    ): MergeContext {
        $basePath   = $this->writeJson('base.json', $base);
        $oursPath   = $this->writeJson('ours.json', $ours);
        $theirsPath = $this->writeJson('theirs.json', $theirs);

        return new MergeContext(
            basePath: $basePath,
            oursPath: $oursPath,
            theirsPath: $theirsPath,
            markerSize: 7,
            pathname: $pathname,
            fileType: FileType::detectFromPathname($pathname),
            workingDir: $workingDir !== '' ? $workingDir : $this->tmpDir,
            noResolve: true,
        );
    }

    /**
     * Read the "ours" file (the merge result) as a decoded array.
     *
     * @return array<string, mixed>
     */
    public function result(MergeContext $context): array
    {
        $contents = file_get_contents($context->oursPath);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read result file: {$context->oursPath}");
        }
        $data = json_decode($contents, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Result is not a JSON object");
        }
        /** @var array<string, mixed> $data */
        return $data;
    }

    public function cleanup(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $name, array $data): string
    {
        $path     = $this->tmpDir . DIRECTORY_SEPARATOR . $name;
        $contents = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
        return $path;
    }
}
