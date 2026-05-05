<?php

declare(strict_types=1);

namespace ComposerMergeDriver;

final readonly class MergeContext
{
    public function __construct(
        /** Absolute path to the base (ancestor) temp file from git (%O). */
        public string $basePath,
        /** Absolute path to our temp file from git (%A). Written in-place as the merge result. */
        public string $oursPath,
        /** Absolute path to their temp file from git (%B). */
        public string $theirsPath,
        /** Conflict marker size passed by git (%L). */
        public int $markerSize,
        /** Relative pathname of the file within the repo (%P). Used for type detection. */
        public string $pathname,
        public FileType $fileType,
        /** Absolute path to the project root containing composer.json. */
        public string $workingDir,
        /** When true, skip using the Composer library to re-resolve lock conflicts. */
        public bool $noResolve,
    ) {}
}
