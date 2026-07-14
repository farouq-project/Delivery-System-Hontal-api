<?php

namespace App\Observers;

use App\Events\CustomerCreated;
use App\Events\CustomerUpdated;
use App\Models\Customer;

class CustomerObserver
{
    public function created(Customer $customer): void
    {
        event(new CustomerCreated($customer));
    }

    public function updated(Customer $customer): void
    {
        $watched = [
            'phone', 'default_address', 'default_latitude', 'default_longitude',
            'vip_level', 'is_active',
        ];

        $changes = array_intersect_key($customer->getChanges(), array_flip($watched));

        if (!empty($changes)) {
            event(new CustomerUpdated($customer, $changes));
        }
    }
}
