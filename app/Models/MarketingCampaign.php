<?php

namespace App\Models;

use App\Models\Scopes\MerchantScope;
use Illuminate\Database\Eloquent\Model;

class MarketingCampaign extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new MerchantScope());
    }

    protected $fillable = [
        'merchant_id', 'name', 'platform', 'lead_source', 'campaign_type',
        'start_date', 'end_date',
        'budget', 'spend', 'reach', 'clicks', 'wa_conversations',
        'leads', 'orders', 'customers_acquired', 'attributed_revenue',
        'notes',
    ];

    protected $casts = [
        'start_date'          => 'date',
        'end_date'            => 'date',
        'budget'              => 'float',
        'spend'               => 'float',
        'attributed_revenue'  => 'float',
        'reach'               => 'integer',
        'clicks'              => 'integer',
        'wa_conversations'    => 'integer',
        'leads'               => 'integer',
        'orders'              => 'integer',
        'customers_acquired'  => 'integer',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    // ── Calculated KPIs ───────────────────────────────────────────────

    public function getCacAttribute(): ?float
    {
        return $this->customers_acquired > 0
            ? round($this->spend / $this->customers_acquired, 0)
            : null;
    }

    public function getCostPerLeadAttribute(): ?float
    {
        return $this->leads > 0
            ? round($this->spend / $this->leads, 0)
            : null;
    }

    public function getCostPerConversationAttribute(): ?float
    {
        return $this->wa_conversations > 0
            ? round($this->spend / $this->wa_conversations, 0)
            : null;
    }

    public function getRoasAttribute(): ?float
    {
        return $this->spend > 0
            ? round($this->attributed_revenue / $this->spend, 2)
            : null;
    }

    public function getConversionRateAttribute(): ?float
    {
        return $this->leads > 0
            ? round($this->orders / $this->leads * 100, 1)
            : null;
    }

    public function appendKpis(): static
    {
        $this->append(['cac', 'cost_per_lead', 'cost_per_conversation', 'roas', 'conversion_rate']);
        return $this;
    }
}
