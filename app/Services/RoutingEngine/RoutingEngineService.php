<?php

namespace App\Services\RoutingEngine;

use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Route;
use App\Models\RouteAssignment;
use App\Models\RouteStop;
use App\Services\DistanceMatrix\GoogleDistanceMatrixService;
use App\Services\RoutingEngine\Optimization\NearestNeighborSolver;
use App\Services\RoutingEngine\Optimization\TwoOptImprover;
use App\Services\RoutingEngine\Scoring\DistanceScorer;
use App\Services\RoutingEngine\Scoring\VipScorer;
use App\Services\RoutingEngine\Scoring\WaitingScorer;
use App\Services\RoutingEngine\Scoring\WindowScorer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RoutingEngineService
{
    public function __construct(
        private GoogleDistanceMatrixService $distanceMatrix,
        private NearestNeighborSolver       $nnSolver,
        private TwoOptImprover              $twoOpt,
        private DistanceScorer              $distanceScorer,
        private WaitingScorer               $waitingScorer,
        private WindowScorer                $windowScorer,
        private VipScorer                   $vipScorer,
    ) {}

    /**
     * Build/refresh today's route: sequence unassigned pending orders into an
     * "unassigned" group (no driver yet) and re-sequence each driver's
     * already-assigned stops. Driver allocation itself stays manual
     * (see RouteController::assignOrder / assignOrders).
     */
    public function generate(Merchant $merchant, string $routeDate): Route
    {
        $settings = $merchant->settings ?? new \App\Models\MerchantSetting(['depot_latitude' => -6.9175, 'depot_longitude' => 107.6191]);
        $depot    = ['lat' => $settings->depot_latitude ?? -6.9175, 'lng' => $settings->depot_longitude ?? 107.6191];

        $dateFilter = function ($q) use ($routeDate) {
            $q->where('requested_delivery_date', $routeDate)
              ->orWhereNull('requested_delivery_date');
        };

        $pendingOrders = DeliveryOrder::with('customer')
            ->where('merchant_id', $merchant->id)
            ->where('status', 'pending')
            ->whereNull('driver_id')
            ->where($dateFilter)
            ->get();

        if ($pendingOrders->isEmpty()) {
            throw new \RuntimeException('No unassigned orders to route.');
        }

        $route = Route::firstOrCreate(
            ['merchant_id' => $merchant->id, 'route_date' => $routeDate],
            ['ulid' => Str::ulid(), 'status' => 'active', 'generation_method' => 'auto', 'generated_at' => now()]
        );
        $route->update(['status' => 'active', 'generation_method' => 'auto', 'generated_at' => now()]);

        // Sequence unassigned pending orders from the depot. Already-assigned
        // orders keep whatever sequence/driver they were given manually.
        $algorithm    = $settings->routing_algorithm ?? 'balanced';
        $scoredOrders = $this->scoreOrders($pendingOrders, $merchant, $depot, $algorithm);

        Log::info('[ROUTE] Scoring results', [
            'route_date'   => $routeDate,
            'depot'        => $depot,
            'order_count'  => $pendingOrders->count(),
            'orders'       => $pendingOrders->map(fn($o) => [
                'id'            => $o->id,
                'customer'      => $o->customer_name,
                'has_coords'    => (bool) ($o->delivery_latitude && $o->delivery_longitude),
                'lat'           => $o->delivery_latitude,
                'lng'           => $o->delivery_longitude,
                'distance_score'=> $scoredOrders[$o->id]['distance_score'] ?? 'N/A',
                'waiting_score' => $scoredOrders[$o->id]['waiting_score']  ?? 'N/A',
                'window_score'  => $scoredOrders[$o->id]['window_score']   ?? 'N/A',
                'vip_score'     => $scoredOrders[$o->id]['vip_score']      ?? 'N/A',
                'total_score'   => $scoredOrders[$o->id]['total_score']    ?? 'N/A',
                'batch'         => $scoredOrders[$o->id]['batch_number']   ?? 'N/A',
            ])->values()->toArray(),
        ]);

        $assignment = RouteAssignment::firstOrCreate(
            ['route_id' => $route->id, 'driver_id' => null],
            ['sequence_number' => 0, 'status' => 'pending']
        );

        RouteStop::where('route_assignment_id', $assignment->id)->delete();

        [$orderedIds, $distSum, $etaData] = $this->optimizeCluster(
            null, $depot, $pendingOrders, $scoredOrders, $settings
        );

        Log::info('[ROUTE] Generated sequence', [
            'total_distance_m' => $distSum,
            'sequence'         => collect($orderedIds)->map(fn($id, $seq) => [
                'seq'        => $seq + 1,
                'order_id'   => $id,
                'customer'   => $pendingOrders->firstWhere('id', $id)?->customer_name,
                'total_score'=> $scoredOrders[$id]['total_score'] ?? 0,
            ])->values()->toArray(),
        ]);

        $this->createStops($route, $assignment, $orderedIds, $scoredOrders, $etaData);

        $assignment->update(['total_stops' => count($orderedIds), 'total_distance_m' => $distSum]);

        $route->update([
            'total_stops'      => RouteStop::where('route_id', $route->id)->count(),
            'total_distance_m' => $route->assignments()->sum('total_distance_m'),
            'total_drivers'    => $route->assignments()->whereNotNull('driver_id')->count(),
        ]);

        return $route->fresh()->load(['assignments.driver', 'assignments.stops.order']);
    }

    private function createStops(Route $route, RouteAssignment $assignment, array $orderedIds, array $scoredOrders, array $etaData): void
    {
        foreach ($orderedIds as $seq => $orderId) {
            $scored   = $scoredOrders[$orderId] ?? [];
            $etaEntry = $etaData[$seq] ?? [];

            RouteStop::create([
                'route_id'            => $route->id,
                'route_assignment_id' => $assignment->id,
                'order_id'            => $orderId,
                'stop_sequence'       => $seq + 1,
                'distance_score'      => $scored['distance_score'] ?? 0,
                'waiting_score'       => $scored['waiting_score'] ?? 0,
                'window_score'        => $scored['window_score'] ?? 0,
                'vip_score'           => $scored['vip_score'] ?? 0,
                'total_score'         => $scored['total_score'] ?? 0,
                'estimated_arrival'   => $etaEntry['eta'] ?? null,
                'distance_from_prev_m'   => $etaEntry['distance_m'] ?? null,
                'duration_from_prev_min' => $etaEntry['duration_min'] ?? null,
            ]);

            DeliveryOrder::where('id', $orderId)->update(['route_sequence' => $seq + 1]);
        }
    }

    /**
     * Reoptimize an existing route by inserting new orders.
     */
    public function reoptimize(Route $route, array $newOrderIds): Route
    {
        $merchant = $route->merchant;
        $settings = $merchant->settings ?? new \App\Models\MerchantSetting(['depot_latitude' => -6.9175, 'depot_longitude' => 107.6191]);
        $depot    = ['lat' => $settings->depot_latitude ?? -6.9175, 'lng' => $settings->depot_longitude ?? 107.6191];

        $newOrders = DeliveryOrder::with('customer')
            ->whereIn('id', $newOrderIds)
            ->where('merchant_id', $merchant->id)
            ->where('status', 'pending')
            ->get();

        $algorithm = $settings->routing_algorithm ?? 'balanced';
        $scoredNew = $this->scoreOrders($newOrders, $merchant, $depot, $algorithm);

        foreach ($route->assignments as $assignment) {
            $remainingStops = $assignment->stops()
                ->whereHas('order', fn($q) => $q->whereNotIn('status', ['delivered', 'failed']))
                ->where('is_locked', false)
                ->orderBy('stop_sequence')
                ->get();

            if ($remainingStops->isEmpty()) continue;

            foreach ($newOrders as $newOrder) {
                if (!$newOrder->delivery_latitude) continue;

                $bestCost = PHP_FLOAT_MAX;
                $bestPos  = $remainingStops->count(); // default: append at end

                for ($i = 0; $i <= $remainingStops->count(); $i++) {
                    $prev = $i === 0
                        ? $depot
                        : ['lat' => $remainingStops[$i-1]->order->delivery_latitude, 'lng' => $remainingStops[$i-1]->order->delivery_longitude];

                    $next = $i < $remainingStops->count()
                        ? ['lat' => $remainingStops[$i]->order->delivery_latitude, 'lng' => $remainingStops[$i]->order->delivery_longitude]
                        : null;

                    $newPt = ['lat' => $newOrder->delivery_latitude, 'lng' => $newOrder->delivery_longitude];

                    $costInsert = $this->haversineM($prev, $newPt) + ($next ? $this->haversineM($newPt, $next) : 0);
                    $costRemove = $next ? $this->haversineM($prev, $next) : 0;
                    $insertionCost = $costInsert - $costRemove;

                    $scoreBoost = ($scoredNew[$newOrder->id]['total_score'] ?? 0) / 100.0;
                    $adjustedCost = $insertionCost / (1 + $scoreBoost);

                    if ($adjustedCost < $bestCost) {
                        $bestCost = $adjustedCost;
                        $bestPos  = $i;
                    }
                }

                // Insert at bestPos: increment sequences after it
                $assignment->stops()
                    ->where('stop_sequence', '>', $bestPos)
                    ->increment('stop_sequence');

                $scored = $scoredNew[$newOrder->id] ?? [];
                RouteStop::create([
                    'route_id'            => $route->id,
                    'route_assignment_id' => $assignment->id,
                    'order_id'            => $newOrder->id,
                    'stop_sequence'       => $bestPos + 1,
                    'total_score'         => $scored['total_score'] ?? 0,
                    'distance_score'      => $scored['distance_score'] ?? 0,
                    'waiting_score'       => $scored['waiting_score'] ?? 0,
                    'window_score'        => $scored['window_score'] ?? 0,
                    'vip_score'           => $scored['vip_score'] ?? 0,
                    'is_manually_placed'  => false,
                ]);

                $newOrder->update(['status' => 'assigned', 'driver_id' => $assignment->driver_id, 'assigned_at' => now()]);
            }

            $assignment->update(['total_stops' => $assignment->stops()->count()]);
        }

        $route->update([
            'generation_method' => 'reoptimized',
            'total_stops'       => $route->assignments->sum('total_stops'),
        ]);

        return $route->fresh(['assignments.driver', 'assignments.stops.order']);
    }

    private function scoreOrders(Collection $orders, Merchant $merchant, array $depot, string $algorithm = 'balanced'): array
    {
        // Distance scores only for orders that have coordinates
        $destinations = [];
        foreach ($orders as $order) {
            if ($order->delivery_latitude && $order->delivery_longitude) {
                $destinations[$order->id] = ['lat' => $order->delivery_latitude, 'lng' => $order->delivery_longitude];
            }
        }

        $distanceScores = [];
        if (!empty($destinations)) {
            $originPoint = [['lat' => $depot['lat'], 'lng' => $depot['lng']]];
            $destPoints  = array_values($destinations);
            $destIds     = array_keys($destinations);

            $matrix    = $this->distanceMatrix->getMatrix($originPoint, $destPoints);
            $distances = [];
            foreach ($destIds as $idx => $orderId) {
                $distances[$orderId] = $matrix[0][$idx]['distance_m'] ?? 0;
            }
            $distanceScores = $this->distanceScorer->scoreAll($distances);
        }

        // Waiting / window / VIP scores run for ALL orders regardless of coordinates
        $scored = [];
        foreach ($orders as $order) {
            $ds = $distanceScores[$order->id] ?? 0;
            $ws = $this->waitingScorer->score($order);
            $wn = $this->windowScorer->score($order);
            $vs = $this->vipScorer->score($order, $merchant);

            $total = match ($algorithm) {
                'distance' => $ds,
                'vip'      => $vs * 3 + $ds,
                default    => $ds + $ws + $wn + $vs, // balanced
            };

            $scored[$order->id] = [
                'distance_score' => $ds,
                'waiting_score'  => $ws,
                'window_score'   => $wn,
                'vip_score'      => $vs,
                'total_score'    => $total,
                'batch_number'   => 1, // overridden below
            ];
        }

        // Batch grouping — orders that arrived more than 30 minutes after the
        // earliest order in this route are batch 2 and will always be sequenced
        // AFTER batch-1 orders, regardless of VIP/window/distance scores.
        // This prevents a 17:00 platinum order from jumping ahead of a 14:50 batch.
        if ($orders->count() > 1) {
            $earliest = $orders
                ->map(fn($o) => $o->order_created_at ?? $o->created_at)
                ->sortBy(fn($dt) => $dt->timestamp)
                ->first();

            foreach ($orders as $order) {
                $createdAt        = $order->order_created_at ?? $order->created_at;
                $minutesSinceFirst = (int) $earliest->diffInMinutes($createdAt); // always >= 0
                $scored[$order->id]['batch_number'] = $minutesSinceFirst > 60 ? 2 : 1;
            }
        }

        return $scored;
    }

    private function optimizeCluster(?Driver $driver, array $depot, Collection $orders, array $scoredOrders, $settings): array
    {
        // Build coord list: [0 = depot/driver, 1..N = stops]
        $driverPos = ($driver && $driver->current_lat && $driver->current_lng)
            ? ['lat' => $driver->current_lat, 'lng' => $driver->current_lng]
            : $depot;

        $allPoints = [$driverPos];
        $indexMap  = [];

        foreach ($orders as $i => $order) {
            if ($order->delivery_latitude && $order->delivery_longitude) {
                $allPoints[]              = ['lat' => $order->delivery_latitude, 'lng' => $order->delivery_longitude];
                $indexMap[$order->id]     = count($allPoints) - 1;
            }
        }

        $matrix = $this->distanceMatrix->getMatrix($allPoints, $allPoints);

        $stopsData = [];
        foreach ($orders as $order) {
            if (isset($indexMap[$order->id])) {
                $namePrefix = strtolower(trim(substr($order->customer_name ?? '', 0, 4)));

                $stopsData[$order->id] = [
                    'lat'         => $order->delivery_latitude,
                    'lng'         => $order->delivery_longitude,
                    'total_score' => $scoredOrders[$order->id]['total_score'] ?? 0,
                    'group_key'   => $namePrefix ?: null,
                ];
            }
        }

        // Hard-group all stops by the first 6 chars of customer name, then within
        // each group sequence by score-weighted nearest-neighbour. Groups themselves
        // are ordered by their nearest stop's distance from the depot.
        $orderedIds = $this->nnSolver->solveGrouped($stopsData, $matrix, $indexMap);

        // Calculate ETAs
        $etaData      = [];
        $totalDistM   = 0;
        $workStart    = Carbon::parse($settings->working_hours_start ?? '07:00:00');
        $currentTime  = now()->setTimeFromTimeString($settings->working_hours_start ?? '07:00:00');
        $prevIdx      = 0;

        foreach ($orderedIds as $seq => $orderId) {
            $curIdx = $indexMap[$orderId] ?? null;
            if ($curIdx === null) continue;

            $leg = $matrix[$prevIdx][$curIdx] ?? ['distance_m' => 0, 'duration_min' => 5];
            $currentTime->addMinutes($leg['duration_min'] + 5); // +5 min service time
            $totalDistM += $leg['distance_m'];

            $etaData[$seq] = [
                'eta'          => $currentTime->copy(),
                'distance_m'   => $leg['distance_m'],
                'duration_min' => $leg['duration_min'],
            ];

            $prevIdx = $curIdx;
        }

        // Orders without a delivery location can't be geo-sequenced, but should
        // still appear in the route, ordered by their waiting/window/VIP score.
        $unlocated = $orders->reject(fn($order) => isset($indexMap[$order->id]))
            ->sortByDesc(fn($order) => $scoredOrders[$order->id]['total_score'] ?? 0)
            ->pluck('id')
            ->all();

        $orderedIds = [...$orderedIds, ...$unlocated];

        return [$orderedIds, $totalDistM, $etaData];
    }

    private function haversineM(array $from, array $to): float
    {
        $R    = 6371000;
        $dLat = deg2rad($to['lat'] - $from['lat']);
        $dLng = deg2rad($to['lng'] - $from['lng']);
        $a    = sin($dLat/2)**2 + cos(deg2rad($from['lat'])) * cos(deg2rad($to['lat'])) * sin($dLng/2)**2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
