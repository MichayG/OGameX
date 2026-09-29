<?php

namespace OGame\Console\Commands\PlayerImport;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use JsonException;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\User;
use RuntimeException;
use Throwable;

class PlayerImporter
{
    public function __construct(
        private AccountImporter $accountImporter,
        private ResearchImporter $researchImporter,
        private PlanetImporter $planetImporter,
        private ImportRequirementsValidator $requirementsValidator,
        private PlayerServiceFactory $playerServiceFactory,
        private ImportAuditWriter $auditWriter
    ) {
    }

    /**
     * @param Closure(string): void|null $onAuditCreated
     * @param Closure(User, int, int): void|null $onUserImported
     * @param Closure(string, string, string, string): void|null $onPlanetRelocated
     * @return array{auditPath: string, imported: int}
     *
     * @throws JsonException
     */
    public function import(
        string $sourcePath,
        Closure|null $onAuditCreated = null,
        Closure|null $onUserImported = null,
        Closure|null $onPlanetRelocated = null
    ): array {
        $this->accountImporter->ensurePasswordIsConfigured();
        [$resolvedPath, $sourceFileChecksum, $document] = $this->loadDocument($sourcePath);

        $auditPath = $this->auditWriter->start($resolvedPath, $sourceFileChecksum);
        $onAuditCreated?->__invoke($auditPath);

        $players = $document['players'];
        $total = count($players);
        $imported = 0;

        foreach ($players as $index => $playerData) {
            $label = $playerData['profile']['username'] ?? '#' . ($index + 1);

            try {
                /** @var list<array{username: string, planetName: string, from: string, to: string}> $relocations */
                $relocations = [];
                $user = DB::transaction(function () use ($playerData, &$relocations): User {
                    $user = $this->accountImporter->import($playerData['profile']);
                    $player = $this->playerServiceFactory->make($user->id, true);

                    $this->researchImporter->import($player, $playerData['researches'] ?? []);
                    $relocations = $this->planetImporter->import($player, $playerData['planets']);
                    $this->requirementsValidator->validate($player);

                    return $user;
                });

                // Only audit after the transaction commits so a failed commit
                // cannot leave a ghost entry for a rolled-back user.
                foreach ($relocations as $relocation) {
                    $onPlanetRelocated?->__invoke(
                        $relocation['username'],
                        $relocation['planetName'],
                        $relocation['from'],
                        $relocation['to']
                    );
                }
                $this->auditWriter->append($user);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    "Failed to import player {$label}: {$exception->getMessage()}",
                    (int)$exception->getCode(),
                    $exception
                );
            }

            $imported++;
            $onUserImported?->__invoke($user, $imported, $total);
        }

        return [
            'auditPath' => $auditPath,
            'imported' => $imported,
        ];
    }

    public function auditPath(): string|null
    {
        return $this->auditWriter->path();
    }

    /**
     * @return array{
     *     0: string,
     *     1: string,
     *     2: array{players: array<int, array<string, mixed>>}
     * }
     *
     * @throws JsonException
     */
    private function loadDocument(string $sourcePath): array
    {
        $resolvedPath = realpath($sourcePath);
        if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new RuntimeException("Player import file is not readable: {$sourcePath}");
        }

        $contents = file_get_contents($resolvedPath);
        if ($contents === false) {
            throw new RuntimeException("Unable to read player import file: {$resolvedPath}");
        }

        // Lowercase SHA-256 of the raw source bytes, before gzip decompression.
        // Stored on the audit as sourceFileChecksum so same-named exports with
        // different contents remain distinguishable (see ImportAuditWriter::start()).
        $sourceFileChecksum = hash('sha256', $contents);

        $document = json_decode($this->jsonContents($contents, $resolvedPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($document)) {
            throw new RuntimeException('Player import JSON must contain an object at its root.');
        }

        /** @var array{players: array<int, array<string, mixed>>} $validated */
        $validated = Validator::make($document, [
            'players' => ['required', 'array', 'min:1'],
            'players.*' => ['array'],
            'players.*.profile' => ['required', 'array'],
            'players.*.planets' => ['required', 'array', 'min:1'],
            'players.*.researches' => ['sometimes', 'array'],
        ])->validate();

        return [$resolvedPath, $sourceFileChecksum, $validated];
    }

    /**
     * Plain JSON and gzip-compressed JSON are both accepted. Gzip is detected
     * from the file magic bytes, so the extension is not required.
     */
    private function jsonContents(string $contents, string $resolvedPath): string
    {
        if (!str_starts_with($contents, "\x1f\x8b")) {
            return $contents;
        }

        // gzdecode() warns on corrupt input. Swallow that and report one error below.
        $decoded = false;
        set_error_handler(static fn (): bool => true);
        try {
            $decoded = gzdecode($contents);
        } catch (Throwable) {
            $decoded = false;
        } finally {
            restore_error_handler();
        }

        if (!is_string($decoded)) {
            throw new RuntimeException("Unable to decompress gzip player import file: {$resolvedPath}");
        }

        return $decoded;
    }
}
