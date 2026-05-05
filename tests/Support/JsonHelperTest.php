<?php

declare(strict_types=1);

namespace ComposerMergeDriver\Tests\Support;

use ComposerMergeDriver\Support\JsonHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JsonHelperTest extends TestCase
{
    #[Test]
    public function encodeProducesPrettyPrintedJson(): void
    {
        $encoded = JsonHelper::encode(['a' => 1, 'b' => 'two']);
        self::assertStringContainsString("\n", $encoded);
        self::assertStringEndsWith("\n", $encoded);
    }

    #[Test]
    public function encodeDoesNotEscapeSlashes(): void
    {
        $encoded = JsonHelper::encode(['url' => 'https://example.com/path']);
        self::assertStringContainsString('https://example.com/path', $encoded);
    }

    #[Test]
    public function hasConflictMarkersDetectsMarkers(): void
    {
        self::assertTrue(JsonHelper::hasConflictMarkers("<<<<<<< HEAD\nfoo\n=======\nbar\n>>>>>>> branch"));
        self::assertFalse(JsonHelper::hasConflictMarkers('{"valid": "json"}'));
    }

    #[Test]
    public function computeContentHashMatchesComposerAlgorithm(): void
    {
        $composerJson = json_encode([
            'name'    => 'test/pkg',
            'require' => ['php' => '^8.1', 'vendor/a' => '^1.0'],
        ], JSON_THROW_ON_ERROR);

        $hash = JsonHelper::computeContentHash($composerJson);
        self::assertSame(32, strlen($hash), 'Content hash should be an MD5 hex string');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $hash);
    }

    #[Test]
    public function computeContentHashIsDeterministic(): void
    {
        $json = '{"require":{"php":"^8.1"}}';
        self::assertSame(
            JsonHelper::computeContentHash($json),
            JsonHelper::computeContentHash($json),
        );
    }

    #[Test]
    public function getStringMapReturnsEmptyForMissingKey(): void
    {
        self::assertSame([], JsonHelper::getStringMap([], 'require'));
    }

    #[Test]
    public function getStringMapExtractsStringValues(): void
    {
        $data = ['require' => ['php' => '^8.1', 'vendor/a' => '^1.0']];
        self::assertSame(['php' => '^8.1', 'vendor/a' => '^1.0'], JsonHelper::getStringMap($data, 'require'));
    }
}
