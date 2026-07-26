<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Hash;
use OGame\Console\Commands\PlayerImport\PlayerImporter;
use OGame\Console\Commands\PlayerImport\PlayerImportRollback;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use OGame\Models\UserTech;
use RuntimeException;
use Tests\TestCase;

class PlayerImportTest extends TestCase
{
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
}
