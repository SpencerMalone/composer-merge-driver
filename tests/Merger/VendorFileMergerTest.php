<?php

declare(strict_types=1);

namespace ComposerMergeDriver\Tests\Merger;

use ComposerMergeDriver\FileType;
use ComposerMergeDriver\MergeContext;
use ComposerMergeDriver\Merger\VendorFileMerger;
use ComposerMergeDriver\Support\ComposerLibraryInterface;
use ComposerMergeDriver\Exception\MergeException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VendorFileMerger.
 *
 * These tests verify the three branching outcomes without needing a real project:
 *  - noResolve: true  → always returns false (skips library entirely)
 *  - noResolve: false, dumpAutoload succeeds → returns true
 *  - noResolve: false, dumpAutoload throws   → returns false
 */
final class VendorFileMergerTest extends TestCase
{
    private string $tmpDir = '';
    private string $oursPath = '';

    protected function setUp(): void
    {
        $this->tmpDir  = sys_get_temp_dir() . '/cmd-vendor-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);

        // Write a dummy "ours" file — VendorFileMerger does not read its content.
        $this->oursPath = $this->tmpDir . '/autoload_psr4.php';
        file_put_contents($this->oursPath, '<?php return [];');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->oursPath)) {
            unlink($this->oursPath);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function makeContext(bool $noResolve): MergeContext
    {
        return new MergeContext(
            basePath:   $this->oursPath,
            oursPath:   $this->oursPath,
            theirsPath: $this->oursPath,
            markerSize: 7,
            pathname:   'vendor/composer/autoload_psr4.php',
            fileType:   FileType::VendorComposer,
            workingDir: $this->tmpDir,
            noResolve:  $noResolve,
        );
    }

    // ─── Tests ───────────────────────────────────────────────────────────────

    #[Test]
    public function noResolveReturnsFalseWithoutCallingLibrary(): void
    {
        // With noResolve=true the merger must not call dumpAutoload at all and
        // must signal a conflict (false) so git marks the file as needing attention.
        $library = $this->createMock(ComposerLibraryInterface::class);
        $library->expects(self::never())->method('dumpAutoload');
        $library->expects(self::never())->method('isAvailable');

        $merger = new VendorFileMerger($library);
        $result = $merger->merge($this->makeContext(noResolve: true));

        self::assertFalse($result);
    }

    #[Test]
    public function successfulDumpAutoloadReturnsTrue(): void
    {
        $library = $this->createMock(ComposerLibraryInterface::class);
        $library->method('isAvailable')->willReturn(true);
        $library->method('dumpAutoload')->willReturn(true);

        $merger = new VendorFileMerger($library);
        $result = $merger->merge($this->makeContext(noResolve: false));

        self::assertTrue($result);
    }

    #[Test]
    public function failedDumpAutoloadReturnsFalse(): void
    {
        $library = $this->createMock(ComposerLibraryInterface::class);
        $library->method('isAvailable')->willReturn(true);
        $library->method('dumpAutoload')->willReturn(false);

        $merger = new VendorFileMerger($library);
        $result = $merger->merge($this->makeContext(noResolve: false));

        self::assertFalse($result);
    }

    #[Test]
    public function dumpAutoloadExceptionReturnsFalse(): void
    {
        $library = $this->createMock(ComposerLibraryInterface::class);
        $library->method('isAvailable')->willReturn(true);
        $library->method('dumpAutoload')->willThrowException(
            new MergeException('Composer dump-autoload failed: something went wrong')
        );

        $merger = new VendorFileMerger($library);
        $result = $merger->merge($this->makeContext(noResolve: false));

        self::assertFalse($result);
    }

    #[Test]
    public function unavailableLibraryReturnsFalse(): void
    {
        $library = $this->createMock(ComposerLibraryInterface::class);
        $library->method('isAvailable')->willReturn(false);
        $library->expects(self::never())->method('dumpAutoload');

        $merger = new VendorFileMerger($library);
        $result = $merger->merge($this->makeContext(noResolve: false));

        self::assertFalse($result);
    }
}
