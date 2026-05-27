<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminEvent;
use App\Models\ClientAdminEventGymSelection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EventGymSelectionController extends Controller
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

    private function gymDetailsOrNull(AdminEvent $event): ?array
    {
        $gd = $event->gym_details;
        if (!is_array($gd) || ($event->category ?? '') !== 'gym') {
            return null;
        }

        return $gd;
    }

    public function show(Request $request, string $id)
    {
        if (!Schema::hasTable('client_admin_event_gym_selections')) {
            return response()->json(['success' => true, 'data' => ['selection' => null]]);
        }

        $event = $this->publishedEventOr404($id);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        $row = ClientAdminEventGymSelection::query()
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
                    'program_key' => $row->program_key,
                    'program_label' => $row->program_label,
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
        if (!Schema::hasTable('client_admin_event_gym_selections')) {
            return response()->json(['success' => false, 'message' => 'Selections are not available yet.'], 409);
        }

        $event = $this->publishedEventOr404($id);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        $gd = $this->gymDetailsOrNull($event);
        if (!$gd) {
            return response()->json(['success' => false, 'message' => 'This event does not offer gym program or package choices.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'program_key' => 'required|string|max:24',
            'program_label' => 'nullable|string|max:120',
            'package_key' => 'required|string|max:32',
            'package_label' => 'nullable|string|max:200',
            'package_includes_shirt' => 'nullable|boolean',
            'shirt_size' => 'nullable|string|max:8',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Validation failed'], 422);
        }

        $programKey = strtolower(trim((string) $request->input('program_key')));
        $programLabel = $request->filled('program_label') ? Str::limit(trim((string) $request->input('program_label')), 120, '') : null;
        $packageKey = strtolower(trim((string) $request->input('package_key')));
        $packageLabel = $request->filled('package_label') ? Str::limit(trim((string) $request->input('package_label')), 200, '') : null;
        $includesShirt = $request->boolean('package_includes_shirt');
        $shirtSize = $request->filled('shirt_size') ? strtoupper(trim((string) $request->input('shirt_size'))) : null;

        if (!$this->programMatchesOffer($gd, $programKey, $programLabel)) {
            return response()->json(['success' => false, 'message' => 'The selected program is not offered for this event.'], 422);
        }

        if (!$this->packageMatchesOffer($gd, $packageKey, $packageLabel, $includesShirt)) {
            return response()->json(['success' => false, 'message' => 'The selected package is not offered for this event.'], 422);
        }

        $needsShirt = $this->selectionRequiresShirtSize($packageKey, $includesShirt);
        $allowedSizes = $this->allowedShirtSizes($gd);
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

        $selection = ClientAdminEventGymSelection::query()->updateOrCreate(
            [
                'client_id' => $request->user()->id,
                'admin_event_id' => $id,
            ],
            [
                'program_key' => $programKey,
                'program_label' => $programKey === 'other' ? $programLabel : null,
                'package_key' => $packageKey,
                'package_label' => $packageKey === 'other' ? $packageLabel : null,
                'package_includes_shirt' => $packageKey === 'other' ? $includesShirt : in_array($packageKey, ['premium_apparel', 'full_kit'], true),
                'shirt_size' => $shirtSize,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Your gym choices have been saved.',
            'data' => [
                'selection' => [
                    'program_key' => $selection->program_key,
                    'program_label' => $selection->program_label,
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
        if (in_array($packageKey, ['premium_apparel', 'full_kit'], true)) {
            return true;
        }

        return $packageKey === 'other' && $includesShirtFromOther;
    }

    /**
     * @return list<string>
     */
    private function allowedShirtSizes(array $gymDetails): array
    {
        $sizes = $gymDetails['shirt_sizes'] ?? [];
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

    private function programMatchesOffer(array $gd, string $programKey, ?string $programLabel): bool
    {
        $programs = $this->normalizeProgramsFromDetails($gd);
        foreach ($programs as $p) {
            $k = strtolower((string) ($p['key'] ?? ''));
            if ($k !== $programKey) {
                continue;
            }
            if ($k === 'other') {
                $lbl = trim((string) ($p['label'] ?? ''));

                return $lbl !== '' && $lbl === trim((string) $programLabel);
            }

            return true;
        }

        return false;
    }

    private function packageMatchesOffer(array $gd, string $packageKey, ?string $packageLabel, bool $includesShirtFromRequest): bool
    {
        $packages = $this->normalizePackagesFromDetails($gd);
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
    private function normalizeProgramsFromDetails(array $gd): array
    {
        if (isset($gd['programs']) && is_array($gd['programs'])) {
            return $gd['programs'];
        }

        $legacy = strtolower(trim((string) ($gd['program'] ?? '')));
        if ($legacy === 'other' && filled($gd['program_custom'] ?? null)) {
            return [['key' => 'other', 'label' => (string) $gd['program_custom']]];
        }
        if (in_array($legacy, ['strength', 'cardio', 'hiit', 'classes', 'hybrid', 'functional'], true)) {
            return [['key' => $legacy]];
        }

        return [];
    }

    /**
     * @return list<array{key: string, label?: string, includes_shirt?: bool}>
     */
    private function normalizePackagesFromDetails(array $gd): array
    {
        if (isset($gd['packages']) && is_array($gd['packages'])) {
            return $gd['packages'];
        }

        $legacy = strtolower(trim((string) ($gd['package'] ?? '')));
        if ($legacy === 'other' && filled($gd['package_custom'] ?? null)) {
            return [[
                'key' => 'other',
                'label' => (string) $gd['package_custom'],
                'includes_shirt' => (bool) ($gd['package_includes_shirt'] ?? false),
            ]];
        }
        if (in_array($legacy, ['day_pass', 'monthly_access', 'classes_bundle', 'premium_apparel', 'full_kit'], true)) {
            return [['key' => $legacy]];
        }

        return [];
    }
}
