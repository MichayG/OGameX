<?php

namespace OGame\Console\Commands\PlayerImport;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use OGame\Enums\CharacterClass;
use OGame\Models\Ban;
use OGame\Models\User;
use RuntimeException;

class AccountImporter
{
    public function ensurePasswordIsConfigured(): void
    {
        if ($this->password() === '') {
            throw new RuntimeException('PLAYER_IMPORT_PASSWORD must be set before importing players.');
        }
    }

    /**
     * @param array<string, mixed> $profile
     */
    public function import(array $profile): User
    {
        $validated = Validator::make($profile, [
            'username' => ['required', 'string', 'max:255', Rule::unique(User::class, 'username')],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'accountAgeDays' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'string', Rule::in(['active', 'banned', 'vacation', 'inactive'])],
            'class' => ['nullable', 'string', Rule::in(['collector', 'general', 'discoverer'])],
        ])->validate();

        $registeredAt = now()->subDays((int)$validated['accountAgeDays']);
        $characterClass = $this->characterClass($validated['class'] ?? null);
        $isVacation = $validated['status'] === 'vacation';
        // Galaxy inactivity is derived from last activity, not a stored status.
        // Seven days is the threshold for the short inactive marker.
        $lastActivity = $validated['status'] === 'inactive' ? now()->subDays(7) : now();

        // Importing is not a registration: suppress the first-user rename and
        // registration-only rewards while retaining normal Eloquent persistence.
        $user = User::withoutEvents(function () use ($validated, $registeredAt, $characterClass, $isVacation, $lastActivity): User {
            $user = new User();
            $user->username = $validated['username'];
            $user->email = $validated['email'];
            $user->password = Hash::make($this->password());
            $user->lang = 'en';
            $user->register_time = (string)$registeredAt->timestamp;
            // Last activity timestamp (galaxy inactive status / online checks).
            $user->time = (string)$lastActivity->timestamp;
            $user->character_class = $characterClass?->value;
            $user->character_class_free_used = $characterClass !== null;
            $user->character_class_changed_at = $characterClass !== null ? $registeredAt : null;
            $user->first_login = $characterClass === null;
            $user->vacation_mode = $isVacation;
            $user->vacation_mode_activated_at = $isVacation ? now()->subDays(2) : null;
            $user->vacation_mode_until = null;
            $user->save();

            return $user;
        });

        if ($validated['status'] === 'banned') {
            Ban::create([
                'user_id' => $user->id,
                'reason' => 'Imported as a banned player',
                'banned_until' => null,
                'canceled' => false,
                'canceled_at' => null,
            ]);
        }

        return $user;
    }

    private function password(): string
    {
        return trim((string)config('app.player_import_password', ''));
    }

    private function characterClass(mixed $class): CharacterClass|null
    {
        return match ($class) {
            'collector' => CharacterClass::COLLECTOR,
            'general' => CharacterClass::GENERAL,
            'discoverer' => CharacterClass::DISCOVERER,
            default => null,
        };
    }
}
