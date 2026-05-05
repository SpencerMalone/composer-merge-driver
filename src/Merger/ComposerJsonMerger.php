<?php

declare(strict_types=1);

namespace ComposerMergeDriver\Merger;

use ComposerMergeDriver\Exception\MergeException;
use ComposerMergeDriver\MergeContext;
use ComposerMergeDriver\Support\ComposerLibraryInterface;
use ComposerMergeDriver\Support\JsonHelper;

/**
 * Performs a semantic three-way merge of composer.json files.
 *
 * Strategy per field type:
 *  - require / require-dev / conflict / replace / provide / suggest:
 *      Merged as package-constraint maps. Each package is resolved independently.
 *  - autoload / autoload-dev / scripts / extra / config / support:
 *      Recursively deep-merged.
 *  - repositories:
 *      Merged as a set, deduplicated by url/type/path.
 *  - All other scalar/array fields:
 *      Standard three-way: take whichever side changed; conflict if both changed differently.
 */
final class ComposerJsonMerger implements MergerInterface
{
    /** @var list<string> */
    private const PACKAGE_MAP_KEYS = ['require', 'require-dev', 'conflict', 'replace', 'provide', 'suggest'];

    /** @var list<string> */
    private const DEEP_MERGE_KEYS = ['autoload', 'autoload-dev', 'scripts', 'extra', 'config', 'support'];

    public function __construct(
        private readonly ComposerLibraryInterface $composer,
    ) {}

    public function merge(MergeContext $context): bool
    {
        $base   = JsonHelper::readFile($context->basePath);
        $ours   = JsonHelper::readFile($context->oursPath);
        $theirs = JsonHelper::readFile($context->theirsPath);

        [$merged, $conflicts] = $this->mergeData($base, $ours, $theirs);

        JsonHelper::writeFile($context->oursPath, $merged);

        foreach ($conflicts as $conflict) {
            fwrite(STDERR, "composer-merge-driver: CONFLICT (composer.json): {$conflict}\n");
        }

        if ($conflicts === [] && !$context->noResolve) {
            foreach ($this->composer->validate($context->oursPath) as $error) {
                fwrite(STDERR, "composer-merge-driver: INVALID (composer.json): {$error}\n");
                $conflicts[] = $error;
            }
        }

        return $conflicts === [];
    }

    // ─── Core three-way merge ────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $ours
     * @param array<string, mixed> $theirs
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function mergeData(array $base, array $ours, array $theirs): array
    {
        /** @var array<string, mixed> $merged */
        $merged    = [];
        $conflicts = [];

        // Preserve ours key order, then append keys only in theirs or base.
        $orderedKeys = array_keys($ours);
        foreach (array_keys($theirs) as $k) {
            if (!in_array($k, $orderedKeys, true)) {
                $orderedKeys[] = $k;
            }
        }
        foreach (array_keys($base) as $k) {
            if (!in_array($k, $orderedKeys, true)) {
                $orderedKeys[] = $k;
            }
        }

        foreach ($orderedKeys as $key) {
            $inBase   = array_key_exists($key, $base);
            $inOurs   = array_key_exists($key, $ours);
            $inTheirs = array_key_exists($key, $theirs);

            // Removed (or never present) in both working copies — skip.
            if (!$inOurs && !$inTheirs) {
                continue;
            }

            if (!$inBase) {
                // Brand-new key — not present in the common ancestor.
                if ($inOurs && !$inTheirs) {
                    $merged[$key] = $ours[$key];
                } elseif (!$inOurs) {
                    $merged[$key] = $theirs[$key];
                } else {
                    [$val, $c] = $this->mergeValue($key, null, $ours[$key], $theirs[$key]);
                    $merged[$key] = $val;
                    $conflicts    = array_merge($conflicts, $c);
                }
                continue;
            }

            $baseVal = $base[$key];

            if (!$inOurs) {
                // We removed it. $inTheirs is guaranteed true here (from the early continue).
                // If theirs also left it unchanged, respect our removal.
                if ($this->equal($baseVal, $theirs[$key])) {
                    // clean removal
                } else {
                    $conflicts[]  = "'{$key}': removed in ours but modified in theirs — keeping theirs";
                    $merged[$key] = $theirs[$key];
                }
                continue;
            }

            if (!$inTheirs) {
                // They removed it. If we left it unchanged, respect their removal.
                if ($this->equal($baseVal, $ours[$key])) {
                    // clean removal
                } else {
                    $conflicts[]  = "'{$key}': modified in ours but removed in theirs — keeping ours";
                    $merged[$key] = $ours[$key];
                }
                continue;
            }

            // Present in all three.
            $oursChanged   = !$this->equal($baseVal, $ours[$key]);
            $theirsChanged = !$this->equal($baseVal, $theirs[$key]);

            if (!$oursChanged && !$theirsChanged) {
                $merged[$key] = $baseVal;
            } elseif ($oursChanged && !$theirsChanged) {
                $merged[$key] = $ours[$key];
            } elseif (!$oursChanged) {
                $merged[$key] = $theirs[$key];
            } else {
                [$val, $c] = $this->mergeValue($key, $baseVal, $ours[$key], $theirs[$key]);
                $merged[$key] = $val;
                $conflicts    = array_merge($conflicts, $c);
            }
        }

        return [$merged, $conflicts];
    }

    /**
     * Merge a single key's value according to its semantic role.
     *
     * @param mixed $base   null when the key is new in both ours and theirs
     * @param mixed $ours
     * @param mixed $theirs
     * @return array{0: mixed, 1: list<string>}
     */
    private function mergeValue(string $key, mixed $base, mixed $ours, mixed $theirs): array
    {
        if ($this->equal($ours, $theirs)) {
            return [$ours, []];
        }

        if (in_array($key, self::PACKAGE_MAP_KEYS, true)) {
            $baseMap   = is_array($base)   ? $this->toStringMap($base, $key)   : [];
            $oursMap   = is_array($ours)   ? $this->toStringMap($ours, $key)   : [];
            $theirsMap = is_array($theirs) ? $this->toStringMap($theirs, $key) : [];
            [$merged, $c] = $this->mergePackageMap($key, $baseMap, $oursMap, $theirsMap);
            return [$merged, $c];
        }

        if ($key === 'repositories') {
            $baseList   = is_array($base)   ? $base   : [];
            $oursList   = is_array($ours)   ? $ours   : [];
            $theirsList = is_array($theirs) ? $theirs : [];
            return [$this->mergeRepositories($baseList, $oursList, $theirsList), []];
        }

        if (in_array($key, self::DEEP_MERGE_KEYS, true)) {
            return $this->deepMerge($key, $base, $ours, $theirs);
        }

        // When both sides are JSON objects (string-keyed arrays) and the key wasn't
        // listed explicitly above, recurse generically rather than raising a conflict.
        // This handles nested structures like autoload.psr-4, scripts.*, extra.*, etc.
        if (is_array($ours) && is_array($theirs)
            && $this->isObjectLike($ours) && $this->isObjectLike($theirs)
        ) {
            return $this->deepMerge($key, $base, $ours, $theirs);
        }

        // Scalar or list value conflict — ours wins, report.
        return [
            $ours,
            [sprintf(
                "'%s': conflict between ours (%s) and theirs (%s) — kept ours",
                $key,
                $this->describe($ours),
                $this->describe($theirs)
            )],
        ];
    }

    // ─── Package-constraint map (require / require-dev / …) ─────────────────

    /**
     * @param array<string, string> $base
     * @param array<string, string> $ours
     * @param array<string, string> $theirs
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function mergePackageMap(
        string $field,
        array $base,
        array $ours,
        array $theirs,
    ): array {
        $merged    = [];
        $conflicts = [];

        $all = array_unique(array_merge(array_keys($base), array_keys($ours), array_keys($theirs)));

        // Preserve ours order, then append theirs additions.
        $ordered = array_keys($ours);
        foreach (array_keys($theirs) as $p) {
            if (!in_array($p, $ordered, true)) {
                $ordered[] = $p;
            }
        }
        foreach (array_keys($base) as $p) {
            if (!in_array($p, $ordered, true) && in_array($p, $all, true)) {
                $ordered[] = $p;
            }
        }

        foreach ($ordered as $pkg) {
            $inBase   = array_key_exists($pkg, $base);
            $inOurs   = array_key_exists($pkg, $ours);
            $inTheirs = array_key_exists($pkg, $theirs);

            if (!$inOurs && !$inTheirs) {
                continue;
            }

            $baseC   = $inBase   ? $base[$pkg]   : null;
            $oursC   = $inOurs   ? $ours[$pkg]   : null;
            $theirsC = $inTheirs ? $theirs[$pkg] : null;

            if (!$inBase) {
                if ($inOurs && !$inTheirs) {
                    $merged[$pkg] = $ours[$pkg];
                } elseif (!$inOurs) {
                    // $inTheirs is guaranteed true here (from the early continue)
                    $merged[$pkg] = $theirs[$pkg];
                } elseif ($oursC === $theirsC) {
                    $merged[$pkg] = $ours[$pkg];
                } else {
                    $conflicts[]  = "'{$field}.{$pkg}': both sides added with different constraints (ours: '{$oursC}', theirs: '{$theirsC}') — kept ours";
                    $merged[$pkg] = $ours[$pkg];
                }
                continue;
            }

            if (!$inOurs) {
                if ($theirsC === $baseC) {
                    // theirs unchanged — honour our removal
                } else {
                    $conflicts[]  = "'{$field}.{$pkg}': removed in ours but changed to '{$theirsC}' in theirs — keeping theirs";
                    $merged[$pkg] = $theirs[$pkg];
                }
                continue;
            }

            if (!$inTheirs) {
                if ($oursC === $baseC) {
                    // ours unchanged — honour their removal
                } else {
                    $conflicts[]  = "'{$field}.{$pkg}': changed to '{$oursC}' in ours but removed in theirs — keeping ours";
                    $merged[$pkg] = $ours[$pkg];
                }
                continue;
            }

            // Present in all three.
            $oursChanged   = $oursC !== $baseC;
            $theirsChanged = $theirsC !== $baseC;

            if (!$oursChanged && !$theirsChanged) {
                $merged[$pkg] = $ours[$pkg];
            } elseif ($oursChanged && !$theirsChanged) {
                $merged[$pkg] = $ours[$pkg];
            } elseif (!$oursChanged) {
                $merged[$pkg] = $theirs[$pkg];
            } elseif ($oursC === $theirsC) {
                $merged[$pkg] = $ours[$pkg];
            } else {
                $conflicts[]  = "'{$field}.{$pkg}': ours '{$oursC}', theirs '{$theirsC}' (base: '{$baseC}') — kept ours";
                $merged[$pkg] = $ours[$pkg];
            }
        }

        return [$merged, $conflicts];
    }

    // ─── Repositories array ──────────────────────────────────────────────────

    /**
     * Merge repository arrays by de-duplicating on a canonical key (url/path/type).
     *
     * @param array<mixed> $base
     * @param array<mixed> $ours
     * @param array<mixed> $theirs
     * @return list<mixed>
     */
    private function mergeRepositories(array $base, array $ours, array $theirs): array
    {
        $seen   = [];
        $merged = [];

        foreach (array_merge($ours, $theirs) as $repo) {
            if (!is_array($repo)) {
                continue;
            }
            $key = $this->repositoryKey($repo);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[]   = $repo;
        }

        // Honour removals: drop repos that existed in base but were removed in ours.
        $removedInOurs = [];
        foreach ($base as $repo) {
            if (!is_array($repo)) {
                continue;
            }
            $key        = $this->repositoryKey($repo);
            $stillInOurs = false;
            foreach ($ours as $oursRepo) {
                if (is_array($oursRepo) && $this->repositoryKey($oursRepo) === $key) {
                    $stillInOurs = true;
                    break;
                }
            }
            if (!$stillInOurs) {
                $removedInOurs[$key] = true;
            }
        }

        return array_values(array_filter(
            $merged,
            fn (array $r): bool => !isset($removedInOurs[$this->repositoryKey($r)])
        ));
    }

    /**
     * Produce a stable identity string for a repository entry.
     *
     * @param array<mixed> $repo
     */
    private function repositoryKey(array $repo): string
    {
        if (isset($repo['url']) && is_string($repo['url'])) {
            return 'url:' . $repo['url'];
        }
        if (isset($repo['path']) && is_string($repo['path'])) {
            return 'path:' . $repo['path'];
        }
        if (isset($repo['type']) && is_string($repo['type'])) {
            return 'type:' . $repo['type'];
        }
        return 'raw:' . serialize($repo);
    }

    // ─── Recursive deep merge ────────────────────────────────────────────────

    /**
     * @param mixed $base
     * @param mixed $ours
     * @param mixed $theirs
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function deepMerge(string $prefix, mixed $base, mixed $ours, mixed $theirs): array
    {
        $base   = $this->asStringKeyedArray($base);
        $ours   = $this->asStringKeyedArray($ours);
        $theirs = $this->asStringKeyedArray($theirs);

        /** @var array<string, mixed> $merged */
        $merged    = [];
        $conflicts = [];

        $orderedKeys = array_keys($ours);
        foreach (array_keys($theirs) as $k) {
            if (!in_array($k, $orderedKeys, true)) {
                $orderedKeys[] = $k;
            }
        }
        foreach (array_keys($base) as $k) {
            if (!in_array($k, $orderedKeys, true)) {
                $orderedKeys[] = $k;
            }
        }

        foreach ($orderedKeys as $key) {
            $inBase   = array_key_exists($key, $base);
            $inOurs   = array_key_exists($key, $ours);
            $inTheirs = array_key_exists($key, $theirs);

            if (!$inOurs && !$inTheirs) {
                continue;
            }

            if (!$inBase) {
                if ($inOurs && !$inTheirs) {
                    $merged[$key] = $ours[$key];
                } elseif (!$inOurs) {
                    $merged[$key] = $theirs[$key];
                } else {
                    [$val, $c] = $this->mergeValue("{$prefix}.{$key}", null, $ours[$key], $theirs[$key]);
                    $merged[$key] = $val;
                    $conflicts    = array_merge($conflicts, $c);
                }
                continue;
            }

            $baseVal = $base[$key];

            if (!$inOurs) {
                // $inTheirs is guaranteed true here (from the early continue)
                if ($this->equal($baseVal, $theirs[$key])) {
                    // clean removal in ours
                } else {
                    $conflicts[]  = "'{$prefix}.{$key}': removed in ours but modified in theirs — keeping theirs";
                    $merged[$key] = $theirs[$key];
                }
                continue;
            }

            if (!$inTheirs) {
                if ($this->equal($baseVal, $ours[$key])) {
                    // clean removal in theirs
                } else {
                    $conflicts[]  = "'{$prefix}.{$key}': modified in ours but removed in theirs — keeping ours";
                    $merged[$key] = $ours[$key];
                }
                continue;
            }

            $oursChanged   = !$this->equal($baseVal, $ours[$key]);
            $theirsChanged = !$this->equal($baseVal, $theirs[$key]);

            if (!$oursChanged && !$theirsChanged) {
                $merged[$key] = $baseVal;
            } elseif ($oursChanged && !$theirsChanged) {
                $merged[$key] = $ours[$key];
            } elseif (!$oursChanged) {
                $merged[$key] = $theirs[$key];
            } else {
                [$val, $c] = $this->mergeValue("{$prefix}.{$key}", $baseVal, $ours[$key], $theirs[$key]);
                $merged[$key] = $val;
                $conflicts    = array_merge($conflicts, $c);
            }
        }

        return [$merged, $conflicts];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function equal(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            return $this->arraysEqual($a, $b);
        }
        return $a === $b;
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    private function arraysEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $k => $v) {
            if (!array_key_exists($k, $b) || !$this->equal($v, $b[$k])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Return true when every key in the array is a string (i.e. it's a JSON object).
     * Empty arrays are considered object-like since JSON objects can be empty.
     *
     * @param array<mixed> $arr
     */
    private function isObjectLike(array $arr): bool
    {
        foreach (array_keys($arr) as $k) {
            if (!is_string($k)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Convert any value to array<string, mixed>, silently dropping non-string keys.
     *
     * @return array<string, mixed>
     */
    private function asStringKeyedArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $k => $v) {
            if (is_string($k)) {
                $result[$k] = $v;
            }
        }
        return $result;
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => "'{$value}'",
            is_bool($value)   => $value ? 'true' : 'false',
            is_null($value)   => 'null',
            is_int($value)    => (string) $value,
            is_float($value)  => (string) $value,
            is_array($value)  => '[array with ' . count($value) . ' items]',
            default           => '[' . gettype($value) . ']',
        };
    }

    /**
     * Validate and coerce any array to array<string, string>.
     *
     * @param array<mixed, mixed> $map
     * @return array<string, string>
     * @throws MergeException
     */
    private function toStringMap(array $map, string $field): array
    {
        $result = [];
        foreach ($map as $k => $v) {
            if (!is_string($k)) {
                throw new MergeException("Non-string key encountered in '{$field}'");
            }
            if (!is_string($v)) {
                throw new MergeException(
                    "Expected string constraint for '{$field}.{$k}', got " . gettype($v)
                );
            }
            $result[$k] = $v;
        }
        return $result;
    }
}
