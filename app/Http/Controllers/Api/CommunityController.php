<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CommunityController extends Controller
{
    private function getViewerMembership(Community $community, string $viewerId): ?CommunityMember
    {
        return CommunityMember::where('community_id', $community->id)
            ->where('client_id', $viewerId)
            ->first();
    }

    private function canAccessCommunity(Community $community, Client $viewer): bool
    {
        if ($community->visibility === 'public' || $community->owner_client_id === $viewer->id) {
            return true;
        }

        $membership = $this->getViewerMembership($community, $viewer->id);
        return $membership && in_array($membership->status, ['active', 'requested'], true);
    }

    private function canPostInCommunity(Community $community, Client $viewer): bool
    {
        if ($community->owner_client_id === $viewer->id) {
            return true;
        }

        $membership = $this->getViewerMembership($community, $viewer->id);
        return $membership && $membership->status === 'active';
    }

    private function canModerateCommunity(Community $community, Client $viewer): bool
    {
        if ($community->owner_client_id === $viewer->id) {
            return true;
        }

        $membership = $this->getViewerMembership($community, $viewer->id);
        return $membership
            && $membership->status === 'active'
            && in_array($membership->role, ['owner', 'admin'], true);
    }

    private function mapClientSummary(Client $client): array
    {
        $profile = $client->profile;
        $displayName = $profile?->display_name
            ?: trim(($profile?->first_name ?? '') . ' ' . ($profile?->last_name ?? ''));

        if (!$displayName) {
            $displayName = explode('@', $client->email)[0] ?? 'User';
        }

        return [
            'id' => $client->id,
            'display_name' => $displayName,
            'profile_picture_url' => $profile?->profile_picture_url,
            'city' => $profile?->city,
            'province' => $profile?->province,
        ];
    }

    private function mapCommunity(Community $community, Client $viewer): array
    {
        $viewerMembership = $community->members->firstWhere('client_id', $viewer->id);

        return [
            'id' => $community->id,
            'name' => $community->name,
            'slug' => $community->slug,
            'description' => $community->description,
            'primary_niche' => $community->primary_niche,
            'city' => $community->city,
            'province' => $community->province,
            'country' => $community->country,
            'visibility' => $community->visibility,
            'cover_image_url' => $community->cover_image_url,
            'is_active' => (bool) $community->is_active,
            'owner' => $community->owner ? $this->mapClientSummary($community->owner) : null,
            'members_count' => (int) $community->members_count,
            'viewer_membership' => $viewerMembership ? [
                'role' => $viewerMembership->role,
                'status' => $viewerMembership->status,
                'joined_at' => $viewerMembership->joined_at,
            ] : null,
            'created_at' => $community->created_at,
            'updated_at' => $community->updated_at,
        ];
    }

    private function mapCommunityPost(CommunityPost $post, Client $viewer): array
    {
        return [
            'id' => $post->id,
            'community_id' => $post->community_id,
            'body' => $post->body,
            'media_urls' => $post->media_urls ?? [],
            'status' => $post->status,
            'author' => $post->client ? $this->mapClientSummary($post->client) : null,
            'is_mine' => $post->client_id === $viewer->id,
            'created_at' => $post->created_at,
            'updated_at' => $post->updated_at,
        ];
    }

    private function mapCommunityParticipant(CommunityMember $member): ?array
    {
        $client = $member->client;
        if (!$client) {
            return null;
        }

        $profile = $client->profile;
        $displayName = $profile?->display_name
            ?: trim(($profile?->first_name ?? '') . ' ' . ($profile?->last_name ?? ''));

        if (!$displayName) {
            $displayName = explode('@', $client->email)[0] ?? 'User';
        }

        return [
            'id' => $client->id,
            'display_name' => $displayName,
            'profile_picture_url' => $profile?->profile_picture_url,
            'city' => $profile?->city,
            'province' => $profile?->province,
            'joined_at' => $member->joined_at,
            'role' => $member->role,
        ];
    }

    private function createUniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'community';
        $slug = $base;
        $suffix = 2;

        while (Community::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    public function index(Request $request)
    {
        $viewer = $request->user();
        $validator = Validator::make($request->all(), [
            'niche' => 'nullable|in:running,gym,biking,hybrid',
            'visibility' => 'nullable|in:public,private',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $limit = (int) $request->input('limit', 20);
        $memberCommunityIds = CommunityMember::where('client_id', $viewer->id)->pluck('community_id');

        $query = Community::query()
            ->with(['owner.profile', 'members' => fn ($q) => $q->where('client_id', $viewer->id)])
            ->withCount(['members as members_count' => fn ($q) => $q->where('status', 'active')])
            ->where('is_active', true)
            ->where(function ($q) use ($viewer, $memberCommunityIds) {
                $q->where('visibility', 'public')
                    ->orWhere('owner_client_id', $viewer->id)
                    ->orWhereIn('id', $memberCommunityIds);
            });

        if ($request->filled('niche')) {
            $query->where('primary_niche', $request->input('niche'));
        }
        if ($request->filled('visibility')) {
            $query->where('visibility', $request->input('visibility'));
        }

        $communities = $query
            ->orderByDesc('members_count')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Community $community) => $this->mapCommunity($community, $viewer))
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'communities' => $communities,
                'total' => $communities->count(),
            ],
        ], 200);
    }

    public function show(Request $request, string $communityId)
    {
        $viewer = $request->user();
        $community = Community::query()
            ->with(['owner.profile', 'members' => fn ($q) => $q->where('client_id', $viewer->id)])
            ->withCount(['members as members_count' => fn ($q) => $q->where('status', 'active')])
            ->find($communityId);

        if (!$community) {
            return response()->json([
                'success' => false,
                'message' => 'Community not found.',
            ], 404);
        }

        $viewerMembership = $community->members->firstWhere('client_id', $viewer->id);
        $canAccess = $community->visibility === 'public'
            || $community->owner_client_id === $viewer->id
            || $viewerMembership;
        if (!$canAccess) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this community.',
            ], 403);
        }

        $participants = CommunityMember::query()
            ->where('community_id', $community->id)
            ->where('status', 'active')
            ->with('client.profile')
            ->orderByDesc('joined_at')
            ->get()
            ->map(fn (CommunityMember $member) => $this->mapCommunityParticipant($member))
            ->filter()
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'community' => $this->mapCommunity($community, $viewer),
                'participants' => $participants,
            ],
        ], 200);
    }

    public function posts(Request $request, string $communityId)
    {
        $viewer = $request->user();
        $validator = Validator::make($request->all(), [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $community = Community::find($communityId);
        if (!$community || !$community->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Community not found.',
            ], 404);
        }

        if (!$this->canAccessCommunity($community, $viewer)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this community.',
            ], 403);
        }

        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 20);

        $paginator = CommunityPost::query()
            ->where('community_id', $community->id)
            ->where('status', 'published')
            ->with('client.profile')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $posts = collect($paginator->items())
            ->map(fn (CommunityPost $post) => $this->mapCommunityPost($post, $viewer))
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'posts' => $posts,
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ], 200);
    }

    public function createPost(Request $request, string $communityId)
    {
        $viewer = $request->user();
        $validator = Validator::make($request->all(), [
            'body' => 'required|string|max:5000',
            'media_urls' => 'nullable|array|max:10',
            'media_urls.*' => 'string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $community = Community::find($communityId);
        if (!$community || !$community->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Community not found.',
            ], 404);
        }

        if (!$this->canPostInCommunity($community, $viewer)) {
            return response()->json([
                'success' => false,
                'message' => 'Only active community members can post.',
            ], 403);
        }

        $post = CommunityPost::create([
            'community_id' => $community->id,
            'client_id' => $viewer->id,
            'body' => trim((string) $request->input('body')),
            'media_urls' => $request->input('media_urls', []),
            'status' => 'published',
        ])->load('client.profile');

        return response()->json([
            'success' => true,
            'message' => 'Post published successfully.',
            'data' => [
                'post' => $this->mapCommunityPost($post, $viewer),
            ],
        ], 201);
    }

    public function deletePost(Request $request, string $communityId, string $postId)
    {
        $viewer = $request->user();
        $community = Community::find($communityId);
        if (!$community || !$community->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Community not found.',
            ], 404);
        }

        $post = CommunityPost::where('community_id', $community->id)->find($postId);
        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found.',
            ], 404);
        }

        $canDelete = $post->client_id === $viewer->id || $this->canModerateCommunity($community, $viewer);
        if (!$canDelete) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to delete this post.',
            ], 403);
        }

        $post->status = 'removed';
        $post->save();
        $post->delete();

        return response()->json([
            'success' => true,
            'message' => 'Post deleted successfully.',
        ], 200);
    }

    public function store(Request $request)
    {
        $viewer = $request->user();
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:2000',
            'primary_niche' => 'required|in:running,gym,biking,hybrid',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'visibility' => 'required|in:public,private',
            'cover_image_url' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $name = trim((string) $request->input('name'));
        $community = Community::create([
            'owner_client_id' => $viewer->id,
            'name' => $name,
            'slug' => $this->createUniqueSlug($name),
            'description' => $request->input('description'),
            'primary_niche' => $request->input('primary_niche'),
            'city' => $request->input('city'),
            'province' => $request->input('province'),
            'country' => $request->input('country'),
            'visibility' => $request->input('visibility'),
            'cover_image_url' => $request->input('cover_image_url'),
            'is_active' => true,
        ]);

        CommunityMember::create([
            'community_id' => $community->id,
            'client_id' => $viewer->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $community->load(['owner.profile', 'members' => fn ($q) => $q->where('client_id', $viewer->id)])
            ->loadCount(['members as members_count' => fn ($q) => $q->where('status', 'active')]);

        return response()->json([
            'success' => true,
            'message' => 'Community created successfully.',
            'data' => [
                'community' => $this->mapCommunity($community, $viewer),
            ],
        ], 201);
    }

    public function join(Request $request, string $communityId)
    {
        $viewer = $request->user();
        $community = Community::find($communityId);

        if (!$community || !$community->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Community not found.',
            ], 404);
        }

        $membership = CommunityMember::where('community_id', $community->id)
            ->where('client_id', $viewer->id)
            ->first();

        if ($membership && $membership->status === 'active') {
            return response()->json([
                'success' => true,
                'message' => 'You are already a member of this community.',
            ], 200);
        }

        $status = $community->visibility === 'private' ? 'requested' : 'active';
        CommunityMember::updateOrCreate(
            ['community_id' => $community->id, 'client_id' => $viewer->id],
            [
                'role' => $membership?->role === 'owner' ? 'owner' : 'member',
                'status' => $status,
                'joined_at' => $status === 'active' ? now() : $membership?->joined_at,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => $status === 'active'
                ? 'Joined community successfully.'
                : 'Join request submitted successfully.',
            'data' => [
                'status' => $status,
            ],
        ], 200);
    }

    public function leave(Request $request, string $communityId)
    {
        $viewer = $request->user();
        $community = Community::find($communityId);
        if (!$community) {
            return response()->json([
                'success' => false,
                'message' => 'Community not found.',
            ], 404);
        }

        if ($community->owner_client_id === $viewer->id) {
            return response()->json([
                'success' => false,
                'message' => 'Community owner cannot leave. Transfer ownership first.',
            ], 422);
        }

        CommunityMember::where('community_id', $community->id)
            ->where('client_id', $viewer->id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Left community successfully.',
        ], 200);
    }
}

