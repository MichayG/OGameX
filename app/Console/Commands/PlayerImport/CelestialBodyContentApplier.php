<?php

namespace OGame\Console\Commands\PlayerImport;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use OGame\GameObjects\Models\DefenseObject;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use RuntimeException;

class CelestialBodyContentApplier
{
    public function __construct(
        private MachineNameMapper $machineNameMapper
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public function apply(PlanetService $planet, array $body): void
    {
        // Buildings, ships, defenses, and resources omitted from the export are
        // level/amount 0. An explicit 0 may still be present.
        $payload = [
            'buildings' => $body['buildings'] ?? [],
            'fleet' => $body['fleet'] ?? [],
            'defenses' => $body['defenses'] ?? [],
            'resources' => $body['resources'] ?? [],
        ];

        $validated = Validator::make($payload, [
            'buildings' => ['array'],
            'buildings.*.code' => ['required', 'string', 'distinct'],
            'buildings.*.level' => ['required', 'integer', 'min:0'],
            'fleet' => ['array'],
            'fleet.*.code' => ['required', 'string', 'distinct'],
            'fleet.*.amount' => ['required', 'integer', 'min:0'],
            'defenses' => ['array'],
            'defenses.*.code' => ['required', 'string', 'distinct'],
            'defenses.*.amount' => ['required', 'integer', 'min:0'],
            'resources' => ['array'],
            'resources.*.code' => [
                'required',
                'string',
                'distinct',
                Rule::in(['metal', 'crystal', 'deuterium']),
            ],
            'resources.*.amount' => ['required', 'integer', 'min:0'],
        ])->validate();

        foreach ($validated['buildings'] as $building) {
            $machineName = $this->machineNameMapper->map($building['code']);
            $object = ObjectService::getObjectByMachineName($machineName);
            $planet->setObjectLevel($object->id, (int)$building['level'], false);
        }

        foreach ($validated['fleet'] as $ship) {
            $machineName = $this->machineNameMapper->map($ship['code']);
            ObjectService::getShipObjectByMachineName($machineName);

            if ((int)$ship['amount'] > 0) {
                $planet->addUnit($machineName, (int)$ship['amount'], false);
            }
        }

        foreach ($validated['defenses'] as $defense) {
            $machineName = $this->machineNameMapper->map($defense['code']);
            // getUnitObjectByMachineName accepts ships and defenses; reject ships listed under defenses.
            $object = ObjectService::getUnitObjectByMachineName($machineName);
            if (!$object instanceof DefenseObject) {
                throw new RuntimeException("{$machineName} is not a defense object.");
            }

            if ((int)$defense['amount'] > 0) {
                $planet->addUnit($machineName, (int)$defense['amount'], false);
            }
        }

        $current = $planet->getResources();
        // Planets are created with starting metal and crystal, so unspecified
        // resources are forced to 0 rather than left at those starting amounts.
        $targets = [
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ];

        foreach ($validated['resources'] as $resource) {
            $targets[$resource['code']] = (int)$resource['amount'];
        }

        $planet->addResources(new Resources(
            $targets['metal'] - $current->metal->get(),
            $targets['crystal'] - $current->crystal->get(),
            $targets['deuterium'] - $current->deuterium->get(),
        ), false);

        $planet->save();
    }
}
