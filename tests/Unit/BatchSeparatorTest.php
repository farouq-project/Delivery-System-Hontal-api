<?php

namespace Tests\Unit;

use App\Services\RoutingEngine\Scheduling\BatchSeparator;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class BatchSeparatorTest extends TestCase
{
    private BatchSeparator $separator;

    protected function setUp(): void
    {
        $this->separator = new BatchSeparator();
    }

    private function makeOrder(string $createdAt, ?string $windowStart = null): object
    {
        return (object) [
            'id'                   => rand(1, 9999),
            'order_created_at'     => Carbon::parse($createdAt),
            'delivery_window_start'=> $windowStart,
            'created_at'           => Carbon::parse($createdAt),
        ];
    }

    public function test_morning_order_classified_correctly(): void
    {
        $order = $this->makeOrder('2026-07-17 08:00:00');
        $result = $this->separator->separate(new Collection([$order]));
        $this->assertSame(BatchSeparator::MORNING, $result[$order->id]);
    }

    public function test_afternoon_order_classified_correctly(): void
    {
        $order = $this->makeOrder('2026-07-17 14:00:00');
        $result = $this->separator->separate(new Collection([$order]));
        $this->assertSame(BatchSeparator::AFTERNOON, $result[$order->id]);
    }

    public function test_late_order_classified_correctly(): void
    {
        $order = $this->makeOrder('2026-07-17 18:00:00');
        $result = $this->separator->separate(new Collection([$order]));
        $this->assertSame(BatchSeparator::LATE, $result[$order->id]);
    }

    public function test_boundary_at_noon_is_afternoon(): void
    {
        $order = $this->makeOrder('2026-07-17 12:00:00');
        $result = $this->separator->separate(new Collection([$order]));
        $this->assertSame(BatchSeparator::AFTERNOON, $result[$order->id]);
    }

    public function test_boundary_at_17_is_late(): void
    {
        $order = $this->makeOrder('2026-07-17 17:00:00');
        $result = $this->separator->separate(new Collection([$order]));
        $this->assertSame(BatchSeparator::LATE, $result[$order->id]);
    }

    public function test_window_start_overrides_created_at(): void
    {
        // Created at 08:00 morning, but delivery window is 14:30 (afternoon)
        $order = $this->makeOrder('2026-07-17 08:00:00', '2026-07-17 14:30:00');
        $result = $this->separator->separate(new Collection([$order]));
        $this->assertSame(BatchSeparator::AFTERNOON, $result[$order->id]);
    }

    public function test_batch_order_is_morning_afternoon_late(): void
    {
        $this->assertSame(
            [BatchSeparator::MORNING, BatchSeparator::AFTERNOON, BatchSeparator::LATE],
            $this->separator->batchOrder()
        );
    }

    public function test_empty_collection_returns_empty_array(): void
    {
        $this->assertSame([], $this->separator->separate(new Collection([])));
    }
}
