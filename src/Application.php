<?php

declare(strict_types=1);

namespace ComposerMergeDriver;

use ComposerMergeDriver\Exception\MergeException;
use ComposerMergeDriver\Merger\ComposerJsonMerger;
use ComposerMergeDriver\Merger\ComposerLockMerger;
use ComposerMergeDriver\Merger\MergerInterface;
use ComposerMergeDriver\Merger\VendorFileMerger;
use ComposerMergeDriver\Support\ComposerLibrary;

final class Application
{
    private const VERSION = '1.0.0';

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        try {
            if ($this->hasFlag($argv, '--help') || $this->hasFlag($argv, '-h')) {
                $this->printHelp();
                return 0;
            }

            if ($this->hasFlag($argv, '--version') || $this->hasFlag($argv, '-V')) {
                fwrite(STDOUT, 'composer-merge-driver ' . self::VERSION . "\n");
                return 0;
            }

            $context = $this->buildContext($argv);
            $merger  = $this->createMerger($context);
            $clean   = $merger->merge($context);

            return $clean ? 0 : 1;
        } catch (MergeException $e) {
            fwrite(STDERR, 'composer-merge-driver: ' . $e->getMessage() . "\n");
            return 2;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'composer-merge-driver: unexpected error: ' . $e->getMessage() . "\n");
            return 2;
        }
    }

    // ─── Argument parsing ────────────────────────────────────────────────────

    /**
     * @param list<string> $argv
     * @throws MergeException
     */
    private function buildContext(array $argv): MergeContext
    {
        $workingDir = $this->getOption($argv, '--working-dir');
        $noResolve  = $this->hasFlag($argv, '--no-resolve');
        $typeOption = $this->getOption($argv, '--type');

        if ($workingDir === null) {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new MergeException('Cannot determine current working directory; pass --working-dir explicitly');
            }
            $workingDir = $cwd;
        }

        $positional = $this->extractPositionalArgs($argv);

        if (count($positional) < 3) {
            throw new MergeException(
                "Too few arguments.\n" .
                "Usage: composer-merge-driver [OPTIONS] <base> <ours> <theirs> [marker-size] [pathname]\n" .
                "Run with --help for full usage."
            );
        }

        $basePath   = $positional[0];
        $oursPath   = $positional[1];
        $theirsPath = $positional[2];
        $markerSize = isset($positional[3]) ? (int) $positional[3] : 7;
        $pathname   = $positional[4] ?? basename($oursPath);

        $fileType = $typeOption !== null
            ? FileType::fromString($typeOption)
            : FileType::detectFromPathname($pathname);

        $resolvedWorkingDir = rtrim($workingDir, '/\\');

        return new MergeContext(
            basePath: $basePath,
            oursPath: $oursPath,
            theirsPath: $theirsPath,
            markerSize: $markerSize,
            pathname: $pathname,
            fileType: $fileType,
            workingDir: $resolvedWorkingDir,
            noResolve: $noResolve,
        );
    }

    private function createMerger(MergeContext $context): MergerInterface
    {
        $library = new ComposerLibrary();

        return match ($context->fileType) {
            FileType::ComposerJson   => new ComposerJsonMerger($library),
            FileType::ComposerLock   => new ComposerLockMerger($library),
            FileType::VendorComposer => new VendorFileMerger($library),
        };
    }

    // ─── CLI helpers ─────────────────────────────────────────────────────────

    /**
     * @param list<string> $argv
     * @return list<string>
     */
    private function extractPositionalArgs(array $argv): array
    {
        /** @var list<string> $positional */
        $positional = [];
        $skipNext   = false;
        $count      = count($argv);

        for ($i = 1; $i < $count; $i++) {
            $arg = $argv[$i];

            if ($skipNext) {
                $skipNext = false;
                continue;
            }

            if (!str_starts_with($arg, '--')) {
                $positional[] = $arg;
                continue;
            }

            // --flag or --key=value: never positional.
            if (!str_contains($arg, '=')) {
                // --key value form — skip the next token.
                if (in_array($arg, ['--working-dir', '--type'], true)) {
                    $skipNext = true;
                }
            }
        }

        return $positional;
    }

    /**
     * @param list<string> $argv
     */
    private function hasFlag(array $argv, string $flag): bool
    {
        return in_array($flag, $argv, true);
    }

    /**
     * @param list<string> $argv
     */
    private function getOption(array $argv, string $name): ?string
    {
        $count = count($argv);
        for ($i = 0; $i < $count; $i++) {
            $arg = $argv[$i];
            // --name=value
            if (str_starts_with($arg, $name . '=')) {
                return substr($arg, strlen($name) + 1);
            }
            // --name value
            if ($arg === $name && $i + 1 < $count) {
                return $argv[$i + 1];
            }
        }
        return null;
    }

    private function printHelp(): void
    {
        fwrite(STDOUT, <<<'HELP'
composer-merge-driver — A semantic git merge driver for Composer files

USAGE
    composer-merge-driver [OPTIONS] <base> <ours> <theirs> [marker-size] [pathname]

ARGUMENTS
    base          Path to the base (ancestor) temp file passed by git (%O)
    ours          Path to our temp file (%A) — modified in place as the result
    theirs        Path to their temp file (%B)
    marker-size   Conflict marker size, default 7 (%L)
    pathname      Relative file path within the repo, used for type detection (%P)

OPTIONS
    --working-dir=<dir>    Project root containing composer.json (default: cwd)
    --no-resolve           Do not use the Composer library to re-resolve lock conflicts
    --type=<type>          Override auto-detection: json | lock | vendor
    -h, --help             Show this message
    -V, --version          Print version

EXIT CODES
    0   Clean merge
    1   Merge with unresolved conflicts (git will mark the file as conflicted)
    2   Fatal error

GIT CONFIGURATION (add to your project)
    .gitattributes:
        composer.json        merge=composer-merge
        composer.lock        merge=composer-merge
        vendor/composer/*    merge=composer-merge

    .git/config (or ~/.gitconfig for global use):
        [merge "composer-merge"]
            name   = Composer merge driver
            driver = composer-merge-driver %O %A %B %L %P

DOCKER (exec into a running container)
    [merge "composer-merge"]
        driver = docker exec my-container composer-merge-driver --working-dir /app %O %A %B %L %P

DOCKER (run a fresh container per merge)
    [merge "composer-merge"]
        driver = docker run --rm \
            -v "$(git rev-parse --show-toplevel)":/project \
            ghcr.io/spencermalone/composer-merge-driver \
            --working-dir /project %O %A %B %L %P

HELP);
    }
}
