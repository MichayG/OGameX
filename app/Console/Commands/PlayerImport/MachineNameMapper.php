<?php

namespace OGame\Console\Commands\PlayerImport;

class MachineNameMapper
{
    /**
     * Compatibility aliases for generator output.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'nanite_factory' => 'nano_factory',
    ];

    public function map(string $code): string
    {
        return self::ALIASES[$code] ?? $code;
    }
}
