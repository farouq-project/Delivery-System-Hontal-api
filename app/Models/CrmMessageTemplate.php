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
            '{{business_name}}'  => $vars['business_name'] ?? '',
            '{{company_name}}'   => $vars['company_name'] ?? config('app.name', 'Hontal'),
            '{{coverage_area}}'  => $vars['coverage_area'] ?? $vars['city'] ?? '',
            '{{website}}'        => $vars['website'] ?? '',
            '{{phone}}'          => $vars['phone'] ?? '',
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $this->content
        );
    }
}
