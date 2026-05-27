<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\LogsAdminActivity;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientProfile;
use App\Models\ProgramCatalog;
use App\Models\WorkoutLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminModuleController extends Controller
{
    use LogsAdminActivity;

    public function clientRecords(Request $request)
    {
        $items = Client::query()
            ->with('profile')
            ->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 15));

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function programEnrollments(Request $request)
    {
        $items = ClientProfile::query()
            ->with('client:id,email,created_at')
            ->whereNotNull('fitness_plan')
            ->orderByDesc('updated_at')
            ->paginate((int) $request->input('per_page', 15));

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function progressUpdates(Request $request)
    {
        $items = WorkoutLog::query()
            ->with('client.profile')
            ->orderByDesc('workout_date')
            ->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 20));

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function dueNotifications(Request $request)
    {
        $profiles = ClientProfile::query()
            ->with('client:id,email')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (ClientProfile $profile) {
                $missing = [];
                if (!$profile->city) $missing[] = 'City';
                if (!$profile->province) $missing[] = 'Province';
                if (!$profile->fitness_plan) $missing[] = 'Fitness plan';
                return [
                    'client_id' => $profile->client_id,
                    'email' => $profile->client?->email,
                    'display_name' => $profile->display_name,
                    'missing_items' => $missing,
                    'is_complete' => count($missing) === 0,
                    'updated_at' => $profile->updated_at,
                ];
            })
            ->filter(fn ($row) => !$row['is_complete'])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'results' => $profiles,
                'total' => $profiles->count(),
            ],
        ]);
    }

    public function reportsSummary()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'workouts_last_7_days' => WorkoutLog::whereDate('created_at', '>=', now()->subDays(7))->count(),
                'new_users_last_30_days' => Client::whereDate('created_at', '>=', now()->subDays(30))->count(),
                'active_programs' => ProgramCatalog::where('is_active', true)->count(),
            ],
        ]);
    }

    public function programCatalog(Request $request)
    {
        $items = ProgramCatalog::query()->with('admin:id,name,email')->orderByDesc('created_at')->paginate((int) $request->input('per_page', 15));
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function createProgramCatalog(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:4000',
            'duration_days' => 'required|integer|min:1|max:365',
            'difficulty' => 'required|in:beginner,intermediate,advanced',
            'is_active' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Validation failed'], 422);
        }

        $item = ProgramCatalog::create([
            'admin_id' => $request->user()->id,
            ...$request->only(['name', 'description', 'duration_days', 'difficulty']),
            'is_active' => $request->boolean('is_active', true),
        ]);
        $this->logAdminActivity($request, 'program_catalog_created', 'program_catalog', $item->id, ['name' => $item->name]);
        return response()->json(['success' => true, 'message' => 'Program catalog item created.', 'data' => ['item' => $item]], 201);
    }

    public function updateProgramCatalog(Request $request, string $id)
    {
        $item = ProgramCatalog::find($id);
        if (!$item) return response()->json(['success' => false, 'message' => 'Program catalog item not found.'], 404);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:150',
            'description' => 'nullable|string|max:4000',
            'duration_days' => 'sometimes|required|integer|min:1|max:365',
            'difficulty' => 'sometimes|required|in:beginner,intermediate,advanced',
            'is_active' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Validation failed'], 422);
        }

        $item->fill($request->only(['name', 'description', 'duration_days', 'difficulty']));
        if ($request->has('is_active')) $item->is_active = $request->boolean('is_active');
        $item->save();
        $this->logAdminActivity($request, 'program_catalog_updated', 'program_catalog', $item->id, ['name' => $item->name]);
        return response()->json(['success' => true, 'message' => 'Program catalog item updated.', 'data' => ['item' => $item]]);
    }

    public function deleteProgramCatalog(Request $request, string $id)
    {
        $item = ProgramCatalog::find($id);
        if (!$item) return response()->json(['success' => false, 'message' => 'Program catalog item not found.'], 404);
        $item->delete();
        $this->logAdminActivity($request, 'program_catalog_deleted', 'program_catalog', $id);
        return response()->json(['success' => true, 'message' => 'Program catalog item deleted.']);
    }

    public function users(Request $request)
    {
        $items = Admin::query()->orderByDesc('created_at')->paginate((int) $request->input('per_page', 15));
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function createUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190|unique:admins,email',
            'password' => 'required|string|min:8',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Validation failed'], 422);
        }

        $admin = Admin::create([
            'name' => $request->input('name'),
            'email' => strtolower((string) $request->input('email')),
            'password' => Hash::make((string) $request->input('password')),
        ]);
        $this->logAdminActivity($request, 'admin_user_created', 'admin', $admin->id, ['email' => $admin->email]);
        return response()->json(['success' => true, 'message' => 'Admin user created.', 'data' => ['admin' => $admin]], 201);
    }

    public function updateUser(Request $request, string $id)
    {
        $admin = Admin::find($id);
        if (!$admin) return response()->json(['success' => false, 'message' => 'Admin user not found.'], 404);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:120',
            'email' => 'sometimes|required|email|max:190|unique:admins,email,' . $admin->id,
            'password' => 'nullable|string|min:8',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Validation failed'], 422);
        }

        if ($request->has('name')) $admin->name = (string) $request->input('name');
        if ($request->has('email')) $admin->email = strtolower((string) $request->input('email'));
        if ($request->filled('password')) $admin->password = Hash::make((string) $request->input('password'));
        $admin->save();

        $this->logAdminActivity($request, 'admin_user_updated', 'admin', $admin->id, ['email' => $admin->email]);
        return response()->json(['success' => true, 'message' => 'Admin user updated.', 'data' => ['admin' => $admin]]);
    }

    public function deleteUser(Request $request, string $id)
    {
        $admin = Admin::find($id);
        if (!$admin) return response()->json(['success' => false, 'message' => 'Admin user not found.'], 404);
        if ($request->user()->id === $admin->id) {
            return response()->json(['success' => false, 'message' => 'You cannot delete your own admin account.'], 422);
        }

        $admin->delete();
        $this->logAdminActivity($request, 'admin_user_deleted', 'admin', $id, ['email' => $admin->email]);
        return response()->json(['success' => true, 'message' => 'Admin user deleted.']);
    }

    public function activityLogs(Request $request)
    {
        $items = ActivityLog::query()
            ->with('admin:id,name,email')
            ->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 20));

        return response()->json(['success' => true, 'data' => $items]);
    }
}

