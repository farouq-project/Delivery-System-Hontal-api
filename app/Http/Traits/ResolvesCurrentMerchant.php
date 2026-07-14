<?php

namespace App\Http\Traits;

use App\Models\Merchant;
use Illuminate\Http\Request;

/**
 * Provides a single, shared implementation of merchant authorization that was
 * previously duplicated as a private method in every controller.
 *
 * Usage: `use ResolvesCurrentMerchant;`
 *
 * Then call either:
 *   $this->authorizeMerchant($request, $resource->merchant_id);
 *   $merchant = $this->currentMerchant($request);
 */
trait ResolvesCurrentMerchant
{
    /**
     * Abort 403 if the authenticated user does not belong to the given merchant
     * (super_admin is always allowed through).
     */
    protected function authorizeMerchant(Request $request, int $merchantId): void
    {
        if ($request->user()->merchant_id !== $merchantId && !$request->user()->isSuperAdmin()) {
            abort(403, 'Access denied.');
        }
    }

    /**
     * Return the merchant the authenticated user belongs to.
     * Super-admin may pass a merchant_id in the request body/query to act on a
     * specific merchant; all other users are bound to their own merchant.
     */
    protected function currentMerchant(Request $request): Merchant
    {
        $user = $request->user();

        if ($user->isSuperAdmin() && $request->filled('merchant_id')) {
            return Merchant::findOrFail($request->input('merchant_id'));
        }

        return $user->merchant;
    }
}
