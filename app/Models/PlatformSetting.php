<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'description'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();
        if (! $setting) {
            return $default;
        }

        return static::castValue($setting->value, $setting->type);
    }

    public static function set(string $key, mixed $value, string $type = 'string', ?string $description = null): static
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value, 'type' => $type, 'description' => $description]
        );
    }

    public static function all($columns = ['*'])
    {
        return parent::all($columns)->map(fn($s) => [
            'key'         => $s->key,
            'value'       => static::castValue($s->value, $s->type),
            'type'        => $s->type,
            'description' => $s->description,
        ])->keyBy('key');
    }

    private static function castValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json'    => json_decode($value, true),
            default   => $value,
        };
    }
}
