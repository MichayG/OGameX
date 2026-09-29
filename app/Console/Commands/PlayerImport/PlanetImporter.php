<?php

namespace OGame\Console\Commands\PlayerImport;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameConstants\UniverseConstants;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use RuntimeException;

class PlanetImporter
{
    /**
     * How far from the requested slot to search when retry-upon-collision is enabled.
     * Each offset fills the next square around the original coordinate. Galaxy is never changed.
     */
    public const int MAX_COLLISION_OFFSET = 15;

    public function __construct(
        private PlanetServiceFactory $planetServiceFactory,
        private CelestialBodyContentApplier $celestialBodyContentApplier,
        private SettingsService $settingsService
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $planets
     * @return list<array{username: string, planetName: string, from: string, to: string}>
     */
    public function import(PlayerService $player, array $planets): array
    {
        Validator::make(['planets' => $planets], [
            'planets' => ['required', 'array', 'min:1'],
            'planets.*' => ['array'],
            'planets.*.kind' => ['required', Rule::in(['planet'])],
            'planets.*.name' => ['required', 'string'],
            'planets.*.isMain' => ['sometimes', 'boolean'],
            'planets.*.position' => ['required', 'string', 'regex:/^\d+:\d+:\d+$/'],
            // Planet diameter/fields come from createPlanetAtPosition.
            'planets.*.size' => ['prohibited'],
            'planets.*.moon' => ['nullable', 'array'],
            'planets.*.moon.kind' => ['required_with:planets.*.moon', Rule::in(['moon'])],
            'planets.*.moon.position' => ['required_with:planets.*.moon', 'string', 'regex:/^\d+:\d+:\d+$/'],
            'planets.*.moon.size' => ['required_with:planets.*.moon', 'integer', 'between:3162,8944'],
        ])->validate();

        $currentPlanetId = null;
        $firstPlanetId = null;
        $relocations = [];

        foreach ($planets as $planetData) {
            $requested = $this->parseCoordinate($planetData['position']);
            [$planet, $placed] = $this->createPlanet($player, $requested, $planetData['name']);
            if (!$placed->equals($requested)) {
                $relocations[] = [
                    'username' => $player->getUser()->username,
                    'planetName' => $planetData['name'],
                    'from' => $requested->asString(),
                    'to' => $placed->asString(),
                ];
            }

            // createPlanetAtPosition does not refresh the owning player's planet list.
            $player->load($player->getId());
            $this->celestialBodyContentApplier->apply($planet, $planetData);

            $planetId = $planet->getPlanetId();
            $firstPlanetId ??= $planetId;
            if (!empty($planetData['isMain'])) {
                $currentPlanetId = $planetId;
            }

            if (isset($planetData['moon'])) {
                $this->importMoon($player, $planet, $planetData['moon'], $placed, $requested);
            }
        }

        $player->setCurrentPlanetId($currentPlanetId ?? $firstPlanetId);

        return $relocations;
    }

    /**
     * @return array{0: PlanetService, 1: Coordinate}
     */
    private function createPlanet(PlayerService $player, Coordinate $requested, string $name): array
    {
        if (!$this->retryUponCollision()) {
            return [
                $this->planetServiceFactory->createPlanetAtPosition($player, $requested, $name),
                $requested,
            ];
        }

        foreach ($this->candidateCoordinates($requested) as $candidate) {
            if ($this->planetServiceFactory->planetExistsAtCoordinate($candidate)) {
                continue;
            }

            return [
                $this->planetServiceFactory->createPlanetAtPosition($player, $candidate, $name),
                $candidate,
            ];
        }

        throw new RuntimeException(
            "Position {$requested->asString()} is already occupied and no free slot was found within "
            . self::MAX_COLLISION_OFFSET . ' positions or systems.'
        );
    }

    /**
     * Requested coordinate, then the next square around it, one offset at a time.
     * Galaxy stays fixed. Out-of-range slots are left out.
     *
     * For 4:83:8 the list starts:
     * 4:83:8,
     * 4:83:9, 4:83:7, 4:84:8, 4:82:8,
     * 4:84:9, 4:82:9, 4:84:7, 4:82:7,
     * 4:83:10, 4:83:6, 4:84:10, 4:82:6,
     * ...
     *
     * @return list<Coordinate>
     */
    private function candidateCoordinates(Coordinate $requested): array
    {
        $candidates = [$requested];

        for ($offset = 1; $offset <= self::MAX_COLLISION_OFFSET; $offset++) {
            $this->appendOffset($candidates, $requested, 0, $offset);
            $this->appendOffset($candidates, $requested, 0, -$offset);

            for ($step = 1; $step < $offset; $step++) {
                $this->appendOffset($candidates, $requested, $step, $offset);
                $this->appendOffset($candidates, $requested, -$step, -$offset);
                $this->appendOffset($candidates, $requested, $step, -$offset);
                $this->appendOffset($candidates, $requested, -$step, $offset);
            }

            $this->appendOffset($candidates, $requested, $offset, 0);
            $this->appendOffset($candidates, $requested, -$offset, 0);

            for ($step = 1; $step < $offset; $step++) {
                $this->appendOffset($candidates, $requested, $offset, $step);
                $this->appendOffset($candidates, $requested, -$offset, $step);
                $this->appendOffset($candidates, $requested, $offset, -$step);
                $this->appendOffset($candidates, $requested, -$offset, -$step);
            }

            $this->appendOffset($candidates, $requested, $offset, $offset);
            $this->appendOffset($candidates, $requested, -$offset, $offset);
            $this->appendOffset($candidates, $requested, $offset, -$offset);
            $this->appendOffset($candidates, $requested, -$offset, -$offset);
        }

        return $candidates;
    }

    /**
     * @param list<Coordinate> $candidates
     */
    private function appendOffset(array &$candidates, Coordinate $requested, int $systemDelta, int $positionDelta): void
    {
        $system = $requested->system + $systemDelta;
        $position = $requested->position + $positionDelta;
        if (
            $system < UniverseConstants::MIN_SYSTEM
            || $system > UniverseConstants::MAX_SYSTEM_COUNT
            || $position < UniverseConstants::MIN_PLANET_POSITION
            || $position > UniverseConstants::MAX_PLANET_POSITION
        ) {
            return;
        }

        $candidates[] = new Coordinate($requested->galaxy, $system, $position);
    }

    private function retryUponCollision(): bool
    {
        return filter_var(config('app.player_import_retry_upon_collision', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string, mixed> $moonData
     */
    private function importMoon(
        PlayerService $player,
        PlanetService $planet,
        array $moonData,
        Coordinate $placedCoordinate,
        Coordinate $requestedCoordinate
    ): void {
        $moonCoordinate = $this->parseCoordinate($moonData['position']);
        // The export stores the moon on the planet's original coordinate, which
        // is $requestedCoordinate. After a move that value still matches, and
        // $placedCoordinate is only the fallback if the file already names the
        // new slot. createMoonForPlanet() then puts the moon on the planet.
        if (!$moonCoordinate->equals($placedCoordinate) && !$moonCoordinate->equals($requestedCoordinate)) {
            throw new RuntimeException(
                "Moon position {$moonCoordinate->asString()} does not match its planet {$requestedCoordinate->asString()}."
            );
        }

        $moon = $this->planetServiceFactory->createMoonForPlanet($planet, 0, 0);

        // No service setter exists, and debris-free factory moons cannot preserve
        // the source diameter. Keep field_max as calculated by the factory.
        // Diameter bounds match PlanetServiceFactory::setupMoonProperties:
        // min = floor(sqrt(10) * 1000), max = floor(sqrt(20 + 60) * 1000) with 2M debris cap.
        $minDiameter = (int)floor(pow(10, 0.5) * 1000); // 3162 km
        $maxDiameter = (int)floor(pow(20 + (3 * 2000000 / 100000), 0.5) * 1000); // 8944 km
        $moonModel = Planet::query()->findOrFail($moon->getPlanetId());
        $moonModel->diameter = max($minDiameter, min($maxDiameter, (int)$moonData['size']));
        $moonModel->save();

        $moon = $this->planetServiceFactory->makeForPlayer($player, $moonModel->id, false);
        $this->celestialBodyContentApplier->apply($moon, $moonData);
        $player->load($player->getId());
    }

    private function parseCoordinate(string $position): Coordinate
    {
        $parts = array_map('intval', explode(':', $position));
        if (count($parts) !== 3) {
            throw new RuntimeException("Invalid coordinate: {$position}");
        }

        [$galaxy, $system, $planet] = $parts;
        if (
            $galaxy < UniverseConstants::MIN_GALAXY
            || $galaxy > $this->settingsService->numberOfGalaxies()
            || $system < UniverseConstants::MIN_SYSTEM
            || $system > UniverseConstants::MAX_SYSTEM_COUNT
            || $planet < UniverseConstants::MIN_PLANET_POSITION
            || $planet > UniverseConstants::MAX_PLANET_POSITION
        ) {
            throw new RuntimeException("Coordinate is outside the configured universe: {$position}");
        }

        return new Coordinate($galaxy, $system, $planet);
    }
}
