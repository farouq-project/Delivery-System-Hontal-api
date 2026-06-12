<?php

namespace App\Services\RoutingEngine;

use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Route;
use App\Models\RouteAssignment;
use App\Models\RouteStop;
use App\Services\DistanceMatrix\GoogleDistanceMatrixService;
use App\Services\RoutingEngine\Clustering\GeographicClusterer;
use App\Services\RoutingEngine\Optimization\NearestNeighborSolver;
use App\Services\RoutingEngine\Optimization\TwoOptImprover;
use App\Services\RoutingEngine\Scoring\DistanceScorer;
use App\Services\RoutingEngine\Scoring\VipScorer;
use App\Services\RoutingEngine\Scoring\WaitingScorer;
use App\Services\RoutingEngine\Scoring\WindowScorer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RoutingEngineService
{
    public function __construct(
        private GoogleDistanceMatrixService $distanceMatrix,
        private GeographicClusterer         $clusterer,
        private NearestNeighborSolver       $nnSolver,
        private TwoOptImprover              $twoOpt,
        private DistanceScorer              $distanceScorer,
        private WaitingScorer               $waitingScorer,
        private WindowScorer                $windowScorer,
        private VipScorer                   $vipScorer,
    ) {}

    /**
     * Generate a full route for the given orders and drivers.
     */
    public function generate(
        Merchant $merchant,
        array    $driverIds,
        array    $orderIds,
        string   $routeDate
    ): Route {
        $orders  = DeliveryOrder::with('customer')
            ->whereIn('id', $orderIds)
            ->whereIn('status', ['pending'])
            ->where('merchant_id', $merchant->id)
            ->get();

        $drivers = Driver::whereIn('id', $driverIds)
            ->where('merchant_id', $merchant->id)
            ->where('is_active', true)
            ->get();

        if ($orders->isEmpty() || $drivers->isEmpty()) {
            throw new \RuntimeException('No valid orders or drivers for routing.');
        }

        $settings = $merchant->settings ?? new \App\Models\MerchantSetting(['depot_latitude' => -6.9175, 'depot_longitude' => 107.6191]);
        $depot    = ['lat' => $settings->depot_latitude ?? -6.9175, 'lng' => $settings->depot_longitude ?? 107.6191];

        // STEP 1: Score all orders
        $scoredOrders = $this->scoreOrders($orders, $merchant, $depot);

        // STEP 2: Cluster orders
        $orderPoints = [];
        foreach ($orders as $order) {
            if ($order->delivery_latitude && $order->delivery_longitude) {
                $orderPoints[$order->id] = ['lat' => $order->delivery_latitude, 'lng' => $order->delivery_longitude];
            }
        }

        $clusters = $this->clusterer->cluster($orderPoints, count($drivers));

        // STEP 3: Assign clusters to drivers
        $clusterAssignments = $this->assignClustersToDrivers($clusters, $drivers, $depot);

        // STEP 4–5: Build route
        $route = Route::create([
            'ulid'              => Str::ulid(),
            'merchant_id'       => $merchant->id,
            'route_date'        => $routeDate,
            'status'            => 'active',
            'generation_method' => 'auto',
            'generated_at'      => now(),
            'total_drivers'     => count($clusterAssignments),
        ]);

        $totalStops    = 0;
        $totalDistance = 0;
        $assignmentSeq = 1;

        foreach ($clusterAssignments as $driverIdx => $assignedCluster) {
            $driver    = $drivers->firstWhere('id', $assignedCluster['driver_id']);
            $clusterOrderIds = $assignedCluster['order_ids'];

            if (empty($clusterOrderIds)) continue;

            // Build matrix for this cluster
            $clusterOrders = $orders->whereIn('id', $clusterOrderIds)->values();
            [$orderedIds, $distSums, $etaData] = $this->optimizeCluster(
                $driver, $depot, $clusterOrders, $scoredOrders, $settings
            );

            $assignment = RouteAssignment::create([
                'route_id'          => $route->id,
                'driver_id'         => $driver->id,
                'sequence_number'   => $assignmentSeq++,
                'total_stops'       => count($orderedIds),
                'total_distance_m'  => $distSums,
                'estimated_start_at' => Carbon::parse($routeDate . ' ' . ($settings->working_hours_start ?? '07:00:00')),
                'status'            => 'pending',
            ]);

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

                // Update order status
                DeliveryOrder::where('id', $orderId)->update([
                    'status'           => 'assigned',
                    'driver_id'        => $driver->id,
                    'route_sequence'   => $seq + 1,
                    'assigned_at'      => now(),
                ]);
            }

            $totalStops    += count($orderedIds);
            $totalDistance += $distSums;
        }

        $route->update(['total_stops' => $totalStops, 'total_distance_m' => $totalDistance]);

        return $route->load(['assignments.driver', 'assignments.stops.order']);
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

        $scoredNew = $this->scoreOrders($newOrders, $merchant, $depot);

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

    private function scoreOrders(Collection $orders, Merchant $merchant, array $depot): array
    {
        $destinations = [];
        foreach ($orders as $order) {
            if ($order->delivery_latitude && $order->delivery_longitude) {
                $destinations[$order->id] = ['lat' => $order->delivery_latitude, 'lng' => $order->delivery_longitude];
            }
        }

        if (empty($destinations)) return [];

        $originPoint = [['lat' => $depot['lat'], 'lng' => $depot['lng']]];
        $destPoints  = array_values($destinations);
        $destIds     = array_keys($destinations);

        $matrix     = $this->distanceMatrix->getMatrix($originPoint, $destPoints);
        $distances  = [];
        foreach ($destIds as $idx => $orderId) {
            $distances[$orderId] = $matrix[0][$idx]['distance_m'] ?? 0;
        }

        $distanceScores = $this->distanceScorer->scoreAll($distances);

        $scored = [];
        foreach ($orders as $order) {
            $ds = $distanceScores[$order->id] ?? 0;
            $ws = $this->waitingScorer->score($order);
            $wn = $this->windowScorer->score($order);
            $vs = $this->vipScorer->score($order, $merchant);

            $scored[$order->id] = [
                'distance_score' => $ds,
                'waiting_score'  => $ws,
                'window_score'   => $wn,
                'vip_score'      => $vs,
                'total_score'    => $ds + $ws + $wn + $vs,
            ];
        }

        return $scored;
    }

    private function assignClustersToDrivers(array $clusters, Collection $drivers, array $depot): array
    {
        $assignments = [];
        $usedDrivers = [];

        foreach ($clusters as $clusterIdx => $orderIds) {
            // Find cluster centroid
            $lats = $lngs = [];
            foreach ($orderIds as $orderId) {
                $order = DeliveryOrder::find($orderId);
                if ($order && $order->delivery_latitude) {
                    $lats[] = $order->delivery_latitude;
                    $lngs[] = $order->delivery_longitude;
                }
            }
            $centroid = empty($lats) ? $depot : ['lat' => array_sum($lats)/count($lats), 'lng' => array_sum($lngs)/count($lngs)];

            // Find nearest available driver
            $bestDriver = null;
            $bestDist   = PHP_FLOAT_MAX;

            foreach ($drivers as $driver) {
                if (isset($usedDrivers[$driver->id])) continue;

                $driverPos = ($driver->current_lat && $driver->current_lng)
                    ? ['lat' => $driver->current_lat, 'lng' => $driver->current_lng]
                    : $depot;

                $dist = $this->haversineM($driverPos, $centroid);
                if ($dist < $bestDist) {
                    $bestDist   = $dist;
                    $bestDriver = $driver;
                }
            }

            if (!$bestDriver) {
                $bestDriver = $drivers->first(fn($d) => !isset($usedDrivers[$d->id])) ?? $drivers->first();
            }

            $usedDrivers[$bestDriver->id] = true;
            $assignments[$clusterIdx] = [
                'driver_id' => $bestDriver->id,
                'order_ids' => $orderIds,
            ];
        }

        return $assignments;
    }

    private function optimizeCluster(Driver $driver, array $depot, Collection $orders, array $scoredOrders, $settings): array
    {
        // Build coord list: [0 = depot/driver, 1..N = stops]
        $driverPos = ($driver->current_lat && $driver->current_lng)
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
                $stopsData[$order->id] = [
                    'lat'         => $order->delivery_latitude,
                    'lng'         => $order->delivery_longitude,
                    'total_score' => $scoredOrders[$order->id]['total_score'] ?? 0,
                ];
            }
        }

        // Phase 1: Nearest neighbor
        $orderedIds = $this->nnSolver->solve($driverPos, $stopsData, $matrix, $indexMap);

        // Phase 2: 2-opt improvement
        $orderedIds = $this->twoOpt->improve($orderedIds, $matrix, $indexMap);

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
