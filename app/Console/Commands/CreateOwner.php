<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\BranchSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateOwner extends Command
{
    private const OWNER_NAME = 'Clervy Ann M. Esteves';

    private const OWNER_EMAIL = 'fivecegas8@gmail.com';

    protected $signature = 'app:create-owner';

    protected $description = 'Create the global FiveCETrack owner account interactively';

    public function handle(): int
    {
        app(BranchSeeder::class)->run();

        $existing = User::query()->where('email', self::OWNER_EMAIL)->first();
        if ($existing !== null) {
            if ($existing->isOwner() && $existing->branch_id === null && $existing->is_active) {
                $this->info('The global owner account is already set up.');

                return self::SUCCESS;
            }

            $this->error('The owner email already belongs to an account. Review it before creating or promoting an account.');

            return self::FAILURE;
        }

        if (User::query()->where('role', UserRole::Owner->value)->whereNull('branch_id')->where('is_active', true)->exists()) {
            $this->error('An active global owner account already exists.');

            return self::FAILURE;
        }

        if (! Branch::query()->where('is_active', true)->exists()) {
            $this->error('No active branches are configured.');

            return self::FAILURE;
        }

        $name = $this->ask('Owner name', self::OWNER_NAME);
        $password = $this->secret('Password (at least 10 characters)');
        $confirmation = $this->secret('Confirm password');

        if (! is_string($name) || trim($name) === '') {
            $this->error('A valid owner name is required.');

            return self::FAILURE;
        }

        if (! is_string($password) || strlen($password) < 10 || $password !== $confirmation) {
            $this->error('Passwords must match and contain at least 10 characters.');

            return self::FAILURE;
        }

        User::create([
            'name' => trim($name),
            'email' => self::OWNER_EMAIL,
            'password' => Hash::make($password),
            'role' => UserRole::Owner,
            'branch_id' => null,
            'is_active' => true,
        ]);

        $this->info('The global owner account was created for both branches.');

        return self::SUCCESS;
    }
}
