<?php

namespace App\Services;

use App\Models\MerchantActivityLog;

class MerchantActivityService
{
    public static function log(
        int $merchantId,
        string $eventType,
        string $description,
        array $context = [],
        ?int $actorId = null
    ): MerchantActivityLog {
        return MerchantActivityLog::create([
            'merchant_id' => $merchantId,
            'event_type'  => $eventType,
            'description' => $description,
            'context'     => empty($context) ? null : $context,
            'actor_id'    => $actorId,
        ]);
    }
}
