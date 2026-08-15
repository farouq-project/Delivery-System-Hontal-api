<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\HontalKirimCredit;
use App\Models\HontalKirimCreditTransaction;
use App\Models\Merchant;
use App\Models\MerchantFeature;
use App\Models\MerchantSetting;
use App\Models\PlatformSetting;
use App\Models\Scopes\MerchantScope;
use App\Models\User;
use App\Models\VipConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class KirimAdminController extends Controller
{
    private function internalMerchantId(): int
    {
        return (int) (PlatformSetting::where('key', 'hontal_internal_merchant_id')->value('value') ?: 0);
    }

    // ── Kirim merchants ───────────────────────────────────────────────────────

    public function merchants(Request $request)
    {
        $merchants = Merchant::where('merchant_type', 'kirim')
            ->with([
                'users' => fn($q) => $q->where('role', 'merchant_owner')
                    ->select('id', 'merchant_id', 'name', 'email'),
            ])
            ->withCount([
                'orders as total_kirim_orders' => fn($q) => $q->where('delivery_type', 'hontal_kirim'),
                'orders as pending_kirim_orders' => fn($q) => $q
                    ->where('delivery_type', 'hontal_kirim')
                    ->whereIn('status', ['pending', 'batched']),
            ])
            ->orderBy('company_name')
            ->get();

        $merchantIds = $merchants->pluck('id');

        $credits = HontalKirimCredit::whereIn('merchant_id', $merchantIds)
            ->get()
            ->keyBy('merchant_id');

        $features = MerchantFeature::whereIn('merchant_id', $merchantIds)
            ->where('feature', 'hontal_kirim')
            ->get()
            ->keyBy('merchant_id');

        $data = $merchants->map(function (Merchant $m) use ($credits, $features) {
            $credit  = $credits->get($m->id);
            $feature = $features->get($m->id);

            return [
                'id'                  => $m->id,
                'company_name'        => $m->company_name,
                'email'               => $m->email,
                'slug'                => $m->slug,
                'merchant_type'       => $m->merchant_type,
                'owner'               => $m->users->first() ? ['name' => $m->users->first()->name, 'email' => $m->users->first()->email] : null,
                'kirim_enabled'       => (bool) $feature?->is_enabled,
                'credit_balance_idr'  => $credit?->balance_idr ?? 0,
                'deliveries_remaining'=> $credit ? (int) floor($credit->balance_idr / 10000) : 0,
                'is_blocked'          => $credit ? ($credit->balance_idr <= 0) : true,
                'total_kirim_orders'  => $m->total_kirim_orders ?? 0,
                'pending_kirim_orders'=> $m->pending_kirim_orders ?? 0,
                'created_at'          => $m->created_at,
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function createMerchant(Request $request)
    {
        $data = $request->validate([
            'company_name'      => 'required|string|max:255',
            'email'             => 'required|email|unique:merchants,email',
            'address'           => 'nullable|string|max:500',
            'owner_name'        => 'required|string|max:255',
            'owner_email'       => 'required|email|unique:users,email',
            'initial_topup_idr' => 'nullable|integer|min:0',
        ]);

        DB::transaction(function () use ($data) {
            $slug = Str::slug($data['company_name']) . '-' . Str::lower(Str::random(4));

            $merchant = Merchant::create([
                'ulid'          => Str::ulid(),
                'company_name'  => $data['company_name'],
                'slug'          => $slug,
                'email'         => $data['email'],
                'address'       => $data['address'] ?? null,
                'timezone'      => 'Asia/Jakarta',
                'merchant_type' => 'kirim',
            ]);

            MerchantSetting::create([
                'merchant_id'           => $merchant->id,
                'routing_algorithm'     => 'balanced',
                'max_stops_per_driver'  => 30,
                'working_hours_start'   => '07:00:00',
                'working_hours_end'     => '17:00:00',
                'gps_ping_interval_sec' => 30,
                'gps_history_days'      => 30,
            ]);

            foreach (['standard' => 0, 'silver' => 50, 'gold' => 100, 'platinum' => 200] as $level => $score) {
                VipConfig::create(['merchant_id' => $merchant->id, 'vip_level' => $level, 'score_value' => $score]);
            }

            User::create([
                'ulid'        => Str::ulid(),
                'merchant_id' => $merchant->id,
                'name'        => $data['owner_name'],
                'email'       => $data['owner_email'],
                'password'    => Hash::make(Str::random(16)), // owner sets their own password via invite
                'role'        => 'merchant_owner',
                'is_active'   => true,
            ]);

            MerchantFeature::create([
                'merchant_id' => $merchant->id,
                'feature'     => 'hontal_kirim',
                'is_enabled'  => true,
            ]);

            $topup = (int) ($data['initial_topup_idr'] ?? 0);
            if ($topup > 0) {
                HontalKirimCredit::create([
                    'merchant_id'               => $merchant->id,
                    'balance_idr'               => $topup,
                    'low_balance_threshold_idr' => 100000,
                ]);
                HontalKirimCreditTransaction::create([
                    'merchant_id' => $merchant->id,
                    'type'        => 'topup',
                    'amount_idr'  => $topup,
                    'note'        => 'Initial onboarding top-up',
                    'created_by'  => request()->user()->id,
                ]);
            }

            return $merchant;
        });

        return response()->json(['message' => 'Kirim merchant created.'], 201);
    }

    public function topup(Request $request)
    {
        $data = $request->validate([
            'merchant_id' => 'required|integer|exists:merchants,id',
            'amount_idr'  => 'required|integer|min:10000',
            'note'        => 'nullable|string|max:500',
        ]);

        $credit = HontalKirimCredit::firstOrCreate(
            ['merchant_id' => $data['merchant_id']],
            ['balance_idr' => 0, 'low_balance_threshold_idr' => 100000]
        );
        $credit->increment('balance_idr', $data['amount_idr']);

        HontalKirimCreditTransaction::create([
            'merchant_id' => $data['merchant_id'],
            'type'        => 'topup',
            'amount_idr'  => $data['amount_idr'],
            'note'        => $data['note'] ?? 'Manual top-up by admin',
            'created_by'  => $request->user()->id,
        ]);

        return response()->json(['data' => $credit->fresh()]);
    }

    // ── Hontal Internal team ──────────────────────────────────────────────────

    public function dispatchers(Request $request)
    {
        $internalId = $this->internalMerchantId();

        $dispatchers = User::where('merchant_id', $internalId)
            ->where('role', 'hontal_dispatcher')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_active', 'last_login_at', 'created_at']);

        return response()->json(['data' => $dispatchers]);
    }

    public function mitraDrivers(Request $request)
    {
        $internalId = $this->internalMerchantId();

        $drivers = Driver::withoutGlobalScope(MerchantScope::class)
            ->where('merchant_id', $internalId)
            ->with('user:id,name,email,is_active,last_login_at')
            ->orderBy('driver_name')
            ->get()
            ->map(fn($d) => [
                'id'            => $d->id,
                'driver_name'   => $d->driver_name,
                'phone'         => $d->phone,
                'vehicle_type'  => $d->vehicle_type,
                'vehicle_plate' => $d->vehicle_plate,
                'status'        => $d->status,
                'is_active'     => $d->is_active,
                'user'          => $d->user ? ['id' => $d->user->id, 'name' => $d->user->name, 'email' => $d->user->email, 'last_login_at' => $d->user->last_login_at] : null,
            ]);

        return response()->json(['data' => $drivers]);
    }

    public function createDispatcher(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $internalId = $this->internalMerchantId();
        if (! $internalId) {
            return response()->json(['message' => 'Hontal Internal merchant not configured.'], 500);
        }

        $user = User::create([
            'ulid'        => Str::ulid(),
            'merchant_id' => $internalId,
            'name'        => $data['name'],
            'email'       => $data['email'],
            'password'    => Hash::make($data['password']),
            'role'        => 'hontal_dispatcher',
            'is_active'   => true,
        ]);

        return response()->json(['data' => $user->only('id', 'name', 'email', 'role')], 201);
    }

    public function createMitraDriver(Request $request)
    {
        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'email'         => 'required|email|unique:users,email',
            'password'      => 'required|string|min:8',
            'phone'         => 'required|string|max:20',
            'vehicle_type'  => 'required|in:motorcycle,car,pickup_truck,van,truck',
            'vehicle_plate' => 'required|string|max:20',
        ]);

        $internalId = $this->internalMerchantId();
        if (! $internalId) {
            return response()->json(['message' => 'Hontal Internal merchant not configured.'], 500);
        }

        DB::transaction(function () use ($data, $internalId) {
            $user = User::create([
                'ulid'        => Str::ulid(),
                'merchant_id' => $internalId,
                'name'        => $data['name'],
                'email'       => $data['email'],
                'password'    => Hash::make($data['password']),
                'role'        => 'driver',
                'is_active'   => true,
            ]);

            Driver::create([
                'ulid'          => Str::ulid(),
                'merchant_id'   => $internalId,
                'user_id'       => $user->id,
                'driver_name'   => $data['name'],
                'phone'         => $data['phone'],
                'vehicle_type'  => $data['vehicle_type'],
                'vehicle_plate' => $data['vehicle_plate'],
                'status'        => 'offline',
                'is_active'     => true,
            ]);
        });

        return response()->json(['message' => 'Mitra driver created.'], 201);
    }
}
