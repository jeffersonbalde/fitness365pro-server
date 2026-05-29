<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\LogsAdminActivity;
use App\Http\Controllers\Controller;
use App\Models\AdminAnnouncement;
use App\Models\AdminEvent;
use App\Models\AdminPost;
use App\Support\PublicUploadStorage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminCmsController extends Controller
{
    use LogsAdminActivity;

    private function hydrateAdminEventMediaUrls(AdminEvent $event): AdminEvent
    {
        $event->image_url = PublicUploadStorage::resolveForClient($event->image_url);

        $badges = $event->badges;
        if (is_array($badges)) {
            $event->badges = array_map(function ($row) {
                if (!is_array($row)) {
                    return $row;
                }
                $row['image_url'] = PublicUploadStorage::resolveForClient($row['image_url'] ?? '');

                return $row;
            }, $badges);
        }

        return $event;
    }

    private function sanitizeEventBadgesInput(Request $request): array
    {
        $input = $request->input('badges', []);
        if (!is_array($input)) {
            return [];
        }

        $clean = [];
        foreach (array_slice($input, 0, 12) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['image_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $titleRaw = isset($row['title']) ? trim((string) $row['title']) : '';
            $clean[] = [
                'title' => $titleRaw !== '' ? Str::limit($titleRaw, 120, '') : null,
                'image_url' => Str::limit($url, 2048, ''),
            ];
        }

        return $clean;
    }

    /**
     * @return list<string>
     */
    private function sanitizeEventBulletStrings(mixed $raw, int $maxItems = 20, int $maxLen = 500): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_slice($raw, 0, $maxItems) as $line) {
            $s = trim((string) $line);
            if ($s === '') {
                continue;
            }
            $out[] = Str::limit($s, $maxLen, '');
        }

        return $out;
    }

    /**
     * @return array{how_it_works: list<string>, participant_rules: list<string>}
     */
    private function finalizedParticipationTexts(Request $request, ?AdminEvent $existing): array
    {
        $howRaw = $existing === null ? $request->input('how_it_works') : (
            $request->has('how_it_works') ? $request->input('how_it_works') : $existing->how_it_works
        );
        $rulesRaw = $existing === null ? $request->input('participant_rules') : (
            $request->has('participant_rules') ? $request->input('participant_rules') : $existing->participant_rules
        );

        return [
            'how_it_works' => $this->sanitizeEventBulletStrings($howRaw),
            'participant_rules' => $this->sanitizeEventBulletStrings($rulesRaw),
        ];
    }

    private function assertParticipationTextsValid(Request $request, \Illuminate\Validation\Validator $v, ?AdminEvent $existing): void
    {
        $texts = $this->finalizedParticipationTexts($request, $existing);
        if (count($texts['how_it_works']) < 1) {
            $v->errors()->add('how_it_works', 'Add at least one step under How this event works.');
        }
        if (count($texts['participant_rules']) < 1) {
            $v->errors()->add('participant_rules', 'Add at least one participant rule.');
        }
    }

    private function nullableCarbonFromRequestOrModel(Request $request, ?AdminEvent $model, string $key): ?Carbon
    {
        if ($request->has($key)) {
            $value = $request->input($key);
            if ($value === null || $value === '') {
                return null;
            }

            return Carbon::parse((string) $value);
        }
        if (!$model || $model->{$key} === null) {
            return null;
        }

        return Carbon::parse($model->{$key});
    }

    private function assertRegistrationWindowOrdered(Request $request, \Illuminate\Validation\Validator $v, ?AdminEvent $existing): void
    {
        $start = $this->nullableCarbonFromRequestOrModel($request, $existing, 'registration_starts_at');
        $deadline = $this->nullableCarbonFromRequestOrModel($request, $existing, 'registration_deadline');

        if ($start && $deadline && $start->greaterThan($deadline)) {
            $v->errors()->add(
                'registration_deadline',
                'Registration deadline must be on or after when registration opens.'
            );
        }
    }

    private function assertEventBadgesLabelsPresent(Request $request, \Illuminate\Validation\Validator $v): void
    {
        $badges = $request->input('badges', []);
        if (!is_array($badges)) {
            return;
        }

        foreach ($badges as $i => $row) {
            if (!is_array($row)) {
                continue;
            }

            $url = trim((string) ($row['image_url'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            if ($url !== '' && $title === '') {
                $v->errors()->add('badges.' . $i . '.title', 'Enter a public label for each badge image.');
            }
        }
    }

    private function assertPaidEventFeeValid(Request $request, \Illuminate\Validation\Validator $v): void
    {
        if (($request->input('fee_type') ?? 'free') !== 'paid') {
            return;
        }

        $raw = $request->input('fee');
        if (!is_numeric($raw) || (float) $raw <= 0) {
            $v->errors()->add('fee', 'Paid events require a registration fee greater than zero.');
        }
    }

    private function runningPackagesListRequiresShirt(array $packages): bool
    {
        foreach ($packages as $p) {
            if (!is_array($p)) {
                continue;
            }
            $k = strtolower(trim((string) ($p['key'] ?? '')));
            if (in_array($k, ['medal_shirt', 'medal_shirt_kit'], true)) {
                return true;
            }
            if ($k === 'other' && !empty($p['includes_shirt'])) {
                return true;
            }
        }

        return false;
    }

    private function gymPackagesListRequiresShirt(array $packages): bool
    {
        foreach ($packages as $p) {
            if (!is_array($p)) {
                continue;
            }
            $k = strtolower(trim((string) ($p['key'] ?? '')));
            if (in_array($k, ['premium_apparel', 'full_kit'], true)) {
                return true;
            }
            if ($k === 'other' && !empty($p['includes_shirt'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{key: string, label?: string}>
     */
    private function uniqueGymPrograms(array $rows): array
    {
        $seen = [];
        $uniq = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $k = strtolower(trim((string) ($row['key'] ?? '')));
            $sig = $k === 'other' ? 'other:' . trim((string) ($row['label'] ?? '')) : $k;
            if ($sig === '' || $sig === 'other:' || isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            $uniq[] = $k === 'other' ? ['key' => 'other', 'label' => trim((string) $row['label'])] : ['key' => $k];
        }

        return $uniq;
    }

    /**
     * @return list<array{key: string, label?: string, includes_shirt?: bool}>
     */
    private function uniqueGymPackages(array $rows): array
    {
        $seen = [];
        $uniq = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $k = strtolower(trim((string) ($row['key'] ?? '')));
            if ($k === 'other') {
                $lab = trim((string) ($row['label'] ?? ''));
                if ($lab === '') {
                    continue;
                }
                $sig = 'other:' . $lab . ':' . (!empty($row['includes_shirt']) ? '1' : '0');
            } else {
                $sig = $k;
            }
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            if ($k === 'other') {
                $uniq[] = [
                    'key' => 'other',
                    'label' => trim((string) $row['label']),
                    'includes_shirt' => (bool) ($row['includes_shirt'] ?? false),
                ];
            } else {
                $uniq[] = ['key' => $k];
            }
        }

        return $uniq;
    }

    /**
     * @return list<array{key: string, label?: string}>
     */
    private function uniqueRunningDistances(array $rows): array
    {
        $seen = [];
        $uniq = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $k = strtolower(trim((string) ($row['key'] ?? '')));
            $sig = $k === 'other' ? 'other:' . trim((string) ($row['label'] ?? '')) : $k;
            if ($sig === '' || $sig === 'other:' || isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            $uniq[] = $k === 'other' ? ['key' => 'other', 'label' => trim((string) $row['label'])] : ['key' => $k];
        }

        return $uniq;
    }

    /**
     * @return list<array{key: string, label?: string, includes_shirt?: bool}>
     */
    private function uniqueRunningPackages(array $rows): array
    {
        $seen = [];
        $uniq = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $k = strtolower(trim((string) ($row['key'] ?? '')));
            if ($k === 'other') {
                $lab = trim((string) ($row['label'] ?? ''));
                if ($lab === '') {
                    continue;
                }
                $sig = 'other:' . $lab . ':' . (!empty($row['includes_shirt']) ? '1' : '0');
            } else {
                $sig = $k;
            }
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            if ($k === 'other') {
                $uniq[] = [
                    'key' => 'other',
                    'label' => trim((string) $row['label']),
                    'includes_shirt' => (bool) ($row['includes_shirt'] ?? false),
                ];
            } else {
                $uniq[] = ['key' => $k];
            }
        }

        return $uniq;
    }

    /**
     * @return list<array{key: string, label?: string}>
     */
    private function sanitizeRunningDistancesArray(mixed $list, array $legacyRow): array
    {
        $out = [];
        if (is_array($list)) {
            $allowed = ['3k', '5k', '10k', '21k', '42k', 'other'];
            foreach (array_slice($list, 0, 8) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $k = strtolower(trim((string) ($row['key'] ?? '')));
                if (!in_array($k, $allowed, true)) {
                    continue;
                }
                if ($k === 'other') {
                    $label = Str::limit(trim((string) ($row['label'] ?? '')), 64, '');
                    if ($label === '') {
                        continue;
                    }
                    $out[] = ['key' => 'other', 'label' => $label];
                } else {
                    $out[] = ['key' => $k];
                }
            }
        }

        if (count($out) > 0) {
            return $this->uniqueRunningDistances($out);
        }

        $d = strtolower(trim((string) ($legacyRow['distance'] ?? '')));
        if ($d === 'other' && filled($legacyRow['distance_custom'] ?? null)) {
            return [['key' => 'other', 'label' => Str::limit(trim((string) $legacyRow['distance_custom']), 64, '')]];
        }
        if (in_array($d, ['3k', '5k', '10k', '21k', '42k'], true)) {
            return [['key' => $d]];
        }

        return [['key' => '5k']];
    }

    /**
     * @return list<array{key: string, label?: string, includes_shirt?: bool}>
     */
    private function sanitizeRunningPackagesArray(mixed $list, array $legacyRow): array
    {
        $out = [];
        if (is_array($list)) {
            $allowed = ['medal', 'medal_shirt', 'medal_shirt_kit', 'other'];
            foreach (array_slice($list, 0, 8) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $k = strtolower(trim((string) ($row['key'] ?? '')));
                if (!in_array($k, $allowed, true)) {
                    continue;
                }
                if ($k === 'other') {
                    $label = Str::limit(trim((string) ($row['label'] ?? '')), 120, '');
                    if ($label === '') {
                        continue;
                    }
                    $out[] = [
                        'key' => 'other',
                        'label' => $label,
                        'includes_shirt' => (bool) ($row['includes_shirt'] ?? false),
                    ];
                } else {
                    $out[] = ['key' => $k];
                }
            }
        }

        if (count($out) > 0) {
            return $this->uniqueRunningPackages($out);
        }

        $p = strtolower(trim((string) ($legacyRow['package'] ?? '')));
        if ($p === 'other' && filled($legacyRow['package_custom'] ?? null)) {
            return [[
                'key' => 'other',
                'label' => Str::limit(trim((string) $legacyRow['package_custom']), 120, ''),
                'includes_shirt' => (bool) ($legacyRow['package_includes_shirt'] ?? false),
            ]];
        }
        if (in_array($p, ['medal', 'medal_shirt', 'medal_shirt_kit'], true)) {
            return [['key' => $p]];
        }

        return [['key' => 'medal']];
    }

    private function assertRunningDetailsValid(Request $request, \Illuminate\Validation\Validator $validator, string $categoryResolved): void
    {
        if ($categoryResolved !== 'running') {
            return;
        }

        $rd = $request->input('running_details');
        if (!is_array($rd)) {
            $validator->errors()->add('running_details', 'Running events require distance and package options.');

            return;
        }

        $distances = $this->sanitizeRunningDistancesArray($rd['distances'] ?? null, $rd);
        $packages = $this->sanitizeRunningPackagesArray($rd['packages'] ?? null, $rd);

        if (count($distances) === 0) {
            $validator->errors()->add('running_details.distances', 'Select at least one race distance to offer.');
        }

        if (count($packages) === 0) {
            $validator->errors()->add('running_details.packages', 'Select at least one registration package to offer.');
        }

        if ($this->runningPackagesListRequiresShirt($packages)) {
            $sizes = $rd['shirt_sizes'] ?? [];
            if (!is_array($sizes) || count(array_filter($sizes, static fn ($s) => is_string($s) && trim($s) !== '')) === 0) {
                $validator->errors()->add('running_details.shirt_sizes', 'Select shirt sizes offered when any package includes a shirt.');
            }
        }
    }

    private function assertGymDetailsValid(Request $request, \Illuminate\Validation\Validator $validator, string $categoryResolved): void
    {
        if ($categoryResolved !== 'gym') {
            return;
        }

        $gd = $request->input('gym_details');
        if (!is_array($gd)) {
            $validator->errors()->add('gym_details', 'Gym events require program and package options.');

            return;
        }

        $programs = $this->sanitizeGymProgramsArray($gd['programs'] ?? null, $gd);
        $packages = $this->sanitizeGymPackagesArray($gd['packages'] ?? null, $gd);

        if (count($programs) === 0) {
            $validator->errors()->add('gym_details.programs', 'Select at least one program focus to offer.');
        }

        if (count($packages) === 0) {
            $validator->errors()->add('gym_details.packages', 'Select at least one membership or pass package to offer.');
        }

        if ($this->gymPackagesListRequiresShirt($packages)) {
            $sizes = $gd['shirt_sizes'] ?? [];
            if (!is_array($sizes) || count(array_filter($sizes, static fn ($s) => is_string($s) && trim($s) !== '')) === 0) {
                $validator->errors()->add('gym_details.shirt_sizes', 'Select shirt sizes offered when any package includes apparel.');
            }
        }
    }

    /**
     * @return list<array{key: string, label?: string}>
     */
    private function sanitizeGymProgramsArray(mixed $list, array $legacyRow): array
    {
        $out = [];
        if (is_array($list)) {
            $allowed = ['strength', 'cardio', 'hiit', 'classes', 'hybrid', 'functional', 'other'];
            foreach (array_slice($list, 0, 8) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $k = strtolower(trim((string) ($row['key'] ?? '')));
                if (!in_array($k, $allowed, true)) {
                    continue;
                }
                if ($k === 'other') {
                    $label = Str::limit(trim((string) ($row['label'] ?? '')), 64, '');
                    if ($label === '') {
                        continue;
                    }
                    $out[] = ['key' => 'other', 'label' => $label];
                } else {
                    $out[] = ['key' => $k];
                }
            }
        }

        if (count($out) > 0) {
            return $this->uniqueGymPrograms($out);
        }

        $p = strtolower(trim((string) ($legacyRow['program'] ?? '')));
        if ($p === 'other' && filled($legacyRow['program_custom'] ?? null)) {
            return [['key' => 'other', 'label' => Str::limit(trim((string) $legacyRow['program_custom']), 64, '')]];
        }
        if (in_array($p, ['strength', 'cardio', 'hiit', 'classes', 'hybrid', 'functional'], true)) {
            return [['key' => $p]];
        }

        return [['key' => 'strength']];
    }

    /**
     * @return list<array{key: string, label?: string, includes_shirt?: bool}>
     */
    private function sanitizeGymPackagesArray(mixed $list, array $legacyRow): array
    {
        $out = [];
        if (is_array($list)) {
            $allowed = ['day_pass', 'monthly_access', 'classes_bundle', 'premium_apparel', 'full_kit', 'other'];
            foreach (array_slice($list, 0, 8) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $k = strtolower(trim((string) ($row['key'] ?? '')));
                if (!in_array($k, $allowed, true)) {
                    continue;
                }
                if ($k === 'other') {
                    $label = Str::limit(trim((string) ($row['label'] ?? '')), 120, '');
                    if ($label === '') {
                        continue;
                    }
                    $out[] = [
                        'key' => 'other',
                        'label' => $label,
                        'includes_shirt' => (bool) ($row['includes_shirt'] ?? false),
                    ];
                } else {
                    $out[] = ['key' => $k];
                }
            }
        }

        if (count($out) > 0) {
            return $this->uniqueGymPackages($out);
        }

        $p = strtolower(trim((string) ($legacyRow['package'] ?? '')));
        if ($p === 'other' && filled($legacyRow['package_custom'] ?? null)) {
            return [[
                'key' => 'other',
                'label' => Str::limit(trim((string) $legacyRow['package_custom']), 120, ''),
                'includes_shirt' => (bool) ($legacyRow['package_includes_shirt'] ?? false),
            ]];
        }
        if (in_array($p, ['day_pass', 'monthly_access', 'classes_bundle', 'premium_apparel', 'full_kit'], true)) {
            return [['key' => $p]];
        }

        return [['key' => 'day_pass']];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sanitizeGymDetailsFromRequest(Request $request, string $category): ?array
    {
        if ($category !== 'gym') {
            return null;
        }

        $raw = $request->input('gym_details', []);
        if (!is_array($raw)) {
            $raw = [];
        }

        $programs = $this->sanitizeGymProgramsArray($raw['programs'] ?? null, $raw);
        $packages = $this->sanitizeGymPackagesArray($raw['packages'] ?? null, $raw);

        $allowedSizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'];
        $sizesIn = $raw['shirt_sizes'] ?? [];
        $shirtSizes = [];
        if (is_array($sizesIn)) {
            foreach ($sizesIn as $sz) {
                $u = strtoupper(trim((string) $sz));
                if (in_array($u, $allowedSizes, true)) {
                    $shirtSizes[] = $u;
                }
            }
            $shirtSizes = array_values(array_unique($shirtSizes));
        }

        if (!$this->gymPackagesListRequiresShirt($packages)) {
            $shirtSizes = [];
        }

        return [
            'programs' => $programs,
            'packages' => $packages,
            'shirt_sizes' => $shirtSizes,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sanitizeRunningDetailsFromRequest(Request $request, string $category): ?array
    {
        if ($category !== 'running') {
            return null;
        }

        $raw = $request->input('running_details', []);
        if (!is_array($raw)) {
            $raw = [];
        }

        $distances = $this->sanitizeRunningDistancesArray($raw['distances'] ?? null, $raw);
        $packages = $this->sanitizeRunningPackagesArray($raw['packages'] ?? null, $raw);

        $allowedSizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'];
        $sizesIn = $raw['shirt_sizes'] ?? [];
        $shirtSizes = [];
        if (is_array($sizesIn)) {
            foreach ($sizesIn as $sz) {
                $u = strtoupper(trim((string) $sz));
                if (in_array($u, $allowedSizes, true)) {
                    $shirtSizes[] = $u;
                }
            }
            $shirtSizes = array_values(array_unique($shirtSizes));
        }

        if (!$this->runningPackagesListRequiresShirt($packages)) {
            $shirtSizes = [];
        }

        return [
            'distances' => $distances,
            'packages' => $packages,
            'shirt_sizes' => $shirtSizes,
        ];
    }

    public function posts(Request $request)
    {
        if (!Schema::hasTable('admin_posts')) {
            return response()->json(['success' => true, 'data' => ['data' => [], 'total' => 0]]);
        }
        $query = AdminPost::query()->with('admin:id,name,email')->orderByDesc('created_at');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $items = $query->paginate((int) $request->input('per_page', 15));
        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function createPost(Request $request)
    {
        if (!Schema::hasTable('admin_posts')) {
            return response()->json(['success' => false, 'message' => 'CMS tables are not migrated yet.'], 409);
        }
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:180',
            'body' => 'required|string|max:10000',
            'image_url' => 'nullable|string|max:2048',
            'status' => 'nullable|in:draft,scheduled,published,archived',
            'publish_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:publish_at',
        ]);
        $validator->after(function ($validation) use ($request) {
            if ($request->input('location_type') === 'onsite' && !filled($request->input('venue'))) {
                $validation->errors()->add('venue', 'Venue is required for onsite events.');
            }
            if ($request->input('fee_type') === 'paid' && (float) $request->input('fee', 0) <= 0) {
                $validation->errors()->add('fee', 'Fee must be greater than 0 for paid events.');
            }
        });
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Validation failed'], 422);
        }

        $post = AdminPost::create([
            'admin_id' => $request->user()->id,
            'title' => trim((string) $request->input('title')),
            'body' => trim((string) $request->input('body')),
            'image_url' => $request->input('image_url'),
            'status' => $request->input('status', 'draft'),
            'publish_at' => $request->input('publish_at'),
            'expires_at' => $request->input('expires_at'),
        ]);

        $this->logAdminActivity($request, 'admin_post_created', 'admin_post', $post->id, ['title' => $post->title]);
        return response()->json(['success' => true, 'message' => 'Post created successfully', 'data' => ['post' => $post]], 201);
    }

    public function updatePost(Request $request, string $id)
    {
        if (!Schema::hasTable('admin_posts')) {
            return response()->json(['success' => false, 'message' => 'CMS tables are not migrated yet.'], 409);
        }
        $post = AdminPost::find($id);
        if (!$post) {
            return response()->json(['success' => false, 'message' => 'Post not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:180',
            'body' => 'sometimes|required|string|max:10000',
            'image_url' => 'nullable|string|max:2048',
            'status' => 'sometimes|in:draft,scheduled,published,archived',
            'publish_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:publish_at',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Validation failed'], 422);
        }

        $post->fill($request->only(['title', 'body', 'image_url', 'status', 'publish_at', 'expires_at']));
        $post->save();

        $this->logAdminActivity($request, 'admin_post_updated', 'admin_post', $post->id, ['status' => $post->status]);
        return response()->json(['success' => true, 'message' => 'Post updated successfully', 'data' => ['post' => $post]]);
    }

    public function deletePost(Request $request, string $id)
    {
        if (!Schema::hasTable('admin_posts')) {
            return response()->json(['success' => false, 'message' => 'CMS tables are not migrated yet.'], 409);
        }
        $post = AdminPost::find($id);
        if (!$post) {
            return response()->json(['success' => false, 'message' => 'Post not found.'], 404);
        }
        $post->delete();
        $this->logAdminActivity($request, 'admin_post_deleted', 'admin_post', $id);
        return response()->json(['success' => true, 'message' => 'Post deleted successfully']);
    }

    public function announcements(Request $request)
    {
        if (!Schema::hasTable('admin_announcements')) {
            return response()->json(['success' => true, 'data' => ['data' => [], 'total' => 0]]);
        }
        $query = AdminAnnouncement::query()->with('admin:id,name,email')->orderByDesc('created_at');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $items = $query->paginate((int) $request->input('per_page', 15));
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function createAnnouncement(Request $request)
    {
        if (!Schema::hasTable('admin_announcements')) {
            return response()->json(['success' => false, 'message' => 'CMS tables are not migrated yet.'], 409);
        }
        $validator = Validator::make($request->all(), [
            'headline' => 'required|string|max:180',
            'body' => 'required|string|max:10000',
            'priority' => 'nullable|in:low,normal,high',
            'status' => 'nullable|in:draft,scheduled,published,archived',
            'publish_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:publish_at',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Validation failed'], 422);
        }

        $announcement = AdminAnnouncement::create([
            'admin_id' => $request->user()->id,
            'headline' => trim((string) $request->input('headline')),
            'body' => trim((string) $request->input('body')),
            'priority' => $request->input('priority', 'normal'),
            'status' => $request->input('status', 'draft'),
            'publish_at' => $request->input('publish_at'),
            'expires_at' => $request->input('expires_at'),
        ]);

        $this->logAdminActivity($request, 'admin_announcement_created', 'admin_announcement', $announcement->id, ['headline' => $announcement->headline]);
        return response()->json(['success' => true, 'message' => 'Announcement created successfully', 'data' => ['announcement' => $announcement]], 201);
    }

    public function updateAnnouncement(Request $request, string $id)
    {
        if (!Schema::hasTable('admin_announcements')) {
            return response()->json(['success' => false, 'message' => 'CMS tables are not migrated yet.'], 409);
        }
        $announcement = AdminAnnouncement::find($id);
        if (!$announcement) {
            return response()->json(['success' => false, 'message' => 'Announcement not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'headline' => 'sometimes|required|string|max:180',
            'body' => 'sometimes|required|string|max:10000',
            'priority' => 'sometimes|in:low,normal,high',
            'status' => 'sometimes|in:draft,scheduled,published,archived',
            'publish_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:publish_at',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Validation failed'], 422);
        }

        $announcement->fill($request->only(['headline', 'body', 'priority', 'status', 'publish_at', 'expires_at']));
        $announcement->save();
        $this->logAdminActivity($request, 'admin_announcement_updated', 'admin_announcement', $announcement->id, ['status' => $announcement->status]);

        return response()->json(['success' => true, 'message' => 'Announcement updated successfully', 'data' => ['announcement' => $announcement]]);
    }

    public function deleteAnnouncement(Request $request, string $id)
    {
        if (!Schema::hasTable('admin_announcements')) {
            return response()->json(['success' => false, 'message' => 'CMS tables are not migrated yet.'], 409);
        }
        $announcement = AdminAnnouncement::find($id);
        if (!$announcement) {
            return response()->json(['success' => false, 'message' => 'Announcement not found.'], 404);
        }
        $announcement->delete();
        $this->logAdminActivity($request, 'admin_announcement_deleted', 'admin_announcement', $id);
        return response()->json(['success' => true, 'message' => 'Announcement deleted successfully']);
    }

    public function events(Request $request)
    {
        if (!Schema::hasTable('admin_events')) {
            return response()->json(['success' => true, 'data' => ['data' => [], 'total' => 0]]);
        }

        $query = AdminEvent::query()->with('admin:id,name,email')->orderByDesc('created_at');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $items = $query->paginate((int) $request->input('per_page', 15));
        $items->getCollection()->transform(fn (AdminEvent $event) => $this->hydrateAdminEventMediaUrls($event));

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function createEvent(Request $request)
    {
        if (!Schema::hasTable('admin_events')) {
            return response()->json(['success' => false, 'message' => 'CMS tables are not migrated yet.'], 409);
        }

        $categoryResolved = $request->input('category') ?: 'other';

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:180',
            'description' => 'required|string|max:10000',
            'image_url' => 'required|string|max:2048',
            'location' => 'required|string|max:180',
            'category' => 'required|in:running,gym,biking,hybrid,endurance,strength,wellness,community,other',
            'location_type' => 'required|in:online,global,onsite',
            'venue' => 'required_if:location_type,onsite|nullable|string|max:180',
            'registration_starts_at' => 'required|date',
            'registration_deadline' => 'required|date',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after_or_equal:starts_at',
            'fee_type' => 'required|in:free,paid',
            'fee' => 'nullable|numeric|min:0|max:999999.99',
            'status' => 'nullable|in:draft,published',
            'publish_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:publish_at',
            'badges' => 'nullable|array|max:12',
            'badges.*.title' => 'nullable|string|max:120',
            'badges.*.image_url' => 'nullable|string|max:2048',
            'running_details' => 'nullable|array',
            'gym_details' => 'nullable|array',
            'how_it_works' => 'nullable|array|max:20',
            'how_it_works.*' => 'nullable|string|max:500',
            'participant_rules' => 'nullable|array|max:20',
            'participant_rules.*' => 'nullable|string|max:500',
        ]);
        $validator->after(function (\Illuminate\Validation\Validator $v) use ($request, $categoryResolved) {
            $this->assertRunningDetailsValid($request, $v, $categoryResolved);
            $this->assertGymDetailsValid($request, $v, $categoryResolved);
            $this->assertParticipationTextsValid($request, $v, null);
            $this->assertRegistrationWindowOrdered($request, $v, null);
            $this->assertEventBadgesLabelsPresent($request, $v);
            $this->assertPaidEventFeeValid($request, $v);
        });
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Validation failed'], 422);
        }

        $badges = $this->sanitizeEventBadgesInput($request);
        $runningDetails = $this->sanitizeRunningDetailsFromRequest($request, $categoryResolved);
        $gymDetails = $this->sanitizeGymDetailsFromRequest($request, $categoryResolved);
        $participation = $this->finalizedParticipationTexts($request, null);

        $event = AdminEvent::create([
            'admin_id' => $request->user()->id,
            'title' => trim((string) $request->input('title')),
            'description' => trim((string) $request->input('description')),
            'image_url' => $request->input('image_url'),
            'badges' => $badges,
            'how_it_works' => $participation['how_it_works'],
            'participant_rules' => $participation['participant_rules'],
            'running_details' => $runningDetails,
            'gym_details' => $gymDetails,
            'location' => $request->input('location'),
            'category' => $request->input('category'),
            'location_type' => $request->input('location_type', 'online'),
            'venue' => $request->input('venue'),
            'registration_starts_at' => $request->input('registration_starts_at'),
            'registration_deadline' => $request->input('registration_deadline'),
            'starts_at' => $request->input('starts_at'),
            'ends_at' => $request->input('ends_at'),
            'fee_type' => $request->input('fee_type', 'free'),
            'fee' => $request->input('fee', 0),
            'status' => $request->input('status', 'draft'),
            'publish_at' => $request->input('publish_at'),
            'expires_at' => $request->input('expires_at'),
        ]);

        $this->logAdminActivity($request, 'admin_event_created', 'admin_event', $event->id, ['title' => $event->title]);

        return response()->json([
            'success' => true,
            'message' => 'Event created successfully',
            'data' => ['event' => $this->hydrateAdminEventMediaUrls($event)],
        ], 201);
    }

    public function updateEvent(Request $request, string $id)
    {
        if (!Schema::hasTable('admin_events')) {
            return response()->json(['success' => false, 'message' => 'CMS tables are not migrated yet.'], 409);
        }

        $event = AdminEvent::find($id);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        $categoryResolved = $request->input('category', $event->category ?: 'other');

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:180',
            'description' => 'required|string|max:10000',
            'image_url' => 'required|string|max:2048',
            'location' => 'required|string|max:180',
            'category' => 'required|in:running,gym,biking,hybrid,endurance,strength,wellness,community,other',
            'location_type' => 'required|in:online,global,onsite',
            'venue' => 'required_if:location_type,onsite|nullable|string|max:180',
            'registration_starts_at' => 'required|date',
            'registration_deadline' => 'required|date',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after_or_equal:starts_at',
            'fee_type' => 'required|in:free,paid',
            'fee' => 'nullable|numeric|min:0|max:999999.99',
            'status' => 'sometimes|in:draft,published',
            'publish_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:publish_at',
            'badges' => 'nullable|array|max:12',
            'badges.*.title' => 'nullable|string|max:120',
            'badges.*.image_url' => 'nullable|string|max:2048',
            'running_details' => 'nullable|array',
            'gym_details' => 'nullable|array',
            'how_it_works' => 'nullable|array|max:20',
            'how_it_works.*' => 'nullable|string|max:500',
            'participant_rules' => 'nullable|array|max:20',
            'participant_rules.*' => 'nullable|string|max:500',
        ]);
        $validator->after(function (\Illuminate\Validation\Validator $v) use ($request, $categoryResolved, $event) {
            $this->assertRunningDetailsValid($request, $v, $categoryResolved);
            $this->assertGymDetailsValid($request, $v, $categoryResolved);
            $this->assertParticipationTextsValid($request, $v, $event);
            $this->assertRegistrationWindowOrdered($request, $v, $event);
            $this->assertEventBadgesLabelsPresent($request, $v);
            $this->assertPaidEventFeeValid($request, $v);
        });
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Validation failed'], 422);
        }

        $payload = $request->only([
            'title',
            'description',
            'image_url',
            'location',
            'category',
            'location_type',
            'venue',
            'registration_starts_at',
            'registration_deadline',
            'starts_at',
            'ends_at',
            'fee_type',
            'fee',
            'status',
            'publish_at',
            'expires_at',
        ]);
        if ($request->has('badges')) {
            $payload['badges'] = $this->sanitizeEventBadgesInput($request);
        }
        $payload['running_details'] = $this->sanitizeRunningDetailsFromRequest($request, $categoryResolved);
        $payload['gym_details'] = $this->sanitizeGymDetailsFromRequest($request, $categoryResolved);
        $participation = $this->finalizedParticipationTexts($request, $event);
        $payload['how_it_works'] = $participation['how_it_works'];
        $payload['participant_rules'] = $participation['participant_rules'];
        $event->fill($payload);
        $event->save();

        $this->logAdminActivity($request, 'admin_event_updated', 'admin_event', $event->id, ['status' => $event->status]);

        return response()->json([
            'success' => true,
            'message' => 'Event updated successfully',
            'data' => ['event' => $this->hydrateAdminEventMediaUrls($event)],
        ]);
    }

    public function deleteEvent(Request $request, string $id)
    {
        if (!Schema::hasTable('admin_events')) {
            return response()->json(['success' => false, 'message' => 'CMS tables are not migrated yet.'], 409);
        }
        $event = AdminEvent::find($id);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }
        $event->delete();
        $this->logAdminActivity($request, 'admin_event_deleted', 'admin_event', $id);
        return response()->json(['success' => true, 'message' => 'Event deleted successfully']);
    }

    public function uploadEventImage(Request $request)
    {
        if (!Schema::hasTable('admin_events')) {
            return response()->json(['success' => false, 'message' => 'CMS tables are not migrated yet.'], 409);
        }

        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Validation failed'], 422);
        }

        $path = PublicUploadStorage::storePublicReference($request->file('image'), 'admin-events');

        return response()->json([
            'success' => true,
            'message' => 'Event image uploaded successfully.',
            'data' => ['image_url' => PublicUploadStorage::resolveForClient($path)],
        ], 201);
    }

    public function uploadEventBadgeImage(Request $request)
    {
        if (!Schema::hasTable('admin_events')) {
            return response()->json(['success' => false, 'message' => 'CMS tables are not migrated yet.'], 409);
        }

        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpg,jpeg,png,webp,gif|max:4096',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Validation failed'], 422);
        }

        $path = PublicUploadStorage::storePublicReference($request->file('image'), 'admin-event-badges');

        return response()->json([
            'success' => true,
            'message' => 'Badge image uploaded successfully.',
            'data' => ['image_url' => PublicUploadStorage::resolveForClient($path)],
        ], 201);
    }
}

