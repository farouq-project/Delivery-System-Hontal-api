<?php

namespace App\Events;

use App\Models\Merchant;
use App\Models\Route;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RouteGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Route    $route,
        public readonly Merchant $merchant,
        public readonly User     $actor,
        public readonly int      $orderCount,
    ) {}
}
