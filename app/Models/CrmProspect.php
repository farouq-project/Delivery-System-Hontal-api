<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmProspect extends Model
{
    protected $fillable = [
        'business_name',
        'category',
        'city',
        'address',
        'phone',
        'website',
        'instagram',
        'contact_person',
        'contact_role',
        'pipeline_stage',
        'notes',
        'last_contact_at',
        'next_followup_at',
        'created_by',
    ];

    protected $casts = [
        'last_contact_at'   => 'date',
        'next_followup_at'  => 'date',
    ];
}
