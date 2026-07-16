<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\MerchantApplication;
use App\Models\PlatformPlan;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    /**
     * POST /api/public/register-interest
     * Public form submission — creates a merchant application in pending state.
     */
    public function registerInterest(Request $request)
    {
        $data = $request->validate([
            'company_name'                 => 'required|string|max:150',
            'owner_name'                   => 'required|string|max:100',
            'email'                        => 'required|email|max:100',
            'phone'                        => 'required|string|max:30',
            'city'                         => 'nullable|string|max:100',
            'business_type'                => 'nullable|string|max:100',
            'branch_count'                 => 'nullable|integer|min:1|max:999',
            'estimated_monthly_deliveries' => 'nullable|integer|min:1',
            'selected_plan'                => 'nullable|string|max:50',
            'notes'                        => 'nullable|string|max:2000',
        ]);

        $application = MerchantApplication::create($data);

        return response()->json([
            'message' => 'Thank you! Your application has been received. Our team will contact you within 1–2 business days.',
            'data'    => ['id' => $application->id, 'status' => $application->status],
        ], 201);
    }

    /**
     * GET /api/public/plans
     * Returns active plans for display on the public pricing page.
     */
    public function plans()
    {
        $plans = PlatformPlan::active()->get()->map(fn($p) => [
            'name'           => $p->name,
            'slug'           => $p->slug,
            'description'    => $p->description,
            'monthly_price'  => $p->monthly_price,
            'delivery_limit' => $p->delivery_limit,
            'branch_limit'   => $p->branch_limit,
            'driver_limit'   => $p->driver_limit,
            'features'       => $p->features ?? [],
        ]);

        return response()->json(['data' => $plans]);
    }
}
