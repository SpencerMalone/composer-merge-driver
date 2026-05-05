<?php

declare(strict_types=1);

namespace ComposerMergeDriver\Tests\Merger;

use ComposerMergeDriver\FileType;
use ComposerMergeDriver\MergeContext;
use ComposerMergeDriver\Merger\ComposerLockMerger;
use ComposerMergeDriver\Support\ComposerLibrary;
use ComposerMergeDriver\Support\JsonHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests that exercise the real Composer library re-resolution path.
 *
 * These tests create a minimal local Composer project using path repositories
 * (no network access required) and verify that ComposerLockMerger can resolve
 * conflicts by calling Composer\Installer directly.
 */
final class ComposerLockMergerIntegrationTest extends TestCase
{
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'cmd-integration-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpRoot);
    }

    // ─── Tests ───────────────────────────────────────────────────────────────

    #[Test]
    public function conflictingPackageVersionsAreResolvedViaComposerLibrary(): void
    {
        // Set up a local path-repository package and a project that requires it.
        $pkgDir     = $this->createPathPackage('test/my-lib', '1.0.0');
        $projectDir = $this->createProject($pkgDir, 'test/my-lib', '*');

        // Manufacture a real three-way conflict: both sides diverged from base.
        // The package only exists at v1.0.0 locally, so Composer resolves it to 1.0.0.
        $base   = $this->makeLockData([['name' => 'test/my-lib', 'version' => '1.0.0']]);
        $ours   = $this->makeLockData([['name' => 'test/my-lib', 'version' => '1.1.0']]);
        $theirs = $this->makeLockData([['name' => 'test/my-lib', 'version' => '2.0.0']]);

        [$basePath, $oursPath, $theirsPath] = $this->writeLockFiles($base, $ours, $theirs);

        $context = new MergeContext(
            basePath:   $basePath,
            oursPath:   $oursPath,
            theirsPath: $theirsPath,
            markerSize: 7,
            pathname:   'composer.lock',
            fileType:   FileType::ComposerLock,
            workingDir: $projectDir,
            noResolve:  false,
        );

        $merger = new ComposerLockMerger(new ComposerLibrary());
        $clean  = $merger->merge($context);

        self::assertTrue($clean, 'Expected a clean merge after re-resolution');

        $result = JsonHelper::readFile($oursPath);
        self::assertArrayHasKey('content-hash', $result);
        self::assertArrayHasKey('packages', $result);
        self::assertNotEmpty($result['packages'], 'Resolved lock should contain the package');
        self::assertSame('test/my-lib', $result['packages'][0]['name']);
    }

    #[Test]
    public function cleanMergeSkipsResolutionAndReturnsTrue(): void
    {
        $pkgDir     = $this->createPathPackage('test/utils', '1.0.0');
        $projectDir = $this->createProject($pkgDir, 'test/utils', '*');

        // No conflict — all three sides agree on the same version.
        $lock = $this->makeLockData([['name' => 'test/utils', 'version' => '1.0.0']]);
        [$basePath, $oursPath, $theirsPath] = $this->writeLockFiles($lock, $lock, $lock);

        $context = new MergeContext(
            basePath:   $basePath,
            oursPath:   $oursPath,
            theirsPath: $theirsPath,
            markerSize: 7,
            pathname:   'composer.lock',
            fileType:   FileType::ComposerLock,
            workingDir: $projectDir,
            noResolve:  false,
        );

        $merger = new ComposerLockMerger(new ComposerLibrary());
        $clean  = $merger->merge($context);

        self::assertTrue($clean);

        // Composer update should NOT have run — the result is written from the merge,
        // not from a fresh install, so vendor/ won't exist in projectDir.
        self::assertDirectoryDoesNotExist($projectDir . DIRECTORY_SEPARATOR . 'vendor');
    }

    #[Test]
    public function resolutionIsSkippedWhenNoResolveIsTrue(): void
    {
        $pkgDir     = $this->createPathPackage('test/pkg', '1.0.0');
        $projectDir = $this->createProject($pkgDir, 'test/pkg', '*');

        // Both sides diverged from base — a real three-way conflict.
        $base   = $this->makeLockData([['name' => 'test/pkg', 'version' => '1.0.0']]);
        $ours   = $this->makeLockData([['name' => 'test/pkg', 'version' => '1.1.0']]);
        $theirs = $this->makeLockData([['name' => 'test/pkg', 'version' => '2.0.0']]);

        [$basePath, $oursPath, $theirsPath] = $this->writeLockFiles($base, $ours, $theirs);

        $context = new MergeContext(
            basePath:   $basePath,
            oursPath:   $oursPath,
            theirsPath: $theirsPath,
            markerSize: 7,
            pathname:   'composer.lock',
            fileType:   FileType::ComposerLock,
            workingDir: $projectDir,
            noResolve:  true,  // <-- skip resolution
        );

        $merger = new ComposerLockMerger(new ComposerLibrary());
        $clean  = $merger->merge($context);

        // Conflict flagged, no resolution attempted.
        self::assertFalse($clean);

        $result = JsonHelper::readFile($oursPath);
        // Best-effort result keeps ours version.
        self::assertSame('1.1.0', $result['packages'][0]['version']);

        // No vendor dir — Composer was never invoked.
        self::assertDirectoryDoesNotExist($projectDir . DIRECTORY_SEPARATOR . 'vendor');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Create a minimal local package directory usable as a Composer path repository.
     */
    private function createPathPackage(string $name, string $version): string
    {
        $dir = $this->tmpRoot . DIRECTORY_SEPARATOR . str_replace('/', '-', $name);
        mkdir($dir, 0700, true);
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'composer.json',
            json_encode([
                'name'        => $name,
                'version'     => $version,
                'description' => 'Integration test fixture',
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
        return $dir;
    }

    /**
     * Create a minimal project directory that requires $packageName from a path repo.
     */
    private function createProject(string $pkgDir, string $packageName, string $constraint): string
    {
        $dir = $this->tmpRoot . DIRECTORY_SEPARATOR . 'project';
        mkdir($dir, 0700, true);
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'composer.json',
            json_encode([
                'name'         => 'test/project',
                'require'      => [$packageName => $constraint],
                'repositories' => [
                    ['type' => 'path', 'url' => $pkgDir, 'options' => ['symlink' => false]],
                ],
                'config'       => ['preferred-install' => 'dist'],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
        return $dir;
    }

    /**
     * Build a minimal lock file data array.
     *
     * @param list<array<string, mixed>> $packages
     * @return array<string, mixed>
     */
    private function makeLockData(array $packages = []): array
    {
        return [
            '_readme'            => ['Generated by Composer'],
            'content-hash'       => 'placeholder',
            'packages'           => $packages,
            'packages-dev'       => [],
            'aliases'            => [],
            'minimum-stability'  => 'stable',
            'stability-flags'    => [],
            'prefer-stable'      => false,
            'prefer-lowest'      => false,
            'platform'           => [],
            'platform-dev'       => [],
            'plugin-api-version' => '2.3.0',
        ];
    }

    /**
     * Write three lock data arrays to temp files and return their paths.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $ours
     * @param array<string, mixed> $theirs
     * @return array{0: string, 1: string, 2: string}
     */
    private function writeLockFiles(array $base, array $ours, array $theirs): array
    {
        $write = function (string $name, array $data): string {
            $path = $this->tmpRoot . DIRECTORY_SEPARATOR . $name;
            file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return $path;
        };

        return [
            $write('base.lock', $base),
            $write('ours.lock', $ours),
            $write('theirs.lock', $theirs),
        ];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
            }
        }
        rmdir($path);
    }
}
