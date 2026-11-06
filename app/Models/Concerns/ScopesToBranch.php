<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait ScopesToBranch
{
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isOwner() && $user->branch_id === null) {
            return $query;
        }

        if ($user->branch_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($query->getModel()->qualifyColumn('branch_id'), $user->branch_id);
    }
}
