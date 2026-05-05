<?php

declare(strict_types=1);

namespace ComposerMergeDriver\Support;

use ComposerMergeDriver\Exception\MergeException;

final class JsonHelper
{
    /**
     * Read a JSON file and return its decoded contents as an associative array.
     *
     * @return array<string, mixed>
     * @throws MergeException
     */
    public static function readFile(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new MergeException("Cannot read file: {$path}");
        }
        return self::decode($contents, $path);
    }

    /**
     * Decode a JSON string into an associative array.
     *
     * @return array<string, mixed>
     * @throws MergeException
     */
    public static function decode(string $json, string $source = ''): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $label = $source !== '' ? " (source: {$source})" : '';
            throw new MergeException("Invalid JSON{$label}: " . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            $label = $source !== '' ? " in {$source}" : '';
            throw new MergeException("Expected a JSON object{$label}, got " . gettype($data));
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Encode an associative array as pretty-printed JSON, matching Composer's default style.
     *
     * @param array<string, mixed> $data
     */
    public static function encode(array $data): string
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        return $json . "\n";
    }

    /**
     * Write an associative array as JSON to a file.
     *
     * @param array<string, mixed> $data
     * @throws MergeException
     */
    public static function writeFile(string $path, array $data): void
    {
        $result = file_put_contents($path, self::encode($data));
        if ($result === false) {
            throw new MergeException("Cannot write file: {$path}");
        }
    }

    /**
     * Extract an array<string, string> from a key in a decoded JSON object.
     * Throws if the value exists but is not a string→string map.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     * @throws MergeException
     */
    public static function getStringMap(array $data, string $key): array
    {
        if (!array_key_exists($key, $data)) {
            return [];
        }

        $raw = $data[$key];
        if (!is_array($raw)) {
            throw new MergeException(
                "Expected '{$key}' to be a JSON object (string map), got " . gettype($raw)
            );
        }

        $result = [];
        foreach ($raw as $k => $v) {
            if (!is_string($k)) {
                throw new MergeException("Non-string key encountered in '{$key}'");
            }
            if (!is_string($v)) {
                throw new MergeException(
                    "Expected string value for '{$key}.{$k}', got " . gettype($v)
                );
            }
            $result[$k] = $v;
        }

        return $result;
    }

    /**
     * Extract a list<array<string, mixed>> from a key in a decoded JSON object.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     * @throws MergeException
     */
    public static function getObjectList(array $data, string $key): array
    {
        if (!array_key_exists($key, $data)) {
            return [];
        }

        $raw = $data[$key];
        if (!is_array($raw)) {
            throw new MergeException(
                "Expected '{$key}' to be a JSON array, got " . gettype($raw)
            );
        }

        $result = [];
        foreach (array_values($raw) as $i => $item) {
            if (!is_array($item)) {
                throw new MergeException(
                    "Expected '{$key}[{$i}]' to be a JSON object, got " . gettype($item)
                );
            }
            /** @var array<string, mixed> $item */
            $result[] = $item;
        }

        return $result;
    }

    /**
     * Return true if the file content contains git conflict markers.
     */
    public static function hasConflictMarkers(string $contents): bool
    {
        return str_contains($contents, '<<<<<<<') ||
               str_contains($contents, '=======') ||
               str_contains($contents, '>>>>>>>');
    }

    /**
     * Compute the content-hash that Composer embeds in composer.lock.
     * Mirrors the algorithm in Composer\Package\Locker::getContentHash().
     *
     * @throws MergeException
     */
    public static function computeContentHash(string $composerJsonContents): string
    {
        $data = json_decode($composerJsonContents, true);
        if (!is_array($data)) {
            throw new MergeException('Cannot compute content hash: composer.json is not a valid JSON object');
        }

        /** @var array<string, mixed> $data */
        $relevantKeys = [
            'name', 'version', 'require', 'require-dev',
            'conflict', 'replace', 'provide',
            'minimum-stability', 'prefer-stable',
            'repositories', 'extra',
        ];

        $relevantContent = [];
        foreach (array_intersect($relevantKeys, array_keys($data)) as $key) {
            $relevantContent[$key] = $data[$key];
        }

        $config = $data['config'] ?? null;
        if (is_array($config) && isset($config['platform']) && is_array($config['platform'])) {
            $relevantContent['config'] = ['platform' => $config['platform']];
        }

        ksort($relevantContent);

        $encoded = json_encode($relevantContent, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return md5($encoded);
    }
}
