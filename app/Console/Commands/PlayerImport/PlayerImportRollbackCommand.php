<?php

namespace OGame\Console\Commands\PlayerImport;

use Illuminate\Console\Command;
use OGame\Models\User;
use Throwable;

class PlayerImportRollbackCommand extends Command
{
    protected $signature = 'ogamex:player-import:rollback
                            {audit : Path to a player import audit JSON file}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Delete player accounts listed in an import audit file';

    public function handle(PlayerImportRollback $rollback): int
    {
        $auditPath = (string)$this->argument('audit');

        try {
            $audit = $rollback->loadAudit($auditPath);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $count = count($audit['users']);
        if ($count === 0) {
            $this->info('The audit contains no imported users.');
            return self::SUCCESS;
        }

        $this->warn("This will permanently delete {$count} imported player(s) and their game data.");
        if (!$this->option('force') && !$this->confirm('Continue with rollback?')) {
            $this->info('Rollback cancelled.');
            return self::SUCCESS;
        }

        try {
            $result = $rollback->rollback(
                $auditPath,
                function (User $user): void {
                    $this->line("  Deleted {$user->username} ({$user->id})");
                },
                function (int $userId): void {
                    $this->warn("  User {$userId} is already missing; skipped.");
                }
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->info("Rollback complete: {$result['deleted']} deleted, {$result['missing']} already missing.");
        $this->line('The audit file was retained for history.');

        return self::SUCCESS;
    }
}
