<?php

declare(strict_types=1);

namespace ComposerMergeDriver\Merger;

use ComposerMergeDriver\MergeContext;

interface MergerInterface
{
    /**
     * Perform a three-way merge and write the result to $context->oursPath.
     *
     * Returns true when the merge is clean, false when unresolved conflicts remain.
     * On false the caller exits with code 1 (git will treat the file as conflicted).
     */
    public function merge(MergeContext $context): bool;
}
