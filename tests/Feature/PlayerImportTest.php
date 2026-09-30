<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use OGame\Console\Commands\PlayerImport\AccountImporter;
use OGame\Console\Commands\PlayerImport\HighscoreMaintenance;
use OGame\Console\Commands\PlayerImport\PlanetImporter;
use OGame\Console\Commands\PlayerImport\PlayerImporter;
use OGame\Console\Commands\PlayerImport\PlayerImportRollback;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameConstants\UniverseConstants;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Highscore;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use OGame\Models\UserTech;
use RuntimeException;
use Tests\TestCase;

class PlayerImportTest extends TestCase
{
    /**
     * Each imported player is committed in its own transaction. This outer
     * transaction turns those commits into savepoints, so a test killed during
     * the highscore refresh cannot leave accounts in the application database.
     * Closing the connection rolls the transaction back.
     */
    use DatabaseTransactions;

    /**
     * @var array<int, int>
     */
    private array $createdUserIds = [];

    /**
     * @var array<int, string>
     */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdUserIds as $userId) {
            if (User::query()->whereKey($userId)->exists()) {
                resolve(PlayerServiceFactory::class)->make($userId, true)->delete();
            }
        }

        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_partial_import_is_audited_and_can_be_rolled_back(): void
    {
        config()->set('app.player_import_password', 'import-test-password');

        $coordinate = $this->getSafeEmptyCoordinate(new Coordinate(1, 250, 8));
        $suffix = bin2hex(random_bytes(5));
        $email = "import-{$suffix}@example.com";
        $document = [
            'version' => '1.0',
            'generatedAt' => now()->toIso8601String(),
            'seed' => 2026,
            'players' => [
                $this->playerData("import-{$suffix}", $email, $coordinate),
                $this->playerData("import-failure-{$suffix}", $email, $coordinate),
            ],
        ];

        $sourcePath = $this->temporaryJson($document);
        $importer = resolve(PlayerImporter::class);

        try {
            $importer->import($sourcePath);
            $this->fail('The duplicate email should stop the import.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Failed to import player', $exception->getMessage());
        }

        $auditPath = $importer->auditPath();
        $this->assertNotNull($auditPath);
        $this->temporaryFiles[] = $auditPath;

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->createdUserIds[] = $user->id;

        $this->assertSame("import-{$suffix}", $user->username);
        $this->assertTrue(Hash::check('import-test-password', $user->password));
        $this->assertSame(CharacterClass::GENERAL->value, $user->character_class);
        $this->assertTrue($user->vacation_mode);

        $tech = UserTech::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(4, $tech->weapon_technology);

        // A failed run does not rebuild highscores. The committed player keeps the placeholder row.
        $highscore = Highscore::query()->where('player_id', $user->id)->firstOrFail();
        $this->assertSame(0, $highscore->general);
        $this->assertFalse(resolve(HighscoreMaintenance::class)->isPaused());

        $planet = Planet::query()
            ->where('user_id', $user->id)
            ->where('planet_type', PlanetType::Planet->value)
            ->firstOrFail();
        $this->assertSame(5, $planet->metal_mine);
        $this->assertSame(4, $planet->research_lab);
        $this->assertSame(3, $planet->small_cargo);
        $this->assertSame(7, $planet->rocket_launcher);
        $this->assertSame(1000, (int)$planet->metal);
        $this->assertSame($planet->id, $user->planet_current);

        $moon = Planet::query()
            ->where('user_id', $user->id)
            ->where('planet_type', PlanetType::Moon->value)
            ->firstOrFail();
        $this->assertSame(8000, $moon->diameter);
        $this->assertSame(2, $moon->lunar_base);

        $audit = json_decode((string)file_get_contents($auditPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $audit['users']);
        $this->assertSame($user->id, $audit['users'][0]['id']);
        $this->assertSame($email, $audit['users'][0]['email']);
        $this->assertSame(realpath($sourcePath), $audit['sourceFile']);
        $this->assertSame(hash('sha256', (string)file_get_contents($sourcePath)), $audit['sourceFileChecksum']);

        $result = resolve(PlayerImportRollback::class)->rollback($auditPath);
        $this->assertSame(['deleted' => 1, 'missing' => 0], $result);
        $this->assertNull(User::query()->find($user->id));
        $this->createdUserIds = [];
        $this->assertFileExists($auditPath);
    }

    public function test_successful_import_scores_players_and_orders_them_by_points(): void
    {
        config()->set('app.player_import_password', 'import-test-password');

        $suffix = bin2hex(random_bytes(5));
        $players = [];
        foreach ([10 => 'weak', 20 => 'strong'] as $mineLevel => $label) {
            $player = $this->playerData(
                "import-{$label}-{$suffix}",
                "import-{$label}-{$suffix}@example.com",
                $this->getSafeEmptyCoordinate(new Coordinate(1, $mineLevel === 10 ? 270 : 280, 8))
            );
            $player['planets'][0]['buildings'] = [
                ['code' => 'metal_mine', 'level' => $mineLevel],
            ];
            $player['planets'][0]['fleet'] = [];
            $player['planets'][0]['defenses'] = [];
            $player['planets'][0]['moon'] = null;
            $player['researches'] = [];
            $players[$label] = $player;
        }

        $importer = resolve(PlayerImporter::class);
        $importer->import($this->temporaryJson([
            'version' => '1.0',
            'generatedAt' => now()->toIso8601String(),
            'seed' => 2026,
            'players' => [$players['weak'], $players['strong']],
        ]));
        $this->rememberAudit($importer);

        $weakerUser = User::query()->where('email', $players['weak']['profile']['email'])->firstOrFail();
        $strongerUser = User::query()->where('email', $players['strong']['profile']['email'])->firstOrFail();
        $this->createdUserIds[] = $weakerUser->id;
        $this->createdUserIds[] = $strongerUser->id;

        $weakerScore = Highscore::query()->where('player_id', $weakerUser->id)->firstOrFail();
        $strongerScore = Highscore::query()->where('player_id', $strongerUser->id)->firstOrFail();

        $this->assertGreaterThan(0, $weakerScore->general);
        $this->assertGreaterThan($weakerScore->general, $strongerScore->general);
        $this->assertLessThan($weakerScore->general_rank, $strongerScore->general_rank);
        $this->assertFalse(resolve(HighscoreMaintenance::class)->isPaused());
    }

    public function test_scheduled_highscore_jobs_wait_while_an_import_is_running(): void
    {
        $maintenance = resolve(HighscoreMaintenance::class);
        $maintenance->resume();

        try {
            $this->assertTrue($this->scheduledHighscoreJobsAreDue());
            $maintenance->pause();
            $this->assertFalse($this->scheduledHighscoreJobsAreDue());
        } finally {
            $maintenance->resume();
        }

        $this->assertTrue($this->scheduledHighscoreJobsAreDue());
    }

    public function test_plain_json_and_gzip_treat_missing_entries_as_zero(): void
    {
        config()->set('app.player_import_password', 'import-test-password');

        foreach (['json', 'gzip'] as $format) {
            $coordinate = $this->getSafeEmptyCoordinate(new Coordinate(1, 260, 8));
            $suffix = bin2hex(random_bytes(5));
            $email = "import-{$format}-{$suffix}@example.com";
            $document = $this->sparsePlayerData("import-{$format}-{$suffix}", $email, $coordinate);
            $sourcePath = $format === 'gzip'
                ? $this->temporaryGzip($document)
                : $this->temporaryJson($document);

            $importer = resolve(PlayerImporter::class);
            $importer->import($sourcePath);

            $auditPath = $importer->auditPath();
            $this->assertNotNull($auditPath);
            $this->temporaryFiles[] = $auditPath;

            $user = User::query()->where('email', $email)->firstOrFail();
            $this->createdUserIds[] = $user->id;

            $tech = UserTech::query()->where('user_id', $user->id)->firstOrFail();
            $this->assertSame(3, $tech->energy_technology);
            $this->assertSame(0, $tech->laser_technology);
            $this->assertSame(0, $tech->weapon_technology);

            $planet = Planet::query()
                ->where('user_id', $user->id)
                ->where('planet_type', PlanetType::Planet->value)
                ->firstOrFail();
            $this->assertSame(4, $planet->research_lab);
            $this->assertSame(0, $planet->metal_mine);
            $this->assertSame(0, $planet->crystal_mine);
            $this->assertSame(2, $planet->light_fighter);
            $this->assertSame(0, $planet->colony_ship);
            $this->assertSame(0, $planet->small_cargo);
            $this->assertSame(5, $planet->heavy_laser);
            $this->assertSame(0, $planet->light_laser);
            $this->assertSame(0, $planet->rocket_launcher);
            $this->assertSame(0, (int)$planet->metal);
            $this->assertSame(0, (int)$planet->crystal);
            $this->assertSame(80, (int)$planet->deuterium);

            $audit = json_decode((string)file_get_contents($auditPath), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(realpath($sourcePath), $audit['sourceFile']);
            $this->assertSame(hash('sha256', (string)file_get_contents($sourcePath)), $audit['sourceFileChecksum']);
        }
    }

    public function test_imported_accounts_share_one_password_hash(): void
    {
        config()->set('app.player_import_password', 'import-test-password');

        $suffix = bin2hex(random_bytes(5));
        $importer = resolve(AccountImporter::class);
        $profile = [
            'accountAgeDays' => 1,
            'status' => 'active',
        ];

        $first = $importer->import($profile + [
            'username' => "hash-a-{$suffix}",
            'email' => "hash-a-{$suffix}@example.com",
        ]);
        $second = $importer->import($profile + [
            'username' => "hash-b-{$suffix}",
            'email' => "hash-b-{$suffix}@example.com",
        ]);
        $this->createdUserIds[] = $first->id;
        $this->createdUserIds[] = $second->id;

        // Bcrypt includes a random salt, so equal hashes mean the password was hashed once.
        $this->assertSame($first->password, $second->password);
        $this->assertTrue(Hash::check('import-test-password', $first->password));
    }

    public function test_inactive_status_sets_last_activity_seven_days_ago(): void
    {
        config()->set('app.player_import_password', 'import-test-password');

        $coordinate = $this->getSafeEmptyCoordinate(new Coordinate(1, 310, 8));
        $suffix = bin2hex(random_bytes(5));
        $email = "import-inactive-{$suffix}@example.com";
        $player = $this->playerData("import-inactive-{$suffix}", $email, $coordinate);
        $player['profile']['status'] = 'inactive';

        $importer = resolve(PlayerImporter::class);
        $importer->import($this->temporaryJson($this->documentFor($player)));
        $this->rememberAudit($importer);

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->createdUserIds[] = $user->id;

        $this->assertEqualsWithDelta(now()->subDays(7)->timestamp, (int)$user->time, 5);
        $this->assertFalse($user->vacation_mode);
        $this->assertNull($user->vacation_mode_activated_at);

        $playerService = resolve(PlayerServiceFactory::class)->make($user->id);
        $this->assertTrue($playerService->isInactive());
        $this->assertFalse($playerService->isLongInactive());
    }

    public function test_invalid_gzip_is_rejected(): void
    {
        config()->set('app.player_import_password', 'import-test-password');

        $path = tempnam(storage_path('app'), 'player-import-test-');
        if ($path === false) {
            $this->fail('Unable to create temporary player import file.');
        }

        file_put_contents($path, "\x1f\x8b" . 'not-gzip');
        $this->temporaryFiles[] = $path;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to decompress gzip player import file');

        resolve(PlayerImporter::class)->import($path);
    }

    public function test_occupied_position_aborts_when_retry_is_disabled(): void
    {
        config()->set('app.player_import_password', 'import-test-password');
        config()->set('app.player_import_retry_upon_collision', false);

        $coordinate = $this->getSafeEmptyCoordinate(new Coordinate(1, 270, 8));
        $suffix = bin2hex(random_bytes(5));
        $this->occupySlot($suffix, $coordinate);

        $email = "import-collision-{$suffix}@example.com";
        $sourcePath = $this->temporaryJson($this->documentFor($this->playerData("import-collision-{$suffix}", $email, $coordinate)));
        $importer = resolve(PlayerImporter::class);

        try {
            $importer->import($sourcePath);
            $this->fail('An occupied coordinate should stop the import when retry is disabled.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already occupied', $exception->getMessage());
            $this->assertStringNotContainsString('no free slot', $exception->getMessage());
        }

        $this->rememberAudit($importer);
        $this->assertNull(User::query()->where('email', $email)->first());
    }

    public function test_retry_upon_collision_moves_to_the_next_position(): void
    {
        config()->set('app.player_import_password', 'import-test-password');
        config()->set('app.player_import_retry_upon_collision', true);

        $coordinate = $this->coordinateWithFreeNeighbors();
        $suffix = bin2hex(random_bytes(5));
        $this->occupySlot($suffix, $coordinate);

        $email = "import-retry-{$suffix}@example.com";
        $relocations = [];
        $importer = resolve(PlayerImporter::class);
        $importer->import(
            $this->temporaryJson($this->documentFor($this->playerData("import-retry-{$suffix}", $email, $coordinate))),
            null,
            null,
            function (string $username, string $planetName, string $from, string $to) use (&$relocations): void {
                $relocations[] = compact('username', 'planetName', 'from', 'to');
            }
        );
        $this->rememberAudit($importer);

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->createdUserIds[] = $user->id;

        $planet = Planet::query()
            ->where('user_id', $user->id)
            ->where('planet_type', PlanetType::Planet->value)
            ->firstOrFail();
        $this->assertSame($coordinate->galaxy, $planet->galaxy);
        $this->assertSame($coordinate->system, $planet->system);
        $this->assertSame($coordinate->position + 1, $planet->planet);
        $this->assertSame([
            [
                'username' => $user->username,
                'planetName' => 'Homeworld',
                'from' => $coordinate->asString(),
                'to' => $coordinate->galaxy . ':' . $coordinate->system . ':' . ($coordinate->position + 1),
            ],
        ], $relocations);

        $moon = Planet::query()
            ->where('user_id', $user->id)
            ->where('planet_type', PlanetType::Moon->value)
            ->firstOrFail();
        $this->assertSame($planet->galaxy, $moon->galaxy);
        $this->assertSame($planet->system, $moon->system);
        $this->assertSame($planet->planet, $moon->planet);
    }

    public function test_retry_upon_collision_moves_to_the_next_system_when_positions_are_taken(): void
    {
        config()->set('app.player_import_password', 'import-test-password');
        config()->set('app.player_import_retry_upon_collision', true);

        $coordinate = $this->coordinateWithFreeNeighbors();
        $suffix = bin2hex(random_bytes(5));
        $occupant = $this->occupySlot($suffix, $coordinate);
        $this->occupySlot($suffix, new Coordinate($coordinate->galaxy, $coordinate->system, $coordinate->position + 1), $occupant);
        $this->occupySlot($suffix, new Coordinate($coordinate->galaxy, $coordinate->system, $coordinate->position - 1), $occupant);

        $email = "import-retry-system-{$suffix}@example.com";
        $importer = resolve(PlayerImporter::class);
        $importer->import($this->temporaryJson($this->documentFor($this->playerData("import-retry-system-{$suffix}", $email, $coordinate))));
        $this->rememberAudit($importer);

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->createdUserIds[] = $user->id;
        $planet = Planet::query()
            ->where('user_id', $user->id)
            ->where('planet_type', PlanetType::Planet->value)
            ->firstOrFail();
        $this->assertSame($coordinate->galaxy, $planet->galaxy);
        $this->assertSame($coordinate->system + 1, $planet->system);
        $this->assertSame($coordinate->position, $planet->planet);
    }

    public function test_retry_upon_collision_aborts_when_the_neighborhood_is_full(): void
    {
        config()->set('app.player_import_password', 'import-test-password');
        config()->set('app.player_import_retry_upon_collision', true);

        $coordinate = $this->getSafeEmptyCoordinate(new Coordinate(1, 300, 8));
        $suffix = bin2hex(random_bytes(5));
        $occupant = $this->occupySlot($suffix, $coordinate);

        for ($systemDelta = -PlanetImporter::MAX_COLLISION_OFFSET; $systemDelta <= PlanetImporter::MAX_COLLISION_OFFSET; $systemDelta++) {
            for ($positionDelta = -PlanetImporter::MAX_COLLISION_OFFSET; $positionDelta <= PlanetImporter::MAX_COLLISION_OFFSET; $positionDelta++) {
                $system = $coordinate->system + $systemDelta;
                $position = $coordinate->position + $positionDelta;
                if (
                    $position < UniverseConstants::MIN_PLANET_POSITION
                    || $position > UniverseConstants::MAX_PLANET_POSITION
                    || $system < UniverseConstants::MIN_SYSTEM
                    || $system > UniverseConstants::MAX_SYSTEM_COUNT
                ) {
                    continue;
                }

                $this->occupySlot($suffix, new Coordinate($coordinate->galaxy, $system, $position), $occupant);
            }
        }

        $email = "import-exhausted-{$suffix}@example.com";
        $importer = resolve(PlayerImporter::class);

        try {
            $importer->import($this->temporaryJson($this->documentFor($this->playerData("import-exhausted-{$suffix}", $email, $coordinate))));
            $this->fail('A full neighborhood should stop the import.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no free slot was found', $exception->getMessage());
        }

        $this->rememberAudit($importer);
        $this->assertNull(User::query()->where('email', $email)->first());
    }

    private function coordinateWithFreeNeighbors(): Coordinate
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $coordinate = $this->getSafeEmptyCoordinate(new Coordinate(1, 280, 8));
            $neighbors = [
                [$coordinate->system, $coordinate->position + 1],
                [$coordinate->system, $coordinate->position - 1],
                [$coordinate->system + 1, $coordinate->position],
                [$coordinate->system - 1, $coordinate->position],
            ];

            foreach ($neighbors as [$system, $position]) {
                if (
                    $position < UniverseConstants::MIN_PLANET_POSITION
                    || $position > UniverseConstants::MAX_PLANET_POSITION
                    || $system < UniverseConstants::MIN_SYSTEM
                    || $system > UniverseConstants::MAX_SYSTEM_COUNT
                ) {
                    continue 2;
                }

                $taken = Planet::query()
                    ->where('galaxy', $coordinate->galaxy)
                    ->where('system', $system)
                    ->where('planet', $position)
                    ->exists();
                if ($taken) {
                    continue 2;
                }
            }

            return $coordinate;
        }

        $this->fail('Failed to find a coordinate with free neighboring slots.');
    }

    private function occupySlot(string $suffix, Coordinate $coordinate, User|null $occupant = null): User
    {
        $occupant ??= User::factory()->create([
            'username' => "occupant-{$suffix}",
            'email' => "occupant-{$suffix}@example.com",
        ]);
        if (!in_array($occupant->id, $this->createdUserIds, true)) {
            $this->createdUserIds[] = $occupant->id;
        }

        $occupied = Planet::query()
            ->where('galaxy', $coordinate->galaxy)
            ->where('system', $coordinate->system)
            ->where('planet', $coordinate->position)
            ->exists();
        if (!$occupied) {
            Planet::factory()->create([
                'user_id' => $occupant->id,
                'galaxy' => $coordinate->galaxy,
                'system' => $coordinate->system,
                'planet' => $coordinate->position,
                'planet_type' => PlanetType::Planet->value,
            ]);
        }

        return $occupant;
    }

    private function scheduledHighscoreJobsAreDue(): bool
    {
        $commands = [
            'ogamex:scheduler:generate-highscores',
            'ogamex:scheduler:generate-alliance-highscores',
            'ogamex:scheduler:generate-highscore-ranks',
        ];
        $events = collect(resolve(Schedule::class)->events())
            ->filter(function ($event) use ($commands): bool {
                foreach ($commands as $command) {
                    if (str_contains((string)$event->command, $command)) {
                        return true;
                    }
                }

                return false;
            });

        $this->assertCount(3, $events);

        foreach ($events as $event) {
            if (!$event->filtersPass($this->app)) {
                return false;
            }
        }

        return true;
    }

    private function rememberAudit(PlayerImporter $importer): void
    {
        if ($importer->auditPath() !== null) {
            $this->temporaryFiles[] = $importer->auditPath();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sparsePlayerData(string $username, string $email, Coordinate $coordinate): array
    {
        return [
            'version' => '1.0',
            'generatedAt' => now()->toIso8601String(),
            'seed' => 2026,
            'players' => [[
                'profile' => [
                    'username' => $username,
                    'email' => $email,
                    'accountAgeDays' => 12,
                    'status' => 'active',
                    'preferredPlaystyle' => 'miner',
                    'class' => 'general',
                ],
                'planets' => [[
                    'kind' => 'planet',
                    'name' => 'Sparse',
                    'isMain' => true,
                    'position' => $coordinate->asString(),
                    'buildings' => [
                        ['code' => 'research_lab', 'level' => 4],
                        ['code' => 'metal_mine', 'level' => 0],
                    ],
                    'fleet' => [
                        ['code' => 'light_fighter', 'amount' => 2],
                        ['code' => 'colony_ship', 'amount' => 0],
                    ],
                    'defenses' => [
                        ['code' => 'heavy_laser', 'amount' => 5],
                        ['code' => 'light_laser', 'amount' => 0],
                    ],
                    'resources' => [
                        ['code' => 'crystal', 'amount' => 0],
                        ['code' => 'deuterium', 'amount' => 80],
                    ],
                ]],
                'researches' => [
                    ['code' => 'energy_technology', 'level' => 3],
                    ['code' => 'laser_technology', 'level' => 0],
                ],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function playerData(string $username, string $email, Coordinate $coordinate): array
    {
        return [
            'profile' => [
                'username' => $username,
                'email' => $email,
                'accountAgeDays' => 30,
                'status' => 'vacation',
                'preferredPlaystyle' => 'fleeter',
                'class' => 'general',
            ],
            'planets' => [[
                'kind' => 'planet',
                'name' => 'Homeworld',
                'isMain' => true,
                'position' => $coordinate->asString(),
                'buildings' => [
                    ['code' => 'metal_mine', 'level' => 5],
                    ['code' => 'research_lab', 'level' => 4],
                ],
                'fleet' => [
                    ['code' => 'small_cargo', 'amount' => 3],
                ],
                'defenses' => [
                    ['code' => 'rocket_launcher', 'amount' => 7],
                ],
                'resources' => [
                    ['code' => 'metal', 'amount' => 1000],
                    ['code' => 'crystal', 'amount' => 500],
                    ['code' => 'deuterium', 'amount' => 250],
                ],
                'moon' => [
                    'kind' => 'moon',
                    'position' => $coordinate->asString(),
                    'size' => 8000,
                    'buildings' => [
                        ['code' => 'lunar_base', 'level' => 2],
                    ],
                    'fleet' => [],
                    'defenses' => [],
                    'resources' => [],
                ],
            ]],
            'researches' => [
                ['code' => 'weapon_technology', 'level' => 4],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $player
     * @return array<string, mixed>
     */
    private function documentFor(array $player): array
    {
        return [
            'version' => '1.0',
            'generatedAt' => now()->toIso8601String(),
            'seed' => 2026,
            'players' => [$player],
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    private function temporaryJson(array $document): string
    {
        $path = tempnam(storage_path('app'), 'player-import-test-');
        if ($path === false) {
            $this->fail('Unable to create temporary player import JSON.');
        }

        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function temporaryGzip(array $document): string
    {
        $path = tempnam(storage_path('app'), 'player-import-test-');
        if ($path === false) {
            $this->fail('Unable to create temporary player import file.');
        }

        $encoded = gzencode((string)json_encode($document, JSON_THROW_ON_ERROR));
        if ($encoded === false) {
            $this->fail('Unable to gzip player import JSON.');
        }

        file_put_contents($path, $encoded);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
