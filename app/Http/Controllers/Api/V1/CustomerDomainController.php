<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ResolvesCurrentMerchant;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\CustomerTag;
use App\Models\CustomerTagAssignment;
use App\Models\CustomerTimeline;
use App\Services\CustomerMetricsService;
use App\Services\CustomerProfileService;
use App\Services\FeatureManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerDomainController extends Controller
{
    use ResolvesCurrentMerchant;

    public function __construct(
        private readonly CustomerProfileService $profileService,
        private readonly CustomerMetricsService $metricsService,
        private readonly FeatureManager         $featureManager,
    ) {}

    // ─── Authorization helpers ───────────────────────────────────────

    private function checkFeature(Request $request): void
    {
        $user = $request->user();
        if ($user->isSuperAdmin()) return;

        if (!$this->featureManager->isEnabled($user->merchant_id, 'customer_domain')) {
            abort(403, 'Customer domain feature is not enabled for your merchant.');
        }
    }

    private function resolveCustomer(Request $request, int $customerId): Customer
    {
        $customer = Customer::findOrFail($customerId);
        $this->authorizeMerchant($request, $customer->merchant_id);
        return $customer;
    }

    private function resolveTag(Request $request, int $tagId): CustomerTag
    {
        $tag = CustomerTag::findOrFail($tagId);
        $this->authorizeMerchant($request, $tag->merchant_id);
        return $tag;
    }

    private function requireOwner(Request $request): void
    {
        if (!in_array($request->user()->role, ['merchant_owner', 'super_admin', 'developer'])) {
            abort(403, 'Only merchant owners can perform this action.');
        }
    }

    // ─── Profile ────────────────────────────────────────────────────

    public function profile(Request $request, int $customerId): JsonResponse
    {
        $this->checkFeature($request);
        $customer = $this->resolveCustomer($request, $customerId);

        $profile = CustomerProfile::where('customer_id', $customer->id)->first()
            ?? $this->profileService->initializeProfile($customer);

        $tagIds = CustomerTagAssignment::where('customer_id', $customer->id)->pluck('tag_id');
        $tags   = CustomerTag::whereIn('id', $tagIds)->get(['id', 'name', 'color']);

        return response()->json([
            'data' => [
                'customer' => $customer->only([
                    'id', 'ulid', 'customer_name', 'phone', 'vip_level',
                    'cluster', 'is_active', 'notes', 'created_at',
                ]),
                'profile' => $profile,
                'tags'    => $tags,
            ],
        ]);
    }

    // ─── Timeline ───────────────────────────────────────────────────

    public function timeline(Request $request, int $customerId): JsonResponse
    {
        $this->checkFeature($request);
        $customer = $this->resolveCustomer($request, $customerId);

        $perPage  = $request->integer('per_page', 20);
        $timeline = CustomerTimeline::where('customer_id', $customer->id)
            ->orderByDesc('occurred_at')
            ->paginate($perPage);

        return response()->json(['data' => $timeline]);
    }

    // ─── Metrics ────────────────────────────────────────────────────

    public function metrics(Request $request, int $customerId): JsonResponse
    {
        $this->checkFeature($request);
        $customer = $this->resolveCustomer($request, $customerId);

        return response()->json(['data' => $this->metricsService->getMetrics($customer)]);
    }

    // ─── Tags on a customer ─────────────────────────────────────────

    public function customerTags(Request $request, int $customerId): JsonResponse
    {
        $this->checkFeature($request);
        $customer = $this->resolveCustomer($request, $customerId);

        $tagIds = CustomerTagAssignment::where('customer_id', $customer->id)->pluck('tag_id');
        $tags   = CustomerTag::whereIn('id', $tagIds)->get();

        return response()->json(['data' => $tags]);
    }

    public function assignTag(Request $request, int $customerId, int $tagId): JsonResponse
    {
        $this->checkFeature($request);
        $customer = $this->resolveCustomer($request, $customerId);
        $tag      = $this->resolveTag($request, $tagId);

        CustomerTagAssignment::firstOrCreate(
            ['customer_id' => $customer->id, 'tag_id' => $tag->id],
            ['assigned_by' => $request->user()->id],
        );

        return response()->json(['message' => 'Tag assigned.']);
    }

    public function removeTag(Request $request, int $customerId, int $tagId): JsonResponse
    {
        $this->checkFeature($request);
        $customer = $this->resolveCustomer($request, $customerId);
        $this->resolveTag($request, $tagId); // authorize tag belongs to same merchant

        CustomerTagAssignment::where('customer_id', $customer->id)
            ->where('tag_id', $tagId)
            ->delete();

        return response()->json(['message' => 'Tag removed.']);
    }

    // ─── Tag management ─────────────────────────────────────────────

    public function indexTags(Request $request): JsonResponse
    {
        $this->checkFeature($request);

        $tags = CustomerTag::where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $tags]);
    }

    public function storeTag(Request $request): JsonResponse
    {
        $this->checkFeature($request);
        $this->requireOwner($request);

        $data = $request->validate([
            'name'  => 'required|string|max:100',
            'color' => 'nullable|string|max:20',
        ]);

        $tag = CustomerTag::create([
            'merchant_id' => $request->user()->merchant_id,
            'name'        => $data['name'],
            'color'       => $data['color'] ?? null,
        ]);

        return response()->json(['data' => $tag], 201);
    }

    public function updateTag(Request $request, int $tagId): JsonResponse
    {
        $this->checkFeature($request);
        $this->requireOwner($request);
        $tag = $this->resolveTag($request, $tagId);

        $data = $request->validate([
            'name'      => 'sometimes|string|max:100',
            'color'     => 'nullable|string|max:20',
            'is_active' => 'sometimes|boolean',
        ]);

        $tag->update($data);

        return response()->json(['data' => $tag]);
    }

    public function destroyTag(Request $request, int $tagId): JsonResponse
    {
        $this->checkFeature($request);
        $this->requireOwner($request);
        $tag = $this->resolveTag($request, $tagId);

        CustomerTagAssignment::where('tag_id', $tag->id)->delete();
        $tag->delete();

        return response()->json(['message' => 'Tag deleted.']);
    }
}
