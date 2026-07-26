<?php

namespace OGame\Console\Commands\PlayerImport;

use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use RuntimeException;

/**
 * Post-import consistency checks using the same requirement rules as the game.
 *
 * Prefer objectRequirementsWithLevelsMet over objectRequirementsMet: both check
 * prerequisite objects, but WithLevelsMet also ensures the object itself is at a
 * reachable level (current level is the imported target, or one below it).
 */
class ImportRequirementsValidator
{
    public function validate(PlayerService $player): void
    {
        $planets = $player->planets->all();
        if ($planets === []) {
            throw new RuntimeException('Imported player has no planets to validate requirements against.');
        }

        $this->validateResearch($player, $planets);
        $this->validateBuildings($planets);
    }

    /**
     * @param array<int, PlanetService> $planets
     */
    private function validateResearch(PlayerService $player, array $planets): void
    {
        foreach (ObjectService::getResearchObjects() as $research) {
            $level = $player->getResearchLevel($research->machine_name);
            if ($level <= 0) {
                continue;
            }

            foreach ($planets as $planet) {
                if (ObjectService::objectRequirementsWithLevelsMet($research->machine_name, $level, $planet)) {
                    continue 2;
                }
            }

            throw new RuntimeException(
                "Research {$research->machine_name} at level {$level} does not meet its requirements on any planet."
            );
        }
    }

    /**
     * @param array<int, PlanetService> $planets
     */
    private function validateBuildings(array $planets): void
    {
        $objects = [...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects()];

        foreach ($planets as $planet) {
            foreach ($objects as $object) {
                if ($object->type !== GameObjectType::Building && $object->type !== GameObjectType::Station) {
                    continue;
                }

                $level = $planet->getObjectLevel($object->machine_name);
                if ($level <= 0) {
                    continue;
                }

                if (!ObjectService::objectRequirementsWithLevelsMet($object->machine_name, $level, $planet)) {
                    throw new RuntimeException(
                        "Building {$object->machine_name} at level {$level} on {$planet->getPlanetCoordinates()->asString()} does not meet its requirements."
                    );
                }
            }
        }
    }
}
