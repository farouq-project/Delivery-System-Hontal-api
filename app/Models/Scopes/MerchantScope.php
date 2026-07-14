<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Automatically filters Eloquent queries by the authenticated user's merchant_id.
 *
 * Bypass conditions (scope does not apply):
 *  - No authenticated user (guest context)
 *  - User is super_admin (cross-merchant access required)
 *  - User has no merchant_id (super_admin/developer with null merchant)
 *
 * Usage: add `static::addGlobalScope(new MerchantScope())` inside a model's
 * `booted()` method. For one-off queries that need cross-merchant access,
 * call `Model::withoutGlobalScope(MerchantScope::class)->...`.
 */
class MerchantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (!Auth::check()) {
            return;
        }

        $user = Auth::user();

        // super_admin and platform developer bypass — they operate cross-merchant
        if ($user->isSuperAdmin() || ($user->merchant_id === null)) {
            return;
        }

        $builder->where($model->getTable() . '.merchant_id', $user->merchant_id);
    }
}
