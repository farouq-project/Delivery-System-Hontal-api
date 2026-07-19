<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\FeatureManager;
use App\Services\MerchantPlatformService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantPlatformController extends Controller
{
    private const OWNER_ROLES = ['merchant_owner', 'developer', 'super_admin'];

    public function __construct(
        private readonly MerchantPlatformService $service,
        private readonly FeatureManager $features,
    ) {}

    // Returns the authenticated merchant_id after role + feature-flag checks.
    // Aborts with 403 on any failure.
    private function gate(Request $request): int
    {
        $user = $request->user();

        if (!in_array($user->role, self::OWNER_ROLES)) {
            abort(403, 'Insufficient role.');
        }

        $merchantId = $user->merchant_id;
        if (!$merchantId) {
            abort(403, 'No merchant context.');
        }

        if (!in_array($user->role, ['super_admin', 'developer'])) {
            if (!$this->features->isEnabled($merchantId, 'merchant_platform')) {
                abort(403, 'Merchant Platform is not enabled for this merchant.');
            }
        }

        return $merchantId;
    }

    // ─── Business Profile ─────────────────────────────────────────────

    public function getProfile(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->getProfile($this->gate($request))]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $merchantId = $this->gate($request);
        $data = $request->validate([
            'company_name'   => 'sometimes|string|max:200',
            'phone'          => 'sometimes|nullable|string|max:30',
            'email'          => 'sometimes|nullable|email|max:200',
            'address'        => 'sometimes|nullable|string|max:500',
            'logo_path'      => 'sometimes|nullable|string|max:500',
            'tax_number'     => 'sometimes|nullable|string|max:50',
            'invoice_footer' => 'sometimes|nullable|string|max:1000',
            'brand_color'    => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        return response()->json(['data' => $this->service->updateProfile($merchantId, $data)]);
    }

    // ─── Operational Settings ──────────────────────────────────────────

    public function getOperational(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->getOperational($this->gate($request))]);
    }

    public function updateOperational(Request $request): JsonResponse
    {
        $merchantId = $this->gate($request);
        $data = $request->validate([
            'depot_address'          => 'sometimes|nullable|string|max:500',
            'depot_latitude'         => 'sometimes|nullable|numeric|between:-90,90',
            'depot_longitude'        => 'sometimes|nullable|numeric|between:-180,180',
            'routing_algorithm'      => 'sometimes|string|in:balanced,distance,vip',
            'max_stops_per_driver'   => 'sometimes|integer|min:1|max:200',
            'klotter_size'           => 'sometimes|integer|min:1|max:200',
            'max_delivery_radius_km' => 'sometimes|nullable|integer|min:1|max:500',
            'auto_dispatch'          => 'sometimes|boolean',
            'auto_geocode_enabled'   => 'sometimes|boolean',
            'hide_driver_logout'     => 'sometimes|boolean',
            'order_edit_pin'              => 'sometimes|nullable|string|regex:/^\d{3,6}$/',
            'location_validation_radius'  => 'sometimes|nullable|integer|min:1|max:500',
        ]);

        return response()->json(['data' => $this->service->updateOperational($merchantId, $data)]);
    }

    // ─── Business Hours ────────────────────────────────────────────────

    public function getHours(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->getHours($this->gate($request))]);
    }

    public function updateHours(Request $request): JsonResponse
    {
        $merchantId = $this->gate($request);
        $data = $request->validate([
            'working_hours_start'  => 'sometimes|string|date_format:H:i',
            'working_hours_end'    => 'sometimes|string|date_format:H:i',
            'working_days'         => 'sometimes|array',
            'working_days.*'       => 'string|in:mon,tue,wed,thu,fri,sat,sun',
            'holiday_mode_enabled' => 'sometimes|boolean',
        ]);

        return response()->json(['data' => $this->service->updateHours($merchantId, $data)]);
    }

    // ─── Invoice Settings ──────────────────────────────────────────────

    public function getInvoice(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->getInvoice($this->gate($request))]);
    }

    public function updateInvoice(Request $request): JsonResponse
    {
        $merchantId = $this->gate($request);
        $data = $request->validate([
            'invoice_prefix'      => 'sometimes|nullable|string|max:20',
            'invoice_date_format' => 'sometimes|nullable|string|max:20',
            'invoice_footer'      => 'sometimes|nullable|string|max:1000',
        ]);

        return response()->json(['data' => $this->service->updateInvoice($merchantId, $data)]);
    }

    // ─── Tracking Settings ─────────────────────────────────────────────

    public function getTracking(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->getTracking($this->gate($request))]);
    }

    public function updateTracking(Request $request): JsonResponse
    {
        $merchantId = $this->gate($request);
        $data = $request->validate([
            'tracking_expiry_hours'   => 'sometimes|integer|min:1|max:168',
            'public_tracking_enabled' => 'sometimes|boolean',
            'show_estimated_arrival'  => 'sometimes|boolean',
            'driver_location_visible' => 'sometimes|boolean',
        ]);

        return response()->json(['data' => $this->service->updateTracking($merchantId, $data)]);
    }

    // ─── Notification Settings ─────────────────────────────────────────

    public function getNotifications(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->getNotifications($this->gate($request))]);
    }

    public function updateNotifications(Request $request): JsonResponse
    {
        $merchantId = $this->gate($request);
        $data = $request->validate([
            'whatsapp_notifications_enabled' => 'sometimes|boolean',
            'email_notifications_enabled'    => 'sometimes|boolean',
            'push_notifications_enabled'     => 'sometimes|boolean',
        ]);

        return response()->json(['data' => $this->service->updateNotifications($merchantId, $data)]);
    }

    // ─── Payment Methods ───────────────────────────────────────────────

    public function indexPaymentMethods(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->getPaymentMethods($this->gate($request))]);
    }

    public function storePaymentMethod(Request $request): JsonResponse
    {
        $merchantId = $this->gate($request);
        $data = $request->validate([
            'method_key' => 'required|string|max:30',
            'label'      => 'required|string|max:100',
            'is_enabled' => 'sometimes|boolean',
        ]);

        return response()->json(['data' => $this->service->storePaymentMethod($merchantId, $data)], 201);
    }

    public function updatePaymentMethod(Request $request, int $id): JsonResponse
    {
        $merchantId = $this->gate($request);
        $data = $request->validate([
            'label'      => 'sometimes|string|max:100',
            'is_enabled' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
        ]);

        return response()->json(['data' => $this->service->updatePaymentMethod($merchantId, $id, $data)]);
    }

    public function reorderPaymentMethods(Request $request): JsonResponse
    {
        $merchantId = $this->gate($request);
        $data = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $this->service->reorderPaymentMethods($merchantId, $data['ids']);

        return response()->json(['data' => $this->service->getPaymentMethods($merchantId)]);
    }

    // ─── Branches ──────────────────────────────────────────────────────

    public function indexBranches(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->getBranches($this->gate($request))]);
    }

    public function storeBranch(Request $request): JsonResponse
    {
        $merchantId = $this->gate($request);
        $data = $request->validate([
            'name'                 => 'required|string|max:100',
            'address'              => 'sometimes|nullable|string|max:500',
            'depot_latitude'       => 'sometimes|nullable|numeric|between:-90,90',
            'depot_longitude'      => 'sometimes|nullable|numeric|between:-180,180',
            'working_hours_start'  => 'sometimes|nullable|string|date_format:H:i',
            'working_hours_end'    => 'sometimes|nullable|string|date_format:H:i',
            'working_days'         => 'sometimes|nullable|array',
            'working_days.*'       => 'string|in:mon,tue,wed,thu,fri,sat,sun',
            'max_stops_per_driver' => 'sometimes|nullable|integer|min:1|max:200',
            'is_active'            => 'sometimes|boolean',
        ]);

        return response()->json(['data' => $this->service->storeBranch($merchantId, $data)], 201);
    }

    public function updateBranch(Request $request, int $id): JsonResponse
    {
        $merchantId = $this->gate($request);
        $data = $request->validate([
            'name'                 => 'sometimes|string|max:100',
            'address'              => 'sometimes|nullable|string|max:500',
            'depot_latitude'       => 'sometimes|nullable|numeric|between:-90,90',
            'depot_longitude'      => 'sometimes|nullable|numeric|between:-180,180',
            'working_hours_start'  => 'sometimes|nullable|string|date_format:H:i',
            'working_hours_end'    => 'sometimes|nullable|string|date_format:H:i',
            'working_days'         => 'sometimes|nullable|array',
            'working_days.*'       => 'string|in:mon,tue,wed,thu,fri,sat,sun',
            'max_stops_per_driver' => 'sometimes|nullable|integer|min:1|max:200',
            'is_active'            => 'sometimes|boolean',
        ]);

        return response()->json(['data' => $this->service->updateBranch($merchantId, $id, $data)]);
    }

    public function destroyBranch(Request $request, int $id): JsonResponse
    {
        $this->service->destroyBranch($this->gate($request), $id);

        return response()->json(null, 204);
    }
}
