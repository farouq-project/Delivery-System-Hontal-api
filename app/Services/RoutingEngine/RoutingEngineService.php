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
use App\Services\RoutingEngine\Preprocessing\HaversineMatrix;
use App\Services\RoutingEngine\Scheduling\BatchSeparator;
use App\Services\RoutingEngine\Scheduling\TimeWindowClassifier;
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
        private GeographicClusterer         $clusterer,
        private HaversineMatrix             $haversineMatrix,
        private BatchSeparator              $batchSeparator,
        private TimeWindowClassifier        $twClassifier,
    ) {}

    public function generate(Merchant $merchant, string $routeDate): Route
    {
        $settings = $merchant->settings
            ?? new \App\Models\MerchantSetting(['depot_latitude' => -6.9175, 'depot_longitude' => 107.6191]);
        $depot = ['lat' => $settings->depot_latitude ?? -6.9175, 'lng' => $settings->depot_longitude ?? 107.6191];
        $mode  = $settings->routing_mode ?? 'balanced';

        $pendingOrders = DeliveryOrder::with('customer')
            ->where('merchant_id', $merchant->id)
            ->where('status', 'pending')
            ->whereNull('driver_id')
            ->where(function ($q) use ($routeDate) {
                $q->where('requested_delivery_date', $routeDate)
                  ->orWhereNull('requested_delivery_date');
            })
            ->get();

        if ($pendingOrders->isEmpty()) {
            throw new \RuntimeException('No unassigned orders to route.');
        }

        $route = Route::firstOrCreate(
            ['merchant_id' => $merchant->id, 'route_date' => $routeDate],
            ['ulid' => Str::ulid(), 'status' => 'active', 'generation_method' => 'auto', 'generated_at' => now()]
        );
        $route->update(['status' => 'active', 'generation_method' => 'auto', 'generated_at' => now()]);

        $algorithm    = $settings->routing_algorithm ?? 'balanced';
        $scoredOrders = $this->scoreOrders($pendingOrders, $merchant, $depot, $algorithm);

        $assignment = RouteAssignment::firstOrCreate(
            ['route_id' => $route->id, 'driver_id' => null],
            ['sequence_number' => 0, 'status' => 'pending']
        );
        RouteStop::where('route_assignment_id', $assignment->id)->delete();

        [$orderedIds, $distSum, $etaData, $analytics] = $this->optimizeV2(
            null, $depot, $pendingOrders, $scoredOrders, $settings, $mode
        );

        Log::info('[ROUTE V2] Generated sequence', [
            'route_date'   => $routeDate,
            'mode'         => $mode,
            'order_count'  => $pendingOrders->count(),
            'analytics'    => $analytics,
        ]);

        $this->createStops($route, $assignment, $orderedIds, $scoredOrders, $etaData);

        $assignment->update(['total_stops' => count($orderedIds), 'total_distance_m' => $distSum]);

        $route->update([
            'total_stops'                    => RouteStop::where('route_id', $route->id)->count(),
            'total_distance_m'               => $route->assignments()->sum('total_distance_m'),
            'total_drivers'                  => $route->assignments()->whereNotNull('driver_id')->count(),
            'routing_mode'                   => $mode,
            'distance_before_optimization_m' => $analytics['distance_before_m'],
            'optimization_saving_m'          => $analytics['saving_m'],
            'google_calls'                   => $analytics['google_calls'],
            'cache_hits'                     => $analytics['cache_hits'],
            'quality_score'                  => $analytics['quality_score'],
            'batch_count'                    => $analytics['batch_count'],
        ]);

        return $route->fresh()->load(['assignments.driver', 'assignments.stops.order']);
    }

    /**
     * V2 pipeline:
     *   1. Batch separation (morning / afternoon / late)
     *   2. Geographic clustering (0.01° grid)
     *   3. Haversine NxN matrix (O(N²) pre-compute)
     *   4. Per-batch: NN solve with geographic group_keys
     *   5. Per-batch: 2-opt improvement (economy skips)
     *   6. Per-batch: tier sort (HIGH → NORMAL → FLEXIBLE)
     *   7. Google refinement for ETAs (optimized mode only)
     *   8. ETA calculation from final matrix
     */
    private function optimizeV2(?Driver $driver, array $depot, Collection $orders, array $scoredOrders, mixed $settings, string $mode): array
    {
        $origin = ($driver && $driver->current_lat && $driver->current_lng)
            ? ['lat' => $driver->current_lat, 'lng' => $driver->current_lng]
            : $depot;

        $locatedOrders   = $orders->filter(fn($o) => $o->delivery_latitude && $o->delivery_longitude)->values();
        $unlocatedOrders = $orders->reject(fn($o) => $o->delivery_latitude && $o->delivery_longitude)->values();

        if ($locatedOrders->isEmpty()) {
            $ids = $unlocatedOrders->sortByDesc(fn($o) => $scoredOrders[$o->id]['total_score'] ?? 0)->pluck('id')->all();
            return [$ids, 0, [], $this->emptyAnalytics()];
        }

        // Step 1 — batch separation
        $batchMap = $this->batchSeparator->separate($orders);

        // Step 2 — geographic clustering over all located orders
        $stopsData = [];
        foreach ($locatedOrders as $order) {
            $stopsData[$order->id] = [
                'lat'         => (float) $order->delivery_latitude,
                'lng'         => (float) $order->delivery_longitude,
                'total_score' => $scoredOrders[$order->id]['total_score'] ?? 0,
            ];
        }
        $clusterMap = $this->clusterer->clusterStops($stopsData);
        foreach ($stopsData as $id => $s) {
            $stopsData[$id]['group_key'] = $clusterMap[$id] ?? null;
        }

        // Step 3 — Haversine matrix (index 0 = origin/depot, 1..N = stops)
        $allPoints = [$origin];
        $indexMap  = [];
        foreach ($locatedOrders as $order) {
            $allPoints[]          = ['lat' => (float) $order->delivery_latitude, 'lng' => (float) $order->delivery_longitude];
            $indexMap[$order->id] = count($allPoints) - 1;
        }
        $hvMatrix = $this->haversineMatrix->build($allPoints);

        // Step 4–6 — per-batch: NN → 2-opt → tier sort
        $classified    = $this->twClassifier->classify($scoredOrders);
        $orderedIds    = [];
        $distanceBefore = 0.0;
        $distanceAfter  = 0.0;

        foreach ($this->batchSeparator->batchOrder() as $batch) {
            $batchStops = array_filter(
                $stopsData,
                fn($id) => ($batchMap[$id] ?? BatchSeparator::MORNING) === $batch,
                ARRAY_FILTER_USE_KEY
            );
            if (empty($batchStops)) continue;

            // NN with geographic group_keys
            $batchIds = $this->nnSolver->solveGrouped($batchStops, $hvMatrix, $indexMap);

            // Measure before 2-opt
            $distanceBefore += $this->totalDistance($batchIds, $indexMap, $hvMatrix);

            // 2-opt (economy mode skips)
            if (in_array($mode, ['balanced', 'optimized'])) {
                $batchIds = $this->twoOpt->improve($batchIds, $hvMatrix, $indexMap);
            }

            $distanceAfter += $this->totalDistance($batchIds, $indexMap, $hvMatrix);

            // Tier sort within this batch: HIGH → NORMAL → FLEXIBLE
            $batchIds    = $this->twClassifier->sortByTier($batchIds, $classified);
            $orderedIds  = array_merge($orderedIds, $batchIds);
        }

        // Step 7 — Google refinement for final ETAs (optimized mode only)
        $googleCalls = 0;
        $cacheHits   = 0;
        $etaMatrix   = $hvMatrix;

        if ($mode === 'optimized' && count($allPoints) > 1) {
            try {
                $etaMatrix   = $this->distanceMatrix->getMatrix($allPoints, $allPoints);
                $googleCalls = 1;
            } catch (\Throwable $e) {
                Log::warning('[ROUTE V2] Google refinement failed, using Haversine ETAs', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Append unlocated orders after the located sequence (sorted by score desc)
        $unlocatedIds = $unlocatedOrders->sortByDesc(fn($o) => $scoredOrders[$o->id]['total_score'] ?? 0)->pluck('id')->all();
        $orderedIds   = [...$orderedIds, ...$unlocatedIds];

        // Step 8 — ETA calculation
        [$totalDistM, $etaData] = $this->buildEtaData($orderedIds, $indexMap, $etaMatrix, $settings);

        $savingM      = max(0, $distanceBefore - $distanceAfter);
        $qualityScore = $distanceBefore > 0 ? round(($savingM / $distanceBefore) * 100, 1) : 0.0;
        $batchCount   = count(array_unique(array_values($batchMap)));

        $analytics = [
            'distance_before_m' => (int) $distanceBefore,
            'saving_m'          => (int) $savingM,
            'google_calls'      => $googleCalls,
            'cache_hits'        => $cacheHits,
            'quality_score'     => $qualityScore,
            'batch_count'       => $batchCount,
        ];

        return [$orderedIds, $totalDistM, $etaData, $analytics];
    }

    private function buildEtaData(array $orderedIds, array $indexMap, array $matrix, mixed $settings): array
    {
        $currentTime = now()->setTimeFromTimeString($settings->working_hours_start ?? '07:00:00');
        $prevIdx     = 0;
        $totalDistM  = 0;
        $etaData     = [];

        foreach ($orderedIds as $seq => $orderId) {
            $curIdx = $indexMap[$orderId] ?? null;
            if ($curIdx === null) continue;

            $leg         = $matrix[$prevIdx][$curIdx] ?? ['distance_m' => 0, 'duration_min' => 5];
            $totalDistM += $leg['distance_m'];
            $currentTime->addMinutes((int) round($leg['duration_min']) + 5);

            $etaData[$seq] = [
                'eta'          => $currentTime->copy(),
                'distance_m'   => $leg['distance_m'],
                'duration_min' => $leg['duration_min'],
            ];

            $prevIdx = $curIdx;
        }

        return [(int) $totalDistM, $etaData];
    }

    private function totalDistance(array $orderedIds, array $indexMap, array $matrix): float
    {
        $total   = 0.0;
        $prevIdx = 0;
        foreach ($orderedIds as $id) {
            $curIdx = $indexMap[$id] ?? null;
            if ($curIdx === null) continue;
            $total  += $matrix[$prevIdx][$curIdx]['distance_m'] ?? 0;
            $prevIdx = $curIdx;
        }
        return $total;
    }

    private function emptyAnalytics(): array
    {
        return ['distance_before_m' => 0, 'saving_m' => 0, 'google_calls' => 0, 'cache_hits' => 0, 'quality_score' => 0.0, 'batch_count' => 1];
    }

    public function reoptimize(Route $route, array $newOrderIds): Route
    {
        $merchant = $route->merchant;
        $settings = $merchant->settings
            ?? new \App\Models\MerchantSetting(['depot_latitude' => -6.9175, 'depot_longitude' => 107.6191]);
        $depot = ['lat' => $settings->depot_latitude ?? -6.9175, 'lng' => $settings->depot_longitude ?? 107.6191];

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
                $bestPos  = $remainingStops->count();

                for ($i = 0; $i <= $remainingStops->count(); $i++) {
                    $prev = $i === 0
                        ? $depot
                        : ['lat' => $remainingStops[$i - 1]->order->delivery_latitude, 'lng' => $remainingStops[$i - 1]->order->delivery_longitude];
                    $next = $i < $remainingStops->count()
                        ? ['lat' => $remainingStops[$i]->order->delivery_latitude, 'lng' => $remainingStops[$i]->order->delivery_longitude]
                        : null;
                    $newPt = ['lat' => $newOrder->delivery_latitude, 'lng' => $newOrder->delivery_longitude];

                    $ins          = $this->haversineM($prev, $newPt) + ($next ? $this->haversineM($newPt, $next) : 0);
                    $rem          = $next ? $this->haversineM($prev, $next) : 0;
                    $scoreBoost   = ($scoredNew[$newOrder->id]['total_score'] ?? 0) / 100.0;
                    $adjustedCost = ($ins - $rem) / (1 + $scoreBoost);

                    if ($adjustedCost < $bestCost) {
                        $bestCost = $adjustedCost;
                        $bestPos  = $i;
                    }
                }

                $assignment->stops()->where('stop_sequence', '>', $bestPos)->increment('stop_sequence');

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

    private function createStops(Route $route, RouteAssignment $assignment, array $orderedIds, array $scoredOrders, array $etaData): void
    {
        foreach ($orderedIds as $seq => $orderId) {
            $scored   = $scoredOrders[$orderId] ?? [];
            $etaEntry = $etaData[$seq] ?? [];

            RouteStop::create([
                'route_id'               => $route->id,
                'route_assignment_id'    => $assignment->id,
                'order_id'               => $orderId,
                'stop_sequence'          => $seq + 1,
                'distance_score'         => $scored['distance_score'] ?? 0,
                'waiting_score'          => $scored['waiting_score'] ?? 0,
                'window_score'           => $scored['window_score'] ?? 0,
                'vip_score'              => $scored['vip_score'] ?? 0,
                'total_score'            => $scored['total_score'] ?? 0,
                'estimated_arrival'      => $etaEntry['eta'] ?? null,
                'distance_from_prev_m'   => $etaEntry['distance_m'] ?? null,
                'duration_from_prev_min' => $etaEntry['duration_min'] ?? null,
            ]);

            DeliveryOrder::where('id', $orderId)->update(['route_sequence' => $seq + 1]);
        }
    }

    private function scoreOrders(Collection $orders, Merchant $merchant, array $depot, string $algorithm = 'balanced'): array
    {
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
            $matrix      = $this->distanceMatrix->getMatrix($originPoint, $destPoints);
            $distances   = [];
            foreach ($destIds as $idx => $orderId) {
                $distances[$orderId] = $matrix[0][$idx]['distance_m'] ?? 0;
            }
            $distanceScores = $this->distanceScorer->scoreAll($distances);
        }

        $scored = [];
        foreach ($orders as $order) {
            $ds    = $distanceScores[$order->id] ?? 0;
            $ws    = $this->waitingScorer->score($order);
            $wn    = $this->windowScorer->score($order);
            $vs    = $this->vipScorer->score($order, $merchant);
            $total = match ($algorithm) {
                'distance' => $ds,
                'vip'      => $vs * 3 + $ds,
                default    => $ds + $ws + $wn + $vs,
            };
            $scored[$order->id] = [
                'distance_score' => $ds,
                'waiting_score'  => $ws,
                'window_score'   => $wn,
                'vip_score'      => $vs,
                'total_score'    => $total,
            ];
        }

        return $scored;
    }

    private function haversineM(array $from, array $to): float
    {
        $R    = 6371000;
        $dLat = deg2rad($to['lat'] - $from['lat']);
        $dLng = deg2rad($to['lng'] - $from['lng']);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($from['lat'])) * cos(deg2rad($to['lat'])) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
