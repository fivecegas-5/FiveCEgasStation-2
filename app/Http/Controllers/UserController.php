<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $users = User::query()->with('branch')->orderBy('name');
        $branches = Branch::query()->orderBy('name');

        if ($user->role !== UserRole::Owner) {
            abort_unless($user->branch_id !== null, 403);
            $users->where('branch_id', $user->branch_id);
            $branches->whereKey($user->branch_id);
        }

        return view('users.index', [
            'users' => $users->get(),
            'branches' => $branches->get(),
            'canCreateManager' => $user->role === UserRole::Owner,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $allowedRoles = $actor->role === UserRole::Owner
            ? [UserRole::Owner->value, UserRole::Manager->value, UserRole::Staff->value]
            : [UserRole::Staff->value];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in($allowedRoles)],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where('is_active', true),
                Rule::requiredIf(fn (): bool => $request->input('role') !== UserRole::Owner->value),
            ],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        if ($actor->role !== UserRole::Owner) {
            abort_unless($actor->branch_id !== null, 403);
            $data['branch_id'] = $actor->branch_id;
        }

        if ($data['role'] === UserRole::Owner->value) {
            $data['branch_id'] = null;
        }

        $created = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => UserRole::from($data['role']),
            'branch_id' => $data['branch_id'] ?? null,
        ]);

        AuditLog::create([
            'branch_id' => $created->branch_id,
            'user_id' => $actor->id,
            'action' => 'user.created',
            'subject_type' => User::class,
            'subject_id' => $created->id,
            'before' => null,
            'after' => ['name' => $created->name, 'email' => $created->email, 'role' => $created->role->value, 'branch_id' => $created->branch_id],
            'occurred_at' => now(),
        ]);

        return redirect()->route('users.index')->with('success', 'User account created.');
    }
}
