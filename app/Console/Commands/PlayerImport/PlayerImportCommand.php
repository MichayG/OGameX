<?php

namespace OGame\Console\Commands\PlayerImport;

use Illuminate\Console\Command;
use OGame\Models\User;
use Throwable;

class PlayerImportCommand extends Command
{
    protected $signature = 'ogamex:player-import
                            {path : Path to a JSON or gzip-compressed JSON file}';

    protected $description = 'Import player accounts from a JSON or gzip-compressed JSON file';

    public function handle(PlayerImporter $playerImporter): int
    {
        $this->info('Importing player accounts...');

        try {
            $result = $playerImporter->import(
                (string)$this->argument('path'),
                function (string $auditPath): void {
                    $this->line("Audit file: {$auditPath}");
                },
                function (User $user, int $imported, int $total): void {
                    $this->line("  [{$imported}/{$total}] Imported {$user->username} ({$user->email})");
                },
                function (string $username, string $planetName, string $from, string $to): void {
                    $this->warn("  Relocated {$planetName} ({$username}) from {$from} to {$to}.");
                },
                $this->output
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            if ($playerImporter->auditPath() !== null) {
                $label = str_starts_with($exception->getMessage(), 'Failed to import player')
                    ? 'Audit file (partial import): '
                    : 'Audit file: ';
                $this->warn($label . $playerImporter->auditPath());
            }

            return self::FAILURE;
        }

        $this->info("Imported {$result['imported']} player(s).");

        return self::SUCCESS;
    }
}
