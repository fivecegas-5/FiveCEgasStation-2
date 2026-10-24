<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\BranchSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateOwner extends Command
{
    protected $signature = 'app:create-owner';

    protected $description = 'Create the first FiveCETrack owner account interactively';

    public function handle(): int
    {
        if (User::where('role', UserRole::Owner->value)->exists()) {
            $this->error('An owner account already exists. Sign in and create additional accounts from User Management.');

            return self::FAILURE;
        }

        app(BranchSeeder::class)->run();
        $name = $this->ask('Owner name');
        $email = $this->ask('Owner email');
        $password = $this->secret('Password (at least 10 characters)');
        $confirmation = $this->secret('Confirm password');

        if (! is_string($name) || trim($name) === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid name and email are required.');

            return self::FAILURE;
        }

        if (! is_string($password) || strlen($password) < 10 || $password !== $confirmation) {
            $this->error('Passwords must match and contain at least 10 characters.');

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error('That email address is already registered.');

            return self::FAILURE;
        }

        User::create([
            'name' => trim($name),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => UserRole::Owner,
            'branch_id' => null,
        ]);

        $this->info('Owner account created. Sign in at /login.');

        return self::SUCCESS;
    }
}
