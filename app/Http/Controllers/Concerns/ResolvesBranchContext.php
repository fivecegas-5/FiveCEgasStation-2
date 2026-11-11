<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

trait ResolvesBranchContext
{
    protected function branchId(Request $request): int
    {
        $data = $request->validate([
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
        ]);
        $user = $request->user();

        if ($user->branch_id !== null) {
            abort_unless(! isset($data['branch_id']) || $user->canAccessBranch((int) $data['branch_id']), 404);

            return $user->branch_id;
        }

        abort_unless($user->isOwner(), 403);
        $branchId = $data['branch_id'] ?? $request->session()->get('active_branch_id');
        $branchId ??= Branch::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->value('id');
        abort_unless($branchId !== null, 422, 'No active branch is available for branch operations.');
        abort_unless($user->canAccessBranch((int) $branchId), 404);
        abort_unless(
            Branch::query()->whereKey($branchId)->where('is_active', true)->exists(),
            404,
            'The selected branch is inactive.',
        );

        $request->session()->put('active_branch_id', (int) $branchId);

        return (int) $branchId;
    }
}
