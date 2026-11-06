<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $users = User::query()
            ->with('branch')
            ->where('role', UserRole::Staff->value)
            ->whereNotNull('branch_id')
            ->orderBy('name');
        $branches = Branch::query()->where('is_active', true)->orderBy('name');

        if ($user->branch_id !== null) {
            $users->where('branch_id', $user->branch_id);
            $branches->whereKey($user->branch_id);
        } else {
            abort_unless($user->isOwner(), 403);
        }

        return view('users.index', [
            'users' => $users->get(),
            'branches' => $branches->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->isOwner(), 403);
        $allowedRoles = [UserRole::Staff->value];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in($allowedRoles)],
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')->where('is_active', true),
            ],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        abort_unless($actor->canAccessBranch((int) $data['branch_id']), 404);

        DB::transaction(function () use ($data, $actor): void {
            $created = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => UserRole::from($data['role']),
                'branch_id' => $data['branch_id'] ?? null,
                'is_active' => true,
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
        });

        return redirect()->route('users.index')->with('success', 'User account created.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        $targetQuery = User::query()->whereKey($user->id);
        abort_unless($actor->isOwner(), 403);
        $targetQuery->where('role', UserRole::Staff->value)->whereNotNull('branch_id');
        if ($actor->branch_id !== null) {
            $targetQuery->where('branch_id', $actor->branch_id);
        }

        $target = $targetQuery->firstOrFail();
        $requestedBranchId = (int) $request->input('branch_id', $target->branch_id);
        abort_unless(
            $request->input('role', UserRole::Staff->value) === UserRole::Staff->value
                && $actor->canAccessBranch($requestedBranchId),
            403,
        );

        $allowedRoles = [UserRole::Staff->value];
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target->id)],
            'role' => ['sometimes', 'required', Rule::in($allowedRoles)],
            'branch_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('branches', 'id')->where('is_active', true),
            ],
            'password' => ['sometimes', 'nullable', 'string', 'min:10', 'confirmed'],
        ]);
        $data['branch_id'] = $requestedBranchId;
        $data['role'] = UserRole::Staff->value;

        DB::transaction(function () use ($target, $data, $actor): void {
            $lockedTarget = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $newRole = UserRole::from($data['role'] ?? $lockedTarget->role->value);
            $before = [
                'name' => $lockedTarget->name,
                'email' => $lockedTarget->email,
                'role' => $lockedTarget->role->value,
                'branch_id' => $lockedTarget->branch_id,
            ];
            $changes = collect($data)->only(['name', 'email', 'branch_id'])->all();
            if (array_key_exists('role', $data)) {
                $changes['role'] = $newRole;
            }
            if (! empty($data['password'])) {
                $changes['password'] = Hash::make($data['password']);
            }

            $lockedTarget->update($changes);
            AuditLog::create([
                'branch_id' => $lockedTarget->branch_id ?? $before['branch_id'],
                'user_id' => $actor->id,
                'action' => 'user.updated',
                'subject_type' => User::class,
                'subject_id' => $lockedTarget->id,
                'before' => $before,
                'after' => [
                    'name' => $lockedTarget->name,
                    'email' => $lockedTarget->email,
                    'role' => $lockedTarget->role->value,
                    'branch_id' => $lockedTarget->branch_id,
                    'password_changed' => ! empty($data['password']),
                ],
                'occurred_at' => now(),
            ]);
        });

        return redirect()->route('users.index')->with('success', 'User account updated.');
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        $targetQuery = User::query()->whereKey($user->id);
        abort_unless($actor->isOwner(), 403);
        $targetQuery->where('role', UserRole::Staff->value)->whereNotNull('branch_id');
        if ($actor->branch_id !== null) {
            $targetQuery->where('branch_id', $actor->branch_id);
        }

        $target = $targetQuery->firstOrFail();
        abort_if($target->id === $actor->id, 422, 'You cannot deactivate your own account.');
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $newStatus = (bool) $data['is_active'];

        DB::transaction(function () use ($request, $target, $newStatus): void {
            $lockedTarget = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            $before = ['is_active' => $lockedTarget->is_active];
            $lockedTarget->update(['is_active' => $newStatus]);
            AuditLog::create([
                'branch_id' => $lockedTarget->branch_id,
                'user_id' => $request->user()->id,
                'action' => $newStatus ? 'user.activated' : 'user.deactivated',
                'subject_type' => User::class,
                'subject_id' => $lockedTarget->id,
                'before' => $before,
                'after' => ['is_active' => $newStatus],
                'occurred_at' => now(),
            ]);
        });

        return redirect()->route('users.index')->with('success', 'User account status updated.');
    }
}
