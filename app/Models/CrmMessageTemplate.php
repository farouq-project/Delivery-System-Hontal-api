<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmMessageTemplate extends Model
{
    protected $fillable = [
        'name',
        'content',
        'category',
        'created_by',
    ];

    public function preview(array $vars): string
    {
        $replacements = [
            '{{business_name}}'  => $vars['business_name']  ?? '',
            '{{contact_name}}'   => $vars['contact_name']   ?? $vars['contact_person'] ?? '',
            '{{company_name}}'   => $vars['company_name']   ?? config('app.name', 'Hontal'),
            '{{coverage_area}}'  => $vars['coverage_area']  ?? $vars['city'] ?? '',
            '{{city}}'           => $vars['city']           ?? '',
            '{{industry}}'       => $vars['industry']       ?? $vars['category'] ?? '',
            '{{website}}'        => $vars['website']        ?? '',
            '{{phone}}'          => $vars['phone']          ?? '',
            '{{address}}'        => $vars['address']        ?? '',
            '{{sender_name}}'    => $vars['sender_name']    ?? 'Tim Hontal',
            '{{sender_phone}}'   => $vars['sender_phone']   ?? '',
            '{{demo_link}}'      => $vars['demo_link']      ?? '',
            '{{trial_days}}'     => $vars['trial_days']     ?? '30',
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $this->content
        );
    }
}
