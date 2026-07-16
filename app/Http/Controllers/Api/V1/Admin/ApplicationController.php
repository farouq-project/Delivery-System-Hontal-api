<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\MerchantApplication;
use App\Services\MerchantProvisioningService;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function __construct(
        private readonly MerchantProvisioningService $provisioningService
    ) {}

    /**
     * GET /api/v1/admin/applications
     */
    public function index(Request $request)
    {
        $query = MerchantApplication::query()
            ->with('approvedBy:id,name')
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->search, fn($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('company_name', 'like', "%{$s}%")
                  ->orWhere('owner_name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%");
            }))
            ->orderByDesc('created_at');

        return response()->json($query->paginate($request->per_page ?? 25));
    }

    /**
     * GET /api/v1/admin/applications/{application}
     */
    public function show(MerchantApplication $application)
    {
        return response()->json(['data' => $application->load('approvedBy:id,name')]);
    }

    /**
     * PATCH /api/v1/admin/applications/{application}/approve
     * Triggers MerchantProvisioningService — creates merchant, user, subscription, features.
     */
    public function approve(Request $request, MerchantApplication $application)
    {
        if (!$application->isPending()) {
            return response()->json(['message' => 'Only pending applications can be approved.'], 422);
        }

        try {
            $result = $this->provisioningService->provision($application, $request->user()->id);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Provisioning failed: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Application approved. Merchant workspace provisioned.',
            'data'    => [
                'merchant_id'    => $result['merchant']->id,
                'merchant_name'  => $result['merchant']->company_name,
                'user_email'     => $result['user']->email,
                'temp_password'  => $result['temp_password'],
                'plan'           => $result['plan']?->name,
                'trial_ends_at'  => $result['subscription']->trial_ends_at?->toDateString(),
            ],
        ]);
    }

    /**
     * PATCH /api/v1/admin/applications/{application}/reject
     */
    public function reject(Request $request, MerchantApplication $application)
    {
        if (!$application->isPending()) {
            return response()->json(['message' => 'Only pending applications can be rejected.'], 422);
        }

        $data = $request->validate(['notes' => 'nullable|string|max:2000']);

        $application->update([
            'status'      => 'rejected',
            'notes'       => $data['notes'] ?? $application->notes,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return response()->json(['message' => 'Application rejected.', 'data' => $application]);
    }

    /**
     * PATCH /api/v1/admin/applications/{application}/notes
     */
    public function notes(Request $request, MerchantApplication $application)
    {
        $data = $request->validate(['notes' => 'required|string|max:2000']);
        $application->update(['notes' => $data['notes']]);
        return response()->json(['data' => $application]);
    }

    /**
     * DELETE /api/v1/admin/applications/{application}
     */
    public function destroy(MerchantApplication $application)
    {
        $application->delete();
        return response()->json(null, 204);
    }
}
