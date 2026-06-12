<?php

namespace App\Services\RoutingEngine\Scoring;

use App\Models\DeliveryOrder;
use App\Models\Merchant;

class VipScorer
{
    private array $vipScoreCache = [];

    public function score(DeliveryOrder $order, Merchant $merchant): float
    {
        $customer = $order->customer;
        if (!$customer) {
            return 0.0;
        }

        $level = $customer->vip_level ?? 'standard';

        if (!isset($this->vipScoreCache[$merchant->id])) {
            $this->vipScoreCache[$merchant->id] = $merchant->vipConfigs->pluck('score_value', 'vip_level')->toArray();
        }

        $defaults = ['standard' => 0, 'silver' => 50, 'gold' => 100, 'platinum' => 200];
        return (float) ($this->vipScoreCache[$merchant->id][$level] ?? $defaults[$level] ?? 0);
    }
}
