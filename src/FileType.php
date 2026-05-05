<?php

declare(strict_types=1);

namespace ComposerMergeDriver;

use ComposerMergeDriver\Exception\MergeException;

enum FileType
{
    case ComposerJson;
    case ComposerLock;
    case VendorComposer;

    public static function detectFromPathname(string $pathname): self
    {
        $basename = basename($pathname);

        if ($basename === 'composer.json') {
            return self::ComposerJson;
        }

        if ($basename === 'composer.lock') {
            return self::ComposerLock;
        }

        $normalised = str_replace('\\', '/', $pathname);
        if (str_contains($normalised, 'vendor/composer/')) {
            return self::VendorComposer;
        }

        throw new MergeException(
            "Cannot detect file type from pathname '{$pathname}'. " .
            "Use --type=json|lock|vendor to specify explicitly."
        );
    }

    public static function fromString(string $type): self
    {
        return match ($type) {
            'json'   => self::ComposerJson,
            'lock'   => self::ComposerLock,
            'vendor' => self::VendorComposer,
            default  => throw new MergeException(
                "Unknown file type '{$type}'. Valid values: json, lock, vendor."
            ),
        };
    }
}
