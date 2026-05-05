<?php

declare(strict_types=1);

namespace ComposerMergeDriver\Merger;

use ComposerMergeDriver\MergeContext;
use ComposerMergeDriver\Support\ComposerLibraryInterface;

/**
 * Handles files under vendor/composer/ (installed.json, autoload_*.php, etc.).
 *
 * These are fully generated artifacts derived from composer.lock — there is no
 * meaningful way to merge them as text. The correct resolution is to regenerate
 * them via `composer dump-autoload`.
 *
 * Strategy:
 *  1. If noResolve is false, attempt to regenerate via the Composer library.
 *     On success, return true (clean merge).
 *  2. If regeneration is skipped or fails, leave "ours" in place and return false
 *     so git marks the file as conflicted, prompting manual resolution
 *     (typically: run `composer install`).
 */
final class VendorFileMerger implements MergerInterface
{
    public function __construct(
        private readonly ComposerLibraryInterface $composer,
    ) {}

    public function merge(MergeContext $context): bool
    {
        if (!$context->noResolve && $this->composer->isAvailable()) {
            try {
                $ok = $this->composer->dumpAutoload($context->workingDir);
                if ($ok) {
                    fwrite(
                        STDERR,
                        "composer-merge-driver: {$context->pathname}: regenerated via composer dump-autoload\n"
                    );
                    return true;
                }
            } catch (\Throwable) {
                // Fall through to conflict below.
            }

            fwrite(
                STDERR,
                "composer-merge-driver: {$context->pathname}: dump-autoload failed — marking as conflicted (run `composer install` to fix)\n"
            );
            return false;
        }

        // noResolve or library unavailable — leave ours in place and mark conflicted.
        fwrite(
            STDERR,
            "composer-merge-driver: {$context->pathname}: skipping regeneration — marking as conflicted (run `composer install` to fix)\n"
        );
        return false;
    }
}
