<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmActivity extends Model
{
    protected $fillable = [
        'prospect_id',
        'type',
        'content',
        'created_by',
    ];

    public function prospect()
    {
        return $this->belongsTo(CrmProspect::class, 'prospect_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
