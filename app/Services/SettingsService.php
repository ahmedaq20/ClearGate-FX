<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(
            "settings.{$key}",
            now()->addHour(),
            fn (): mixed => Setting::query()->where('key', $key)->value('value') ?? $default
        );
    }

    public function set(string $key, mixed $value): void
    {
        $setting = Setting::query()->firstOrNew(
            ['key' => $key],
            [
                'type' => $this->resolveType($value),
                'group_name' => 'general',
            ]
        );

        $setting->value = $this->normalizeValue($value);
        $setting->save();

        Cache::forget("settings.{$key}");
    }

    /**
     * @return array<string, mixed>
     */
    public function group(string $group): array
    {
        return Setting::query()
            ->where('group_name', $group)
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function publicSettings(): array
    {
        return Setting::query()
            ->where('is_public', true)
            ->pluck('value', 'key')
            ->toArray();
    }

    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private function resolveType(mixed $value): string
    {
        return match (true) {
            is_int($value) => 'integer',
            is_bool($value) => 'boolean',
            is_array($value), is_object($value) => 'json',
            default => 'string',
        };
    }
}
