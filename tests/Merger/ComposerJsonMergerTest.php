<?php

declare(strict_types=1);

namespace ComposerMergeDriver\Tests\Merger;

use ComposerMergeDriver\Merger\ComposerJsonMerger;
use ComposerMergeDriver\Support\ComposerLibraryInterface;
use ComposerMergeDriver\Tests\Support\MergeFixture;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComposerJsonMergerTest extends TestCase
{
    private MergeFixture $fixture;
    private ComposerJsonMerger $merger;

    protected function setUp(): void
    {
        $this->fixture = new MergeFixture();
        // noResolve=true in MergeFixture, so validate() is never called.
        $library = $this->createMock(ComposerLibraryInterface::class);
        $library->expects(self::never())->method('validate');
        $this->merger  = new ComposerJsonMerger($library);
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    // ─── require / require-dev ───────────────────────────────────────────────

    #[Test]
    public function bothSidesAddDifferentPackages(): void
    {
        $base = ['require' => ['php' => '^8.1', 'existing/pkg' => '^1.0']];
        $ours = ['require' => ['php' => '^8.1', 'existing/pkg' => '^1.0', 'ours/pkg' => '^2.0']];
        $theirs = ['require' => ['php' => '^8.1', 'existing/pkg' => '^1.0', 'theirs/pkg' => '^3.0']];

        $ctx    = $this->fixture->make($base, $ours, $theirs);
        $clean  = $this->merger->merge($ctx);
        $result = $this->fixture->result($ctx);

        self::assertTrue($clean);
        self::assertSame('^2.0', $result['require']['ours/pkg'] ?? null);
        self::assertSame('^3.0', $result['require']['theirs/pkg'] ?? null);
        self::assertSame('^1.0', $result['require']['existing/pkg'] ?? null);
    }

    #[Test]
    public function onlyOursChangedAPackageVersion(): void
    {
        $base   = ['require' => ['vendor/pkg' => '^1.0']];
        $ours   = ['require' => ['vendor/pkg' => '^2.0']];
        $theirs = ['require' => ['vendor/pkg' => '^1.0']];

        $ctx    = $this->fixture->make($base, $ours, $theirs);
        $clean  = $this->merger->merge($ctx);
        $result = $this->fixture->result($ctx);

        self::assertTrue($clean);
        self::assertSame('^2.0', $result['require']['vendor/pkg'] ?? null);
    }

    #[Test]
    public function onlyTheirsChangedAPackageVersion(): void
    {
        $base   = ['require' => ['vendor/pkg' => '^1.0']];
        $ours   = ['require' => ['vendor/pkg' => '^1.0']];
        $theirs = ['require' => ['vendor/pkg' => '^1.5']];

        $ctx    = $this->fixture->make($base, $ours, $theirs);
        $clean  = $this->merger->merge($ctx);
        $result = $this->fixture->result($ctx);

        self::assertTrue($clean);
        self::assertSame('^1.5', $result['require']['vendor/pkg'] ?? null);
    }

    #[Test]
    public function bothSidesChangedSamePackageToDifferentVersions(): void
    {
        $base   = ['require' => ['vendor/pkg' => '^1.0']];
        $ours   = ['require' => ['vendor/pkg' => '^2.0']];
        $theirs = ['require' => ['vendor/pkg' => '^3.0']];

        $ctx   = $this->fixture->make($base, $ours, $theirs);
        $clean = $this->merger->merge($ctx);

        self::assertFalse($clean, 'Conflicting version bumps should not be a clean merge');
        // Best-effort: ours wins
        $result = $this->fixture->result($ctx);
        self::assertSame('^2.0', $result['require']['vendor/pkg'] ?? null);
    }

    #[Test]
    public function bothSidesChangedSamePackageToSameVersion(): void
    {
        $base   = ['require' => ['vendor/pkg' => '^1.0']];
        $ours   = ['require' => ['vendor/pkg' => '^2.0']];
        $theirs = ['require' => ['vendor/pkg' => '^2.0']];

        $ctx    = $this->fixture->make($base, $ours, $theirs);
        $clean  = $this->merger->merge($ctx);
        $result = $this->fixture->result($ctx);

        self::assertTrue($clean);
        self::assertSame('^2.0', $result['require']['vendor/pkg'] ?? null);
    }

    #[Test]
    public function oursRemovedPackageThatTheirsDidNotTouch(): void
    {
        $base   = ['require' => ['php' => '^8.1', 'vendor/old' => '^1.0']];
        $ours   = ['require' => ['php' => '^8.1']];
        $theirs = ['require' => ['php' => '^8.1', 'vendor/old' => '^1.0']];

        $ctx    = $this->fixture->make($base, $ours, $theirs);
        $clean  = $this->merger->merge($ctx);
        $result = $this->fixture->result($ctx);

        self::assertTrue($clean);
        self::assertArrayNotHasKey('vendor/old', $result['require'] ?? []);
    }

    #[Test]
    public function theirsRemovedPackageThatOursDidNotTouch(): void
    {
        $base   = ['require' => ['php' => '^8.1', 'vendor/old' => '^1.0']];
        $ours   = ['require' => ['php' => '^8.1', 'vendor/old' => '^1.0']];
        $theirs = ['require' => ['php' => '^8.1']];

        $ctx    = $this->fixture->make($base, $ours, $theirs);
        $clean  = $this->merger->merge($ctx);
        $result = $this->fixture->result($ctx);

        self::assertTrue($clean);
        self::assertArrayNotHasKey('vendor/old', $result['require'] ?? []);
    }

    #[Test]
    public function oursRemovedPackageThatTheirsUpdated(): void
    {
        $base   = ['require' => ['vendor/pkg' => '^1.0']];
        $ours   = ['require' => []];
        $theirs = ['require' => ['vendor/pkg' => '^2.0']];

        $ctx   = $this->fixture->make($base, $ours, $theirs);
        $clean = $this->merger->merge($ctx);

        // Conflict: we removed, they bumped the version.
        self::assertFalse($clean);
        // They changed it deliberately → keep theirs in best-effort output.
        $result = $this->fixture->result($ctx);
        self::assertSame('^2.0', $result['require']['vendor/pkg'] ?? null);
    }

    // ─── Top-level scalar fields ─────────────────────────────────────────────

    #[Test]
    public function noChangesProducesIdenticalOutput(): void
    {
        $data = ['name' => 'vendor/pkg', 'description' => 'desc', 'require' => ['php' => '^8.1']];

        $ctx    = $this->fixture->make($data, $data, $data);
        $clean  = $this->merger->merge($ctx);
        $result = $this->fixture->result($ctx);

        self::assertTrue($clean);
        self::assertSame('vendor/pkg', $result['name'] ?? null);
    }

    #[Test]
    public function onlyOursChangedDescription(): void
    {
        $base   = ['description' => 'old'];
        $ours   = ['description' => 'updated by us'];
        $theirs = ['description' => 'old'];

        $ctx    = $this->fixture->make($base, $ours, $theirs);
        $clean  = $this->merger->merge($ctx);
        $result = $this->fixture->result($ctx);

        self::assertTrue($clean);
        self::assertSame('updated by us', $result['description'] ?? null);
    }

    #[Test]
    public function onlyTheirsChangedDescription(): void
    {
        $base   = ['description' => 'old'];
        $ours   = ['description' => 'old'];
        $theirs = ['description' => 'updated by them'];

        $ctx    = $this->fixture->make($base, $ours, $theirs);
        $clean  = $this->merger->merge($ctx);
        $result = $this->fixture->result($ctx);

        self::assertTrue($clean);
        self::assertSame('updated by them', $result['description'] ?? null);
    }

    #[Test]
    public function oursAddedNewTopLevelKeyTheirsDidNot(): void
    {
        $base   = ['require' => []];
        $ours   = ['require' => [], 'minimum-stability' => 'dev'];
        $theirs = ['require' => []];

        $ctx    = $this->fixture->make($base, $ours, $theirs);
        $clean  = $this->merger->merge($ctx);
        $result = $this->fixture->result($ctx);

        self::assertTrue($clean);
        self::assertSame('dev', $result['minimum-stability'] ?? null);
    }

    #[Test]
    public function theirsAddedNewTopLevelKeyOursDidNot(): void
    {
        $base   = ['require' => []];
        $ours   = ['require' => []];
        $theirs = ['require' => [], 'minimum-stability' => 'stable'];

        $ctx    = $this->fixture->make($base, $ours, $theirs);
        $clean  = $this->merger->merge($ctx);
        $result = $this->fixture->result($ctx);

        self::assertTrue($clean);
        self::assertSame('stable', $result['minimum-stability'] ?? null);
    }

    // ─── autoload (deep merge) ───────────────────────────────────────────────

    #[Test]
    public function bothSidesAddDifferentAutoloadNamespaces(): void
    {
        $base = ['autoload' => ['psr-4' => ['App\\' => 'src/']]];
        $ours = ['autoload' => ['psr-4' => ['App\\' => 'src/', 'Admin\\' => 'admin/']]];
        $theirs = ['autoload' => ['psr-4' => ['App\\' => 'src/', 'Api\\' => 'api/']]];

        $ctx    = $this->fixture->make($base, $ours, $theirs);
        $clean  = $this->merger->merge($ctx);
        $result = $this->fixture->result($ctx);

        self::assertTrue($clean);
        $psr4 = $result['autoload']['psr-4'] ?? [];
        self::assertSame('admin/', $psr4['Admin\\'] ?? null);
        self::assertSame('api/', $psr4['Api\\'] ?? null);
        self::assertSame('src/', $psr4['App\\'] ?? null);
    }

    // ─── repositories ────────────────────────────────────────────────────────

    #[Test]
    public function repositoriesAreMergedByUrl(): void
    {
        $repoA = ['type' => 'vcs', 'url' => 'https://github.com/org/a'];
        $repoB = ['type' => 'vcs', 'url' => 'https://github.com/org/b'];

        $base   = ['repositories' => [$repoA]];
        $ours   = ['repositories' => [$repoA, $repoB]];
        $theirs = ['repositories' => [$repoA]];

        $ctx    = $this->fixture->make($base, $ours, $theirs);
        $clean  = $this->merger->merge($ctx);
        $result = $this->fixture->result($ctx);

        self::assertTrue($clean);
        self::assertCount(2, $result['repositories'] ?? []);
    }

    #[Test]
    public function repositoryRemovedInOursIsDropped(): void
    {
        $repoA = ['type' => 'vcs', 'url' => 'https://github.com/org/a'];

        $base   = ['repositories' => [$repoA]];
        $ours   = ['repositories' => []];
        $theirs = ['repositories' => [$repoA]];

        $ctx    = $this->fixture->make($base, $ours, $theirs);
        $clean  = $this->merger->merge($ctx);
        $result = $this->fixture->result($ctx);

        self::assertTrue($clean);
        self::assertCount(0, $result['repositories'] ?? ['x']);
    }

    // ─── validation ──────────────────────────────────────────────────────────

    #[Test]
    public function validationErrorsFromLibraryReturnFalse(): void
    {
        $library = $this->createMock(ComposerLibraryInterface::class);
        $library->method('validate')->willReturn(['require.vendor/pkg must be a string']);
        $merger = new ComposerJsonMerger($library);

        $base = $ours = $theirs = ['require' => ['php' => '^8.1']];
        $ctx  = $this->makeNoResolveOffContext($base, $ours, $theirs);

        $clean = $merger->merge($ctx);

        self::assertFalse($clean);
    }

    #[Test]
    public function validationIsSkippedWhenMergeHasConflicts(): void
    {
        // If there are merge conflicts, validate() must not be called at all.
        $library = $this->createMock(ComposerLibraryInterface::class);
        $library->expects(self::never())->method('validate');
        $merger = new ComposerJsonMerger($library);

        $base   = ['require' => ['vendor/pkg' => '^1.0']];
        $ours   = ['require' => ['vendor/pkg' => '^2.0']];
        $theirs = ['require' => ['vendor/pkg' => '^3.0']];
        $ctx    = $this->makeNoResolveOffContext($base, $ours, $theirs);

        $clean = $merger->merge($ctx);

        self::assertFalse($clean);
    }

    #[Test]
    public function validationIsSkippedWhenNoResolveIsTrue(): void
    {
        $library = $this->createMock(ComposerLibraryInterface::class);
        $library->expects(self::never())->method('validate');
        $merger = new ComposerJsonMerger($library);

        // noResolve=true via MergeFixture — clean merge, but validate() still not called.
        $base = $ours = $theirs = ['require' => ['php' => '^8.1']];
        $ctx  = $this->fixture->make($base, $ours, $theirs);

        $clean = $merger->merge($ctx);

        self::assertTrue($clean);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /**
     * Build a MergeContext with noResolve=false, writing fixtures to temp files.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $ours
     * @param array<string, mixed> $theirs
     */
    private function makeNoResolveOffContext(array $base, array $ours, array $theirs): \ComposerMergeDriver\MergeContext
    {
        $ctx = $this->fixture->make($base, $ours, $theirs);
        return new \ComposerMergeDriver\MergeContext(
            basePath:   $ctx->basePath,
            oursPath:   $ctx->oursPath,
            theirsPath: $ctx->theirsPath,
            markerSize: $ctx->markerSize,
            pathname:   $ctx->pathname,
            fileType:   $ctx->fileType,
            workingDir: $ctx->workingDir,
            noResolve:  false,
        );
    }
}
