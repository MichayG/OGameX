<?php

namespace OGame\Console\Commands\PlayerImport;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use JsonException;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\User;
use RuntimeException;

class PlayerImportRollback
{
    public function __construct(
        private PlayerServiceFactory $playerServiceFactory
    ) {
    }

    /**
     * @param Closure(User): void|null $onDeleted
     * @param Closure(int): void|null $onMissing
     * @return array{deleted: int, missing: int}
     *
     * @throws JsonException
     */
    public function rollback(
        string $auditPath,
        Closure|null $onDeleted = null,
        Closure|null $onMissing = null
    ): array {
        $audit = $this->loadAudit($auditPath);
        $deleted = 0;
        $missing = 0;

        foreach ($audit['users'] as $record) {
            $user = User::query()->find($record['id']);
            if ($user === null) {
                $missing++;
                $onMissing?->__invoke((int)$record['id']);
                continue;
            }

            DB::transaction(function () use ($user): void {
                $this->playerServiceFactory->make($user->id, true)->delete();
            });

            $deleted++;
            $onDeleted?->__invoke($user);
        }

        return [
            'deleted' => $deleted,
            'missing' => $missing,
        ];
    }

    /**
     * @return array{
     *     importedAt: string,
     *     sourceFile: string,
     *     sourceFileChecksum: string,
     *     users: array<int, array{id: int, username: string, email: string}>
     * }
     *
     * @throws JsonException
     */
    public function loadAudit(string $auditPath): array
    {
        $resolvedPath = realpath($auditPath);
        if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new RuntimeException("Player import audit is not readable: {$auditPath}");
        }

        $contents = file_get_contents($resolvedPath);
        if ($contents === false) {
            throw new RuntimeException("Unable to read player import audit: {$resolvedPath}");
        }

        $audit = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($audit)) {
            throw new RuntimeException('Player import audit must contain an object at its root.');
        }

        /** @var array{
         *     importedAt: string,
         *     sourceFile: string,
         *     sourceFileChecksum: string,
         *     users: array<int, array{id: int, username: string, email: string}>
         * } $validated
         */
        $validated = Validator::make($audit, [
            'importedAt' => ['required', 'string'],
            'sourceFile' => ['required', 'string'],
            // Lowercase SHA-256 hex of the imported source JSON bytes.
            'sourceFileChecksum' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'users' => ['required', 'array'],
            'users.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'users.*.username' => ['required', 'string'],
            'users.*.email' => ['required', 'string', 'email'],
        ])->validate();

        return $validated;
    }
}
