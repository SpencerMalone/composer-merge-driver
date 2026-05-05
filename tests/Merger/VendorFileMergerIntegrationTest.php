<?php

declare(strict_types=1);

namespace ComposerMergeDriver\Tests\Merger;

use ComposerMergeDriver\FileType;
use ComposerMergeDriver\MergeContext;
use ComposerMergeDriver\Merger\VendorFileMerger;
use ComposerMergeDriver\Support\ComposerLibrary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for VendorFileMerger that exercise the real
 * AutoloadGenerator path against a local Composer project.
 *
 * No network access is required — a path repository is used.
 */
final class VendorFileMergerIntegrationTest extends TestCase
{
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'cmd-vendor-int-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpRoot);
    }

    // ─── Tests ───────────────────────────────────────────────────────────────

    #[Test]
    public function dumpAutoloadRegeneratesVendorFilesAndReturnsTrue(): void
    {
        $projectDir = $this->createInstalledProject();

        // Corrupt the autoload map to confirm regeneration actually rewrites it.
        $autoloadPsr4 = $projectDir . '/vendor/composer/autoload_psr4.php';
        self::assertFileExists($autoloadPsr4);
        file_put_contents($autoloadPsr4, '<?php return ["CORRUPTED" => []];');

        $oursPath = $projectDir . '/vendor/composer/autoload_psr4.php';

        $context = new MergeContext(
            basePath:   $oursPath,
            oursPath:   $oursPath,
            theirsPath: $oursPath,
            markerSize: 7,
            pathname:   'vendor/composer/autoload_psr4.php',
            fileType:   FileType::VendorComposer,
            workingDir: $projectDir,
            noResolve:  false,
        );

        $merger = new VendorFileMerger(new ComposerLibrary());
        $clean  = $merger->merge($context);

        self::assertTrue($clean);

        // File should be regenerated — no longer contains our corruption marker.
        $regenerated = file_get_contents($autoloadPsr4);
        self::assertIsString($regenerated);
        self::assertStringNotContainsString('CORRUPTED', $regenerated);
    }

    #[Test]
    public function noResolveReturnsFalseWithoutTouchingFiles(): void
    {
        $projectDir = $this->createInstalledProject();

        $autoloadPsr4 = $projectDir . '/vendor/composer/autoload_psr4.php';
        $originalContent = file_get_contents($autoloadPsr4);

        $context = new MergeContext(
            basePath:   $autoloadPsr4,
            oursPath:   $autoloadPsr4,
            theirsPath: $autoloadPsr4,
            markerSize: 7,
            pathname:   'vendor/composer/autoload_psr4.php',
            fileType:   FileType::VendorComposer,
            workingDir: $projectDir,
            noResolve:  true,
        );

        $merger = new VendorFileMerger(new ComposerLibrary());
        $clean  = $merger->merge($context);

        self::assertFalse($clean);
        // File must be untouched.
        self::assertSame($originalContent, file_get_contents($autoloadPsr4));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Create a minimal Composer project with a path-repo package,
     * run `composer install` so vendor/ is populated, and return the project dir.
     */
    private function createInstalledProject(): string
    {
        // Local package fixture.
        $pkgDir = $this->tmpRoot . '/my-lib';
        mkdir($pkgDir, 0700, true);
        file_put_contents(
            $pkgDir . '/composer.json',
            json_encode([
                'name'        => 'test/my-lib',
                'version'     => '1.0.0',
                'description' => 'Integration test fixture',
                'autoload'    => ['psr-4' => ['MyLib\\' => 'src/']],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
        mkdir($pkgDir . '/src', 0700, true);

        // Project.
        $projectDir = $this->tmpRoot . '/project';
        mkdir($projectDir, 0700, true);
        file_put_contents(
            $projectDir . '/composer.json',
            json_encode([
                'name'         => 'test/project',
                'require'      => ['test/my-lib' => '*'],
                'repositories' => [
                    ['type' => 'path', 'url' => $pkgDir, 'options' => ['symlink' => false]],
                ],
                'config'       => ['preferred-install' => 'dist'],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );

        // Pre-create empty vendor stub files so Composer's local-repo loader does
        // not emit PHP warnings about missing files during createComposer().
        $vendorComposerDir = $projectDir . '/vendor/composer';
        mkdir($vendorComposerDir, 0700, true);
        foreach (['installed.php', 'InstalledVersions.php', 'autoload_namespaces.php',
                  'autoload_psr4.php', 'autoload_classmap.php', 'autoload_static.php',
                  'autoload_real.php'] as $stub) {
            file_put_contents($vendorComposerDir . '/' . $stub, '<?php return [];');
        }
        file_put_contents($projectDir . '/vendor/autoload.php', '<?php return require __DIR__ . \'/composer/autoload_real.php\';');

        // Use the Composer library itself to install, so no external binary needed.
        $io       = new \Composer\IO\NullIO();
        $composer = (new \Composer\Factory())->createComposer(
            $io,
            $projectDir . '/composer.json',
            true,
            $projectDir,
            true,
            true,
        );

        $installer = \Composer\Installer::create($io, $composer)
            ->setUpdate(true)
            ->setRunScripts(false)
            ->disablePlugins();

        $exitCode = $installer->run();
        self::assertSame(0, $exitCode, 'composer install failed during test setup');
        self::assertDirectoryExists($projectDir . '/vendor/composer');

        return $projectDir;
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
