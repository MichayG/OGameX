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
    public function __construct(
        private PlanetServiceFactory $planetServiceFactory,
        private CelestialBodyContentApplier $celestialBodyContentApplier,
        private SettingsService $settingsService
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $planets
     */
    public function import(PlayerService $player, array $planets): void
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

        foreach ($planets as $planetData) {
            $coordinate = $this->parseCoordinate($planetData['position']);
            $planet = $this->planetServiceFactory->createPlanetAtPosition(
                $player,
                $coordinate,
                $planetData['name']
            );

            // createPlanetAtPosition does not refresh the owning player's planet list.
            $player->load($player->getId());
            $this->celestialBodyContentApplier->apply($planet, $planetData);

            $planetId = $planet->getPlanetId();
            $firstPlanetId ??= $planetId;
            if (!empty($planetData['isMain'])) {
                $currentPlanetId = $planetId;
            }

            if (isset($planetData['moon'])) {
                $this->importMoon($player, $planet, $planetData['moon'], $coordinate);
            }
        }

        $player->setCurrentPlanetId($currentPlanetId ?? $firstPlanetId);
    }

    /**
     * @param array<string, mixed> $moonData
     */
    private function importMoon(
        PlayerService $player,
        PlanetService $planet,
        array $moonData,
        Coordinate $planetCoordinate
    ): void {
        $moonCoordinate = $this->parseCoordinate($moonData['position']);
        if (!$moonCoordinate->equals($planetCoordinate)) {
            throw new RuntimeException(
                "Moon position {$moonCoordinate->asString()} does not match its planet {$planetCoordinate->asString()}."
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
