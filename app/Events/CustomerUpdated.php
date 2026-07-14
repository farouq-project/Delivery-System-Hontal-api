<?php

namespace App\Events;

use App\Models\Customer;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CustomerUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Customer $customer,
        public readonly array    $changes,  // field => new_value, from getChanges()
    ) {}
}
