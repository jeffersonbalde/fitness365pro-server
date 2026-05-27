<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminEvent;
use App\Models\ClientAdminEventRunningSelection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EventRunningSelectionController extends Controller
{
    private function publishedEventOr404(string $id): ?AdminEvent
    {
        if (!Schema::hasTable('admin_events')) {
            return null;
        }

        $now = now('UTC');

        return AdminEvent::query()
            ->where('id', $id)
            ->active($now)
            ->first();
    }

    private function runningDetailsOrNull(AdminEvent $event): ?array
    {
        $rd = $event->running_details;
        if (!is_array($rd) || ($event->category ?? '') !== 'running') {
            return null;
        }

        return $rd;
    }

    public function show(Request $request, string $id)
    {
        if (!Schema::hasTable('client_admin_event_running_selections')) {
            return response()->json(['success' => true, 'data' => ['selection' => null]]);
        }

        $event = $this->publishedEventOr404($id);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        $row = ClientAdminEventRunningSelection::query()
            ->where('client_id', $request->user()->id)
            ->where('admin_event_id', $id)
            ->first();

        if (!$row) {
            return response()->json(['success' => true, 'data' => ['selection' => null]]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'selection' => [
                    'distance_key' => $row->distance_key,
                    'distance_label' => $row->distance_label,
                    'package_key' => $row->package_key,
                    'package_label' => $row->package_label,
                    'package_includes_shirt' => (bool) $row->package_includes_shirt,
                    'shirt_size' => $row->shirt_size,
                    'updated_at' => $row->updated_at?->toISOString(),
                ],
            ],
        ]);
    }

    public function update(Request $request, string $id)
    {
        if (!Schema::hasTable('client_admin_event_running_selections')) {
            return response()->json(['success' => false, 'message' => 'Selections are not available yet.'], 409);
        }

        $event = $this->publishedEventOr404($id);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        $rd = $this->runningDetailsOrNull($event);
        if (!$rd) {
            return response()->json(['success' => false, 'message' => 'This event does not offer running distance or package choices.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'distance_key' => 'required|string|max:16',
            'distance_label' => 'nullable|string|max:120',
            'package_key' => 'required|string|max:32',
            'package_label' => 'nullable|string|max:200',
            'package_includes_shirt' => 'nullable|boolean',
            'shirt_size' => 'nullable|string|max:8',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Validation failed'], 422);
        }

        $distanceKey = strtolower(trim((string) $request->input('distance_key')));
        $distanceLabel = $request->filled('distance_label') ? Str::limit(trim((string) $request->input('distance_label')), 120, '') : null;
        $packageKey = strtolower(trim((string) $request->input('package_key')));
        $packageLabel = $request->filled('package_label') ? Str::limit(trim((string) $request->input('package_label')), 200, '') : null;
        $includesShirt = $request->boolean('package_includes_shirt');
        $shirtSize = $request->filled('shirt_size') ? strtoupper(trim((string) $request->input('shirt_size'))) : null;

        if (!$this->distanceMatchesOffer($rd, $distanceKey, $distanceLabel)) {
            return response()->json(['success' => false, 'message' => 'The selected distance is not offered for this event.'], 422);
        }

        if (!$this->packageMatchesOffer($rd, $packageKey, $packageLabel, $includesShirt)) {
            return response()->json(['success' => false, 'message' => 'The selected package is not offered for this event.'], 422);
        }

        $needsShirt = $this->selectionRequiresShirtSize($packageKey, $includesShirt);
        $allowedSizes = $this->allowedShirtSizes($rd);
        if ($needsShirt) {
            if ($shirtSize === null || $shirtSize === '') {
                return response()->json(['success' => false, 'message' => 'Shirt size is required for the selected package.'], 422);
            }
            if (!in_array($shirtSize, $allowedSizes, true)) {
                return response()->json(['success' => false, 'message' => 'The shirt size is not offered for this event.'], 422);
            }
        } else {
            $shirtSize = null;
        }

        $selection = ClientAdminEventRunningSelection::query()->updateOrCreate(
            [
                'client_id' => $request->user()->id,
                'admin_event_id' => $id,
            ],
            [
                'distance_key' => $distanceKey,
                'distance_label' => $distanceKey === 'other' ? $distanceLabel : null,
                'package_key' => $packageKey,
                'package_label' => $packageKey === 'other' ? $packageLabel : null,
                'package_includes_shirt' => $packageKey === 'other' ? $includesShirt : in_array($packageKey, ['medal_shirt', 'medal_shirt_kit'], true),
                'shirt_size' => $shirtSize,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Your race choices have been saved.',
            'data' => [
                'selection' => [
                    'distance_key' => $selection->distance_key,
                    'distance_label' => $selection->distance_label,
                    'package_key' => $selection->package_key,
                    'package_label' => $selection->package_label,
                    'package_includes_shirt' => (bool) $selection->package_includes_shirt,
                    'shirt_size' => $selection->shirt_size,
                    'updated_at' => $selection->updated_at?->toISOString(),
                ],
            ],
        ]);
    }

    private function selectionRequiresShirtSize(string $packageKey, bool $includesShirtFromOther): bool
    {
        if (in_array($packageKey, ['medal_shirt', 'medal_shirt_kit'], true)) {
            return true;
        }

        return $packageKey === 'other' && $includesShirtFromOther;
    }

    /**
     * @return list<string>
     */
    private function allowedShirtSizes(array $runningDetails): array
    {
        $sizes = $runningDetails['shirt_sizes'] ?? [];
        if (!is_array($sizes)) {
            return [];
        }
        $allowed = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'];
        $out = [];
        foreach ($sizes as $sz) {
            $u = strtoupper(trim((string) $sz));
            if (in_array($u, $allowed, true)) {
                $out[] = $u;
            }
        }

        return array_values(array_unique($out));
    }

    private function distanceMatchesOffer(array $rd, string $distanceKey, ?string $distanceLabel): bool
    {
        $distances = $this->normalizeDistancesFromDetails($rd);
        foreach ($distances as $d) {
            $k = strtolower((string) ($d['key'] ?? ''));
            if ($k !== $distanceKey) {
                continue;
            }
            if ($k === 'other') {
                $lbl = trim((string) ($d['label'] ?? ''));

                return $lbl !== '' && $lbl === trim((string) $distanceLabel);
            }

            return true;
        }

        return false;
    }

    private function packageMatchesOffer(array $rd, string $packageKey, ?string $packageLabel, bool $includesShirtFromRequest): bool
    {
        $packages = $this->normalizePackagesFromDetails($rd);
        foreach ($packages as $p) {
            $k = strtolower((string) ($p['key'] ?? ''));
            if ($k !== $packageKey) {
                continue;
            }
            if ($k === 'other') {
                $lbl = trim((string) ($p['label'] ?? ''));
                $reqShirt = (bool) ($p['includes_shirt'] ?? false);
                if ($lbl === '' || $lbl !== trim((string) $packageLabel)) {
                    return false;
                }

                return $reqShirt === $includesShirtFromRequest;
            }

            return true;
        }

        return false;
    }

    /**
     * @return list<array{key: string, label?: string}>
     */
    private function normalizeDistancesFromDetails(array $rd): array
    {
        if (isset($rd['distances']) && is_array($rd['distances'])) {
            return $rd['distances'];
        }

        $legacy = strtolower(trim((string) ($rd['distance'] ?? '')));
        if ($legacy === 'other' && filled($rd['distance_custom'] ?? null)) {
            return [['key' => 'other', 'label' => (string) $rd['distance_custom']]];
        }
        if (in_array($legacy, ['3k', '5k', '10k', '21k', '42k'], true)) {
            return [['key' => $legacy]];
        }

        return [];
    }

    /**
     * @return list<array{key: string, label?: string, includes_shirt?: bool}>
     */
    private function normalizePackagesFromDetails(array $rd): array
    {
        if (isset($rd['packages']) && is_array($rd['packages'])) {
            return $rd['packages'];
        }

        $legacy = strtolower(trim((string) ($rd['package'] ?? '')));
        if ($legacy === 'other' && filled($rd['package_custom'] ?? null)) {
            return [[
                'key' => 'other',
                'label' => (string) $rd['package_custom'],
                'includes_shirt' => (bool) ($rd['package_includes_shirt'] ?? false),
            ]];
        }
        if (in_array($legacy, ['medal', 'medal_shirt', 'medal_shirt_kit'], true)) {
            return [['key' => $legacy]];
        }

        return [];
    }
}
