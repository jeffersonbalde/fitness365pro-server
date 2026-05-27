<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;

class AdminMembersController extends Controller
{
    public function index(Request $request)
    {
        $query = Client::query()
            ->with(['profile', 'goals:id,name,slug'])
            ->withCount([
                'followers',
                'following',
                'eventRegistrations',
                'communityMemberships',
                'workoutLogs',
            ]);

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('email', 'like', $like)
                    ->orWhereHas('profile', function ($profileQuery) use ($like) {
                        $profileQuery
                            ->where('display_name', 'like', $like)
                            ->orWhere('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('city', 'like', $like)
                            ->orWhere('province', 'like', $like)
                            ->orWhere('phone', 'like', $like);
                    });
            });
        }

        $onboarding = $request->input('onboarding');
        if ($onboarding === 'completed') {
            $query->whereHas('profile', fn ($profileQuery) => $profileQuery->where('onboarding_completed', true));
        } elseif ($onboarding === 'incomplete') {
            $query->where(function ($q) {
                $q->whereDoesntHave('profile')
                    ->orWhereHas('profile', fn ($profileQuery) => $profileQuery->where('onboarding_completed', false));
            });
        }

        $niche = trim((string) $request->input('niche', ''));
        if ($niche !== '') {
            $query->whereHas('profile', fn ($profileQuery) => $profileQuery->where('primary_niche', $niche));
        }

        $items = $query
            ->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 25));

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function show(string $id)
    {
        $member = Client::query()
            ->with([
                'profile',
                'goals:id,name,slug',
                'eventRegistrations' => function ($registrationQuery) {
                    $registrationQuery
                        ->with('event:id,title,status,starts_at,ends_at,registration_deadline,category,location')
                        ->orderByDesc('created_at');
                },
            ])
            ->withCount([
                'followers',
                'following',
                'eventRegistrations',
                'communityMemberships',
                'workoutLogs',
                'badges',
            ])
            ->find($id);

        if (!$member) {
            return response()->json(['success' => false, 'message' => 'Member not found.'], 404);
        }

        return response()->json(['success' => true, 'data' => ['member' => $member]]);
    }
}
