<?php

declare(strict_types=1);

namespace ComposerMergeDriver\Support;

use ComposerMergeDriver\Exception\MergeException;

final class ComposerRunner
{
    public function __construct(
        private readonly string $composerBin,
        private readonly string $workingDir,
    ) {}

    /**
     * Run composer with the given arguments in a temporary working directory.
     * Returns the exit code and combined stdout/stderr output.
     *
     * @param list<string> $args
     * @return array{exitCode: int, output: string}
     * @throws MergeException
     */
    public function run(array $args, ?string $cwd = null): array
    {
        $effectiveCwd = $cwd ?? $this->workingDir;

        $cmd = array_merge([$this->composerBin], $args);
        $escaped = implode(' ', array_map('escapeshellarg', $cmd));

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($escaped, $descriptorSpec, $pipes, $effectiveCwd, null);
        if ($process === false) {
            throw new MergeException("Failed to launch composer: {$escaped}");
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $output = ($stdout !== false ? $stdout : '') . ($stderr !== false ? $stderr : '');

        return ['exitCode' => $exitCode, 'output' => $output];
    }

    /**
     * Update only the listed packages (re-resolves their versions and dependencies).
     * Returns true on success, false on failure.
     *
     * @param list<string> $packages
     */
    public function update(array $packages, string $workingDir): bool
    {
        $args = array_merge(
            ['update', '--no-scripts', '--no-plugins', '--no-interaction', '--no-progress'],
            $packages
        );

        $result = $this->run($args, $workingDir);
        return $result['exitCode'] === 0;
    }

    /**
     * Regenerate autoload files for the project.
     */
    public function dumpAutoload(string $workingDir): bool
    {
        $result = $this->run(
            ['dump-autoload', '--no-scripts', '--no-plugins', '--no-interaction'],
            $workingDir
        );
        return $result['exitCode'] === 0;
    }

    /**
     * Return true if the configured composer binary is runnable.
     */
    public function isAvailable(): bool
    {
        try {
            $result = $this->run(['--version']);
            return $result['exitCode'] === 0;
        } catch (MergeException) {
            return false;
        }
    }
}
