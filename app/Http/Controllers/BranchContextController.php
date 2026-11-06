<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchContextController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
        ]);
        abort_unless($request->user()->canAccessBranch((int) $data['branch_id']), 404);

        $request->session()->put('active_branch_id', (int) $data['branch_id']);

        return back()->with('success', 'Active branch updated.');
    }
}
