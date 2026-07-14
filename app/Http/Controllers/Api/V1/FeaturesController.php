<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\FeatureManager;
use Illuminate\Http\Request;

class FeaturesController extends Controller
{
    public function __construct(private readonly FeatureManager $features) {}

    public function index(Request $request)
    {
        $user = $request->user();

        if (in_array($user->role, ['super_admin', 'developer'])) {
            return response()->json(['data' => [
                'customer_domain'     => true,
                'executive_dashboard' => true,
            ]]);
        }

        $merchantId = $user->merchant_id;

        if (!$merchantId) {
            return response()->json(['data' => [
                'customer_domain'     => false,
                'executive_dashboard' => false,
            ]]);
        }

        return response()->json(['data' => [
            'customer_domain'     => $this->features->isEnabled($merchantId, 'customer_domain'),
            'executive_dashboard' => $this->features->isEnabled($merchantId, 'executive_dashboard'),
        ]]);
    }
}
