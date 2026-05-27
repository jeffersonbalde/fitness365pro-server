<?php

namespace App\Support;

class RegistrationDeliveryCatalog
{
    /**
     * @return list<array{key: string, label: string, fee_php: float}>
     */
    public static function builtin(): array
    {
        return [
            ['key' => 'pickup', 'label' => 'Pickup at venue (PHP 0)', 'fee_php' => 0.0],
            ['key' => 'metro_manila', 'label' => 'Courier — Metro Manila', 'fee_php' => 150.0],
            ['key' => 'luzon', 'label' => 'Courier — Luzon (outside NCR)', 'fee_php' => 200.0],
            ['key' => 'visayas_mindanao', 'label' => 'Courier — Visayas / Mindanao', 'fee_php' => 250.0],
        ];
    }

    /** @param  array<string,mixed>|null  $fromEvent */
    public static function resolve(?array $fromEvent): array
    {
        if (! is_array($fromEvent) || $fromEvent === []) {
            return self::builtin();
        }

        $out = [];
        foreach ($fromEvent as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = strtolower(trim((string) ($row['key'] ?? '')));
            if ($key === '') {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                $label = $key;
            }
            $fee = round(max(0.0, (float) ($row['fee_php'] ?? 0)), 2);
            $out[] = ['key' => $key, 'label' => $label, 'fee_php' => $fee];
        }

        return $out !== [] ? $out : self::builtin();
    }

    /** @param  array<string,mixed>|null  $fromEvent */
    public static function feeByKey(?array $fromEvent, string $wantedKey): ?float
    {
        $wantedKey = strtolower(trim($wantedKey));
        foreach (self::resolve($fromEvent) as $area) {
            if ($area['key'] === $wantedKey) {
                return (float) $area['fee_php'];
            }
        }

        return null;
    }
}
