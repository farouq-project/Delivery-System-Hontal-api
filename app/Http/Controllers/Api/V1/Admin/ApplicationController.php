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

    public function show(MerchantApplication $application)
    {
        return response()->json(['data' => $application->load('approvedBy:id,name')]);
    }

    public function approve(Request $request, MerchantApplication $application)
    {
        if (!$application->isActionable()) {
            return response()->json(['message' => 'Only pending or review applications can be approved.'], 422);
        }

        $merchantType = $application->merchant_type_requested ?? 'sistem';

        try {
            $result = $this->provisioningService->provision($application, $request->user()->id, $merchantType);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Provisioning failed: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Application approved. Merchant workspace provisioned.',
            'data'    => [
                'merchant_id'   => $result['merchant']->id,
                'merchant_name' => $result['merchant']->company_name,
                'user_email'    => $result['user']->email,
                'temp_password' => $result['temp_password'],
                'plan'          => $result['plan']?->name,
                'trial_ends_at' => $result['subscription']->trial_ends_at?->toDateString(),
                'merchant_type' => $merchantType,
            ],
        ]);
    }

    public function approveAsKirim(Request $request, MerchantApplication $application)
    {
        if (!$application->isActionable()) {
            return response()->json(['message' => 'Only pending or review applications can be approved.'], 422);
        }

        try {
            $result = $this->provisioningService->provision($application, $request->user()->id, 'kirim');
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Provisioning failed: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Application approved as Kirim merchant. Workspace provisioned.',
            'data'    => [
                'merchant_id'   => $result['merchant']->id,
                'merchant_name' => $result['merchant']->company_name,
                'user_email'    => $result['user']->email,
                'temp_password' => $result['temp_password'],
                'plan'          => $result['plan']?->name,
                'trial_ends_at' => $result['subscription']->trial_ends_at?->toDateString(),
                'merchant_type' => 'kirim',
            ],
        ]);
    }

    public function update(Request $request, MerchantApplication $application)
    {
        if (in_array($application->status, ['approved', 'converted', 'rejected'])) {
            return response()->json(['message' => 'Cannot edit a finalized application.'], 422);
        }

        $data = $request->validate([
            'company_name'                  => 'sometimes|string|max:255',
            'owner_name'                    => 'sometimes|string|max:255',
            'email'                         => 'sometimes|email|max:255',
            'phone'                         => 'sometimes|nullable|string|max:30',
            'city'                          => 'sometimes|nullable|string|max:100',
            'business_type'                 => 'sometimes|nullable|string|max:100',
            'branch_count'                  => 'sometimes|nullable|integer|min:0',
            'estimated_monthly_deliveries'  => 'sometimes|nullable|integer|min:0',
            'selected_plan'                 => 'sometimes|nullable|string|max:50',
            'notes'                         => 'sometimes|nullable|string|max:2000',
            'merchant_type_requested'       => 'sometimes|nullable|in:sistem,kirim',
        ]);

        $application->update($data);

        return response()->json(['data' => $application->fresh()->load('approvedBy:id,name')]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'company_name'                  => 'required|string|max:255',
            'owner_name'                    => 'required|string|max:255',
            'email'                         => 'required|email|max:255',
            'phone'                         => 'nullable|string|max:30',
            'city'                          => 'nullable|string|max:100',
            'business_type'                 => 'nullable|string|max:100',
            'branch_count'                  => 'nullable|integer|min:0',
            'estimated_monthly_deliveries'  => 'nullable|integer|min:0',
            'selected_plan'                 => 'nullable|string|max:50',
            'notes'                         => 'nullable|string|max:2000',
            'status'                        => 'nullable|in:pending,approved,converted',
            'merchant_type_requested'       => 'nullable|in:sistem,kirim',
        ]);

        $application = MerchantApplication::create([
            ...$data,
            'status' => $data['status'] ?? 'pending',
        ]);

        return response()->json(['data' => $application], 201);
    }

    public function reject(Request $request, MerchantApplication $application)
    {
        if (!$application->isActionable()) {
            return response()->json(['message' => 'Only pending or review applications can be rejected.'], 422);
        }

        $data = $request->validate([
            'rejection_reason' => 'required|string|max:2000',
        ]);

        $application->update([
            'status'           => 'rejected',
            'rejection_reason' => $data['rejection_reason'],
            'approved_by'      => $request->user()->id,
            'approved_at'      => now(),
        ]);

        return response()->json(['message' => 'Application rejected.', 'data' => $application]);
    }

    public function requestInfo(Request $request, MerchantApplication $application)
    {
        if (!$application->isPending()) {
            return response()->json(['message' => 'Only pending applications can be set to review.'], 422);
        }

        $data = $request->validate(['notes' => 'nullable|string|max:2000']);

        $application->update([
            'status'         => 'review',
            'internal_notes' => $data['notes'] ?? $application->internal_notes,
        ]);

        return response()->json(['message' => 'Application moved to review.', 'data' => $application]);
    }

    public function notes(Request $request, MerchantApplication $application)
    {
        $data = $request->validate(['notes' => 'required|string|max:2000']);
        $application->update(['internal_notes' => $data['notes']]);
        return response()->json(['data' => $application]);
    }

    public function destroy(MerchantApplication $application)
    {
        $application->delete();
        return response()->json(null, 204);
    }
}
