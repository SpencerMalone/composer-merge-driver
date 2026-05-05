<?php

declare(strict_types=1);

namespace ComposerMergeDriver\Merger;

use ComposerMergeDriver\Exception\MergeException;
use ComposerMergeDriver\MergeContext;
use ComposerMergeDriver\Support\ComposerLibraryInterface;
use ComposerMergeDriver\Support\JsonHelper;

/**
 * Performs a three-way merge of composer.lock files.
 *
 * Algorithm:
 *  1. Index packages by name for base / ours / theirs.
 *  2. Three-way merge each package independently (same rules as a text 3-way merge
 *     but at the structured-object level — no false conflicts from key reordering).
 *  3. Merge scalar lock metadata (minimum-stability, prefer-stable, etc.).
 *  4. Recompute content-hash from the merged composer.json in the working directory.
 *  5. When package-level conflicts remain and --no-resolve is not set,
 *     resolve them by running `composer update <conflicted-packages>` in an
 *     isolated temp directory so the project working tree is never mutated.
 */
final class ComposerLockMerger implements MergerInterface
{
    /** @var list<string> Keys whose value is a flat array (not a package list). */
    private const SCALAR_KEYS = [
        'minimum-stability',
        'prefer-stable',
        'prefer-lowest',
        'plugin-api-version',
    ];

    public function __construct(
        private readonly ComposerLibraryInterface $composer,
    ) {}

    public function merge(MergeContext $context): bool
    {
        $base   = JsonHelper::readFile($context->basePath);
        $ours   = JsonHelper::readFile($context->oursPath);
        $theirs = JsonHelper::readFile($context->theirsPath);

        [$packages,    $pkgConflicts]    = $this->mergePackageList($base, $ours, $theirs, 'packages');
        [$packagesDev, $pkgDevConflicts] = $this->mergePackageList($base, $ours, $theirs, 'packages-dev');

        $conflicts = array_merge($pkgConflicts, $pkgDevConflicts);

        $merged = $this->mergeMetadata($base, $ours, $theirs);
        $merged['packages']     = $packages;
        $merged['packages-dev'] = $packagesDev;

        // Refresh the content-hash to match the (already merged) composer.json.
        $composerJsonPath = $context->workingDir . DIRECTORY_SEPARATOR . 'composer.json';
        if (is_file($composerJsonPath)) {
            $composerJsonContents = @file_get_contents($composerJsonPath);
            if ($composerJsonContents !== false && !JsonHelper::hasConflictMarkers($composerJsonContents)) {
                try {
                    $merged['content-hash'] = JsonHelper::computeContentHash($composerJsonContents);
                } catch (MergeException) {
                    // Leave the existing hash; it will be corrected on the next `composer install`.
                }
            }
        }

        // Attempt re-resolution for conflicted packages.
        if ($conflicts !== [] && !$context->noResolve) {
            $conflictedNames = array_values(array_unique(
                array_map(static fn (array $c): string => $c['name'], $conflicts)
            ));
            $resolved = $this->resolveViaComposer($context, $merged, $conflictedNames);
            if ($resolved !== null) {
                JsonHelper::writeFile($context->oursPath, $resolved);
                return true;
            }
        }

        JsonHelper::writeFile($context->oursPath, $merged);

        foreach ($conflicts as $conflict) {
            fwrite(STDERR, "composer-merge-driver: CONFLICT (composer.lock): " . $conflict['description'] . "\n");
        }

        return $conflicts === [];
    }

    // ─── Package list merge ──────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $ours
     * @param array<string, mixed> $theirs
     * @param string               $key    'packages' or 'packages-dev'
     * @return array{
     *   0: list<array<string, mixed>>,
     *   1: list<array{name: string, description: string}>
     * }
     * @throws MergeException
     */
    private function mergePackageList(
        array $base,
        array $ours,
        array $theirs,
        string $key,
    ): array {
        $baseMap   = $this->indexByName(JsonHelper::getObjectList($base, $key));
        $oursMap   = $this->indexByName(JsonHelper::getObjectList($ours, $key));
        $theirsMap = $this->indexByName(JsonHelper::getObjectList($theirs, $key));

        $all = array_unique(array_merge(
            array_keys($baseMap),
            array_keys($oursMap),
            array_keys($theirsMap),
        ));

        // Preserve ours insertion order, then append theirs additions.
        $ordered = array_keys($oursMap);
        foreach (array_keys($theirsMap) as $n) {
            if (!in_array($n, $ordered, true)) {
                $ordered[] = $n;
            }
        }
        foreach (array_keys($baseMap) as $n) {
            if (!in_array($n, $ordered, true) && in_array($n, $all, true)) {
                $ordered[] = $n;
            }
        }

        /** @var list<array<string, mixed>> $merged */
        $merged    = [];
        $conflicts = [];

        foreach ($ordered as $name) {
            $inBase   = array_key_exists($name, $baseMap);
            $inOurs   = array_key_exists($name, $oursMap);
            $inTheirs = array_key_exists($name, $theirsMap);

            if (!$inOurs && !$inTheirs) {
                continue;
            }

            if (!$inBase) {
                // New in one or both working copies.
                if ($inOurs && !$inTheirs) {
                    $merged[] = $oursMap[$name];
                } elseif (!$inOurs) {
                    $merged[] = $theirsMap[$name];
                } elseif ($this->sameVersion($oursMap[$name], $theirsMap[$name])) {
                    $merged[] = $oursMap[$name];
                } else {
                    $conflicts[] = [
                        'name'        => $name,
                        'description' => "'{$key}.{$name}': both sides added with different versions — kept ours",
                    ];
                    $merged[] = $oursMap[$name];
                }
                continue;
            }

            $basePkg = $baseMap[$name];

            if (!$inOurs) {
                // We removed it. $inTheirs is guaranteed true here (from the early continue).
                if ($this->sameVersion($basePkg, $theirsMap[$name])) {
                    // clean removal
                } else {
                    $conflicts[] = [
                        'name'        => $name,
                        'description' => "'{$key}.{$name}': removed in ours but updated in theirs — keeping theirs",
                    ];
                    $merged[] = $theirsMap[$name];
                }
                continue;
            }

            if (!$inTheirs) {
                // They removed it.
                if ($this->sameVersion($basePkg, $oursMap[$name])) {
                    // clean removal
                } else {
                    $conflicts[] = [
                        'name'        => $name,
                        'description' => "'{$key}.{$name}': updated in ours but removed in theirs — keeping ours",
                    ];
                    $merged[] = $oursMap[$name];
                }
                continue;
            }

            // Present in all three.
            $oursPkg   = $oursMap[$name];
            $theirsPkg = $theirsMap[$name];

            $oursUpdated   = !$this->sameVersion($basePkg, $oursPkg);
            $theirsUpdated = !$this->sameVersion($basePkg, $theirsPkg);

            if (!$oursUpdated && !$theirsUpdated) {
                $merged[] = $basePkg;
            } elseif ($oursUpdated && !$theirsUpdated) {
                $merged[] = $oursPkg;
            } elseif (!$oursUpdated) {
                $merged[] = $theirsPkg;
            } elseif ($this->sameVersion($oursPkg, $theirsPkg)) {
                $merged[] = $oursPkg;
            } else {
                $oursVersion   = $this->packageVersion($oursPkg);
                $theirsVersion = $this->packageVersion($theirsPkg);
                $baseVersion   = $this->packageVersion($basePkg);
                $conflicts[]   = [
                    'name'        => $name,
                    'description' => "'{$key}.{$name}': ours={$oursVersion}, theirs={$theirsVersion} (base={$baseVersion}) — kept ours, re-resolution recommended",
                ];
                $merged[] = $oursPkg;
            }
        }

        return [$merged, $conflicts];
    }

    // ─── Metadata merge ──────────────────────────────────────────────────────

    /**
     * Merge all non-package scalar fields of the lock file.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $ours
     * @param array<string, mixed> $theirs
     * @return array<string, mixed>
     */
    private function mergeMetadata(array $base, array $ours, array $theirs): array
    {
        $merged = [];

        // Preserve the _readme from ours (or theirs as fallback) — it's informational only.
        if (array_key_exists('_readme', $ours)) {
            $merged['_readme'] = $ours['_readme'];
        } elseif (array_key_exists('_readme', $theirs)) {
            $merged['_readme'] = $theirs['_readme'];
        }

        // content-hash will be set by the caller after both files are available.
        if (array_key_exists('content-hash', $ours)) {
            $merged['content-hash'] = $ours['content-hash'];
        }

        foreach (self::SCALAR_KEYS as $key) {
            $inBase   = array_key_exists($key, $base);
            $inOurs   = array_key_exists($key, $ours);
            $inTheirs = array_key_exists($key, $theirs);

            if (!$inOurs && !$inTheirs) {
                continue;
            }
            if (!$inBase) {
                $merged[$key] = $inOurs ? $ours[$key] : $theirs[$key];
                continue;
            }

            $oursChanged   = $inOurs   && $ours[$key]   !== $base[$key];
            $theirsChanged = $inTheirs && $theirs[$key] !== $base[$key];

            if ($theirsChanged && !$oursChanged) {
                $merged[$key] = $theirs[$key];
            } elseif ($inOurs) {
                $merged[$key] = $ours[$key];
            }
        }

        // Merge stability-flags (package-name → stability-int map).
        $merged['aliases']         = $this->mergeAliases($base, $ours, $theirs);
        $merged['stability-flags'] = $this->mergeStabilityFlags($base, $ours, $theirs);
        $merged['platform']        = $this->mergeStringMap($base, $ours, $theirs, 'platform');
        $merged['platform-dev']    = $this->mergeStringMap($base, $ours, $theirs, 'platform-dev');

        return $merged;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $ours
     * @param array<string, mixed> $theirs
     * @return list<mixed>
     */
    private function mergeAliases(array $base, array $ours, array $theirs): array
    {
        $seen   = [];
        $merged = [];
        $lists  = array_merge(
            JsonHelper::getObjectList($ours, 'aliases'),
            JsonHelper::getObjectList($theirs, 'aliases'),
        );
        foreach ($lists as $alias) {
            $k = serialize($alias);
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $merged[] = $alias;
            }
        }
        return $merged;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $ours
     * @param array<string, mixed> $theirs
     * @return array<string, mixed>
     */
    private function mergeStabilityFlags(array $base, array $ours, array $theirs): array
    {
        $oursFlags   = $this->extractAssocArray($ours, 'stability-flags');
        $theirsFlags = $this->extractAssocArray($theirs, 'stability-flags');
        return array_merge($oursFlags, $theirsFlags);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $ours
     * @param array<string, mixed> $theirs
     * @return array<string, mixed>
     */
    private function mergeStringMap(array $base, array $ours, array $theirs, string $key): array
    {
        $oursMap   = $this->extractAssocArray($ours, $key);
        $theirsMap = $this->extractAssocArray($theirs, $key);
        $baseMap   = $this->extractAssocArray($base, $key);

        $merged = $oursMap;
        foreach ($theirsMap as $k => $v) {
            if (!array_key_exists($k, $merged) || $merged[$k] === ($baseMap[$k] ?? null)) {
                $merged[$k] = $v;
            }
        }
        return $merged;
    }

    // ─── Composer re-resolution ──────────────────────────────────────────────

    /**
     * Run `composer update <packages>` in a temporary directory to resolve conflicts.
     * Returns the new lock file data on success, null on failure.
     *
     * @param array<string, mixed> $partialLock
     * @param list<string>         $conflictedNames
     * @return array<string, mixed>|null
     */
    private function resolveViaComposer(
        MergeContext $context,
        array $partialLock,
        array $conflictedNames,
    ): ?array {
        $composerJsonPath = $context->workingDir . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_file($composerJsonPath)) {
            fwrite(STDERR, "composer-merge-driver: Skipping re-resolution: composer.json not found at {$composerJsonPath}\n");
            return null;
        }

        $composerJsonContents = @file_get_contents($composerJsonPath);
        if ($composerJsonContents === false) {
            fwrite(STDERR, "composer-merge-driver: Skipping re-resolution: cannot read composer.json\n");
            return null;
        }

        if (JsonHelper::hasConflictMarkers($composerJsonContents)) {
            fwrite(STDERR, "composer-merge-driver: Skipping re-resolution: composer.json still has conflict markers\n");
            return null;
        }

        if (!$this->composer->isAvailable()) {
            fwrite(STDERR, "composer-merge-driver: Skipping re-resolution: Composer library not available\n");
            return null;
        }

        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'composer-merge-driver-' . bin2hex(random_bytes(8));
        if (!mkdir($tmpDir, 0700, true)) {
            fwrite(STDERR, "composer-merge-driver: Skipping re-resolution: cannot create temp directory\n");
            return null;
        }

        try {
            // Copy in merged composer.json and our best-effort partial lock.
            if (!copy($composerJsonPath, $tmpDir . DIRECTORY_SEPARATOR . 'composer.json')) {
                return null;
            }
            JsonHelper::writeFile($tmpDir . DIRECTORY_SEPARATOR . 'composer.lock', $partialLock);

            // Copy auth.json if present so private packages can be resolved.
            $authJson = $context->workingDir . DIRECTORY_SEPARATOR . 'auth.json';
            if (is_file($authJson)) {
                copy($authJson, $tmpDir . DIRECTORY_SEPARATOR . 'auth.json');
            }

            fwrite(STDERR, "composer-merge-driver: Re-resolving " . implode(', ', $conflictedNames) . " via composer update…\n");

            $ok = $this->composer->update($conflictedNames, $tmpDir);

            if (!$ok) {
                fwrite(STDERR, "composer-merge-driver: composer update failed; falling back to best-effort merge\n");
                return null;
            }

            $newLockPath = $tmpDir . DIRECTORY_SEPARATOR . 'composer.lock';
            if (!is_file($newLockPath)) {
                return null;
            }

            return JsonHelper::readFile($newLockPath);
        } catch (MergeException $e) {
            fwrite(STDERR, "composer-merge-driver: Re-resolution error: " . $e->getMessage() . "\n");
            return null;
        } finally {
            $this->removeDirectory($tmpDir);
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $packages
     * @return array<string, array<string, mixed>>
     * @throws MergeException
     */
    private function indexByName(array $packages): array
    {
        $index = [];
        foreach ($packages as $pkg) {
            $name = $pkg['name'] ?? null;
            if (!is_string($name)) {
                throw new MergeException("Lock file contains a package entry without a 'name' field");
            }
            $index[$name] = $pkg;
        }
        return $index;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function sameVersion(array $a, array $b): bool
    {
        $versionA = $a['version'] ?? null;
        $versionB = $b['version'] ?? null;

        $distA   = $a['dist']   ?? null;
        $sourceA = $a['source'] ?? null;
        $distB   = $b['dist']   ?? null;
        $sourceB = $b['source'] ?? null;

        $refA = (is_array($distA)   ? ($distA['reference']   ?? null) : null)
             ?? (is_array($sourceA) ? ($sourceA['reference'] ?? null) : null);
        $refB = (is_array($distB)   ? ($distB['reference']   ?? null) : null)
             ?? (is_array($sourceB) ? ($sourceB['reference'] ?? null) : null);

        return $versionA === $versionB && $refA === $refB;
    }

    /**
     * @param array<string, mixed> $pkg
     */
    private function packageVersion(array $pkg): string
    {
        $v = $pkg['version'] ?? null;
        return is_string($v) ? $v : '(unknown)';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function extractAssocArray(array $data, string $key): array
    {
        $val = $data[$key] ?? null;
        if (!is_array($val)) {
            return [];
        }
        /** @var array<string, mixed> $val */
        return $val;
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
