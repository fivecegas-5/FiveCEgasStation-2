<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RecoverOwner extends Command
{
    protected $signature = 'app:recover-owner';

    protected $description = 'Find an owner account and securely set a new password';

    public function handle(): int
    {
        $owners = User::query()
            ->where('role', UserRole::Owner->value)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        if ($owners->isEmpty()) {
            $this->error('No owner account exists. Create the initial account with app:create-owner.');

            return self::FAILURE;
        }

        $email = $this->choice(
            'Select the owner account to recover',
            $owners->pluck('email')->all(),
            0,
        );
        $owner = $owners->firstWhere('email', $email);
        $password = $this->secret('New password (at least 10 characters)');
        $confirmation = $this->secret('Confirm new password');

        if (! is_string($password) || strlen($password) < 10 || $password !== $confirmation) {
            $this->error('Passwords must match and contain at least 10 characters.');

            return self::FAILURE;
        }

        $owner->forceFill([
            'password' => Hash::make($password),
            'remember_token' => Str::random(60),
        ])->save();

        $this->info("Owner password reset for {$owner->email}. Sign in at /login.");

        return self::SUCCESS;
    }
}
