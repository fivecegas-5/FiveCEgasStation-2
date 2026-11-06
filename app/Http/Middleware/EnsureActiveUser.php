<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $retiredManager = $user?->role === UserRole::Manager;
        $unassigned = $user !== null && $user->branch_id === null && ! $user->isOwner();

        if ($user !== null && (! $user->is_active || $retiredManager || $unassigned)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => $retiredManager
                    ? 'Manager accounts are no longer supported. Contact the owner.'
                    : 'This account is inactive or is not assigned to a branch. Contact the owner.',
            ]);
        }

        return $next($request);
    }
}
