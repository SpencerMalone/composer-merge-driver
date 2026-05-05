<?php

declare(strict_types=1);

namespace ComposerMergeDriver\Support;

use ComposerMergeDriver\Exception\MergeException;

interface ComposerLibraryInterface
{
    /**
     * Update only the listed packages (re-resolves their versions and dependencies)
     * and write a new composer.lock in $cwd. Packages are not downloaded.
     *
     * @param list<string> $packages
     * @throws MergeException
     */
    public function update(array $packages, string $cwd): bool;

    /**
     * Regenerate autoload files (vendor/composer/*) for the project at $cwd.
     *
     * @throws MergeException
     */
    public function dumpAutoload(string $cwd): bool;

    /**
     * Return true if the Composer library is available (classes can be loaded).
     */
    public function isAvailable(): bool;
}
