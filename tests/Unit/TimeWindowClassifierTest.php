<?php

namespace Tests\Unit;

use App\Services\RoutingEngine\Scheduling\TimeWindowClassifier;
use PHPUnit\Framework\TestCase;

class TimeWindowClassifierTest extends TestCase
{
    private TimeWindowClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new TimeWindowClassifier();
    }

    public function test_high_tier_at_threshold(): void
    {
        $scored = [1 => ['total_score' => 150]];
        $result = $this->classifier->classify($scored);
        $this->assertSame(TimeWindowClassifier::HIGH, $result[1]);
    }

    public function test_high_tier_above_threshold(): void
    {
        $scored = [1 => ['total_score' => 200]];
        $result = $this->classifier->classify($scored);
        $this->assertSame(TimeWindowClassifier::HIGH, $result[1]);
    }

    public function test_normal_tier_below_threshold(): void
    {
        $scored = [1 => ['total_score' => 80]];
        $result = $this->classifier->classify($scored);
        $this->assertSame(TimeWindowClassifier::NORMAL, $result[1]);
    }

    public function test_flexible_tier_at_zero(): void
    {
        $scored = [1 => ['total_score' => 0]];
        $result = $this->classifier->classify($scored);
        $this->assertSame(TimeWindowClassifier::FLEXIBLE, $result[1]);
    }

    public function test_sort_by_tier_orders_high_before_normal_before_flexible(): void
    {
        $classified = [
            10 => TimeWindowClassifier::FLEXIBLE,
            20 => TimeWindowClassifier::HIGH,
            30 => TimeWindowClassifier::NORMAL,
        ];

        $sorted = $this->classifier->sortByTier([10, 20, 30], $classified);

        $this->assertSame([20, 30, 10], $sorted);
    }

    public function test_sort_by_tier_is_stable_within_tier(): void
    {
        $classified = [
            1 => TimeWindowClassifier::NORMAL,
            2 => TimeWindowClassifier::NORMAL,
            3 => TimeWindowClassifier::HIGH,
        ];

        $sorted = $this->classifier->sortByTier([1, 2, 3], $classified);

        // HIGH (3) first, then NORMAL in original order (1, 2)
        $this->assertSame([3, 1, 2], $sorted);
    }

    public function test_classify_multiple_orders(): void
    {
        $scored = [
            1 => ['total_score' => 200],
            2 => ['total_score' => 50],
            3 => ['total_score' => 0],
        ];

        $result = $this->classifier->classify($scored);

        $this->assertSame(TimeWindowClassifier::HIGH, $result[1]);
        $this->assertSame(TimeWindowClassifier::NORMAL, $result[2]);
        $this->assertSame(TimeWindowClassifier::FLEXIBLE, $result[3]);
    }

    public function test_missing_score_treated_as_flexible(): void
    {
        $scored = [1 => []];
        $result = $this->classifier->classify($scored);
        $this->assertSame(TimeWindowClassifier::FLEXIBLE, $result[1]);
    }

    public function test_empty_input_returns_empty(): void
    {
        $this->assertSame([], $this->classifier->classify([]));
        $this->assertSame([], $this->classifier->sortByTier([], []));
    }
}
