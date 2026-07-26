<?php

namespace OGame\Console\Commands\PlayerImport;

use JsonException;
use OGame\Models\User;
use RuntimeException;

class ImportAuditWriter
{
    private string|null $path = null;

    /**
     * @var array{
     *     importedAt: string,
     *     sourceFile: string,
     *     sourceFileChecksum: string,
     *     users: array<int, array{id: int, username: string, email: string}>
     * }
     */
    private array $audit = [
        'importedAt' => '',
        'sourceFile' => '',
        // SHA-256 hex digest of the source JSON file bytes (see start()).
        'sourceFileChecksum' => '',
        'users' => [],
    ];

    /**
     * Start a new audit file for this import run.
     *
     * `$sourceFileChecksum` must be the lowercase SHA-256 hex digest of the
     * exact source JSON file bytes that are being imported. SHA-256 is used
     * so two imports that reuse the same path/filename can still be distinguished
     * when their contents differ.
     *
     * @throws JsonException
     */
    public function start(string $sourceFile, string $sourceFileChecksum): string
    {
        $directory = storage_path('app/player-import');
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create player import audit directory: {$directory}");
        }

        $baseName = 'import-' . now()->format('Ymd-His');
        $path = "{$directory}/{$baseName}.json";
        $suffix = 2;
        while (file_exists($path)) {
            $path = "{$directory}/{$baseName}-{$suffix}.json";
            $suffix++;
        }

        $this->path = $path;
        $this->audit = [
            'importedAt' => now()->toIso8601String(),
            'sourceFile' => $sourceFile,
            'sourceFileChecksum' => $sourceFileChecksum,
            'users' => [],
        ];
        $this->flush();

        return $path;
    }

    /**
     * Persist immediately so earlier successes remain rollbackable if a later player fails.
     *
     * @throws JsonException
     */
    public function append(User $user): void
    {
        if ($this->path === null) {
            throw new RuntimeException('The player import audit has not been started.');
        }

        $this->audit['users'][] = [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
        ];
        $this->flush();
    }

    public function path(): string|null
    {
        return $this->path;
    }

    /**
     * Write the in-memory audit via a temp file + rename so a crash cannot leave a truncated JSON.
     *
     * @throws JsonException
     */
    private function flush(): void
    {
        if ($this->path === null) {
            throw new RuntimeException('The player import audit has not been started.');
        }

        $json = json_encode($this->audit, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        $directory = dirname($this->path);
        $temporaryPath = tempnam($directory, '.player-import-');
        if ($temporaryPath === false) {
            throw new RuntimeException("Unable to create a temporary audit file in {$directory}");
        }

        $handle = fopen($temporaryPath, 'wb');
        if ($handle === false) {
            @unlink($temporaryPath);
            throw new RuntimeException("Unable to open temporary audit file: {$temporaryPath}");
        }

        try {
            if (!flock($handle, LOCK_EX) || fwrite($handle, $json) === false || !fflush($handle)) {
                throw new RuntimeException("Unable to write player import audit: {$this->path}");
            }

            if (function_exists('fsync')) {
                fsync($handle);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        if (!rename($temporaryPath, $this->path)) {
            @unlink($temporaryPath);
            throw new RuntimeException("Unable to publish player import audit: {$this->path}");
        }
    }
}
