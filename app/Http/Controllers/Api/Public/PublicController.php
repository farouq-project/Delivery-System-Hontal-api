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

    public function features()
    {
        return response()->json([
            'data' => [
                ['slug' => 'dispatch', 'title' => 'Smart Dispatch', 'description' => 'Assign and schedule deliveries in seconds with drag-and-drop route planning.'],
                ['slug' => 'routing', 'title' => 'Route Optimization', 'description' => 'Automatically group stops by area and minimize total travel distance.'],
                ['slug' => 'tracking', 'title' => 'Live Tracking', 'description' => 'Real-time driver GPS visible to dispatchers and optionally to end-customers.'],
                ['slug' => 'customer_domain', 'title' => 'Customer Intelligence', 'description' => 'Order history, behavioral tags, and VIP tiers for every customer.'],
                ['slug' => 'bi', 'title' => 'Business Intelligence', 'description' => 'Delivery cost, success rates, and driver performance dashboards.'],
                ['slug' => 'multi_driver', 'title' => 'Multi-Driver', 'description' => 'Manage unlimited drivers with per-driver route sheets and stop counts.'],
            ],
        ]);
    }

    public function faq()
    {
        return response()->json([
            'data' => [
                ['question' => 'What counts as a delivery credit?', 'answer' => 'One credit is consumed each time an order is created. Credits reset monthly.'],
                ['question' => 'Can I add more credits mid-month?', 'answer' => 'Yes — purchase a credit top-up pack at any time. Credits are added instantly.'],
                ['question' => 'Is there a free trial?', 'answer' => 'Yes, every new account starts with a 7-day free trial on the Growth plan with no credit card required.'],
                ['question' => 'Can I change my plan?', 'answer' => 'You can upgrade or downgrade at any time. Changes take effect on your next billing cycle.'],
                ['question' => 'Do you support multiple branches?', 'answer' => 'Yes — Growth and above support multiple depot locations and per-branch route sheets.'],
            ],
        ]);
    }

    public function testimonials()
    {
        return response()->json([
            'data' => [
                ['name' => 'Distributor Galon', 'role' => 'Owner', 'body' => 'Pengiriman kami 3x lebih cepat setelah pakai Hontal. Sopir tidak lagi bingung urutan rute.'],
                ['name' => 'Toko Sembako Maju', 'role' => 'Owner', 'body' => 'Fitur tracking sangat membantu pelanggan yang ingin tahu kapan barang tiba.'],
                ['name' => 'CV Sejahtera Distribusi', 'role' => 'Dispatcher', 'body' => 'Bisa atur 200+ order dalam satu klik, hemat 2 jam kerja tiap hari.'],
            ],
        ]);
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
