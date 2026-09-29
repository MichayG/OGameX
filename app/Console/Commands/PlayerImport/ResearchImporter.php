<?php

namespace OGame\Console\Commands\PlayerImport;

use Illuminate\Support\Facades\Validator;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;

class ResearchImporter
{
    public function __construct(
        private MachineNameMapper $machineNameMapper
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $researches
     */
    public function import(PlayerService $player, array $researches): void
    {
        // Researches omitted from the export stay at level 0. An explicit 0 is still accepted.
        $validated = Validator::make(['researches' => $researches], [
            'researches' => ['array'],
            'researches.*.code' => ['required', 'string', 'distinct'],
            'researches.*.level' => ['required', 'integer', 'min:0'],
        ])->validate();

        foreach ($validated['researches'] as $research) {
            $machineName = $this->machineNameMapper->map($research['code']);

            // Resolve first so unknown or incorrectly categorized codes fail clearly.
            ObjectService::getResearchObjectByMachineName($machineName);
            $player->setResearchLevel($machineName, (int)$research['level']);
        }
    }
}
