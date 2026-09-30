<?php

namespace OGame\Console\Commands\PlayerImport;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Pauses scheduled highscore rebuilds for the duration of an import, then
 * rebuilds scores and ranks once after every player has been committed.
 */
class HighscoreMaintenance
{
    /**
     * Shared with the scheduler container. A killed import process cannot
     * clear this itself, so the pause expires and scheduled jobs resume.
     */
    public const string CACHE_KEY = 'player-import:highscore-maintenance';

    public function pause(): void
    {
        Cache::put(self::CACHE_KEY, 1, now()->addDay());
    }

    public function resume(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function isPaused(): bool
    {
        return Cache::has(self::CACHE_KEY);
    }

    /**
     * Score every player, then rebuild alliance totals and ranks.
     * Alliance totals depend on player points, and ranks are assigned from both.
     */
    public function refresh(OutputInterface|null $output = null): void
    {
        $commands = [
            'ogamex:scheduler:generate-highscores',
            'ogamex:scheduler:generate-alliance-highscores',
            'ogamex:scheduler:generate-highscore-ranks',
        ];

        foreach ($commands as $command) {
            try {
                $exitCode = Artisan::call($command, [], $output);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    "Highscore refresh failed during {$command}: {$exception->getMessage()}",
                    (int)$exception->getCode(),
                    $exception
                );
            }

            if ($exitCode !== 0) {
                throw new RuntimeException("Highscore refresh failed: {$command} exited with status {$exitCode}.");
            }
        }
    }
}
