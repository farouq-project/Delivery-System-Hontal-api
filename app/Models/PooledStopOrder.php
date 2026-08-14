<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class PooledStopOrder extends Pivot
{
    public $timestamps = false;

    protected $table = 'pooled_stop_orders';
}
