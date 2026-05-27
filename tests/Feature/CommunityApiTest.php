<?php

use App\Models\Client;
use App\Models\ClientProfile;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('creates a community and assigns owner membership', function () {
    $viewer = Client::factory()->create();
    ClientProfile::create(['client_id' => $viewer->id, 'display_name' => 'Owner']);
    Sanctum::actingAs($viewer);

    $response = $this->postJson('/api/v1/communities', [
        'name' => 'Pagadian Runners',
        'description' => 'For runners in the city.',
        'primary_niche' => 'running',
        'city' => 'Pagadian City',
        'province' => 'Zamboanga Peninsula',
        'country' => 'Philippines',
        'visibility' => 'public',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.community.name', 'Pagadian Runners')
        ->assertJsonPath('data.community.primary_niche', 'running')
        ->assertJsonPath('data.community.viewer_membership.role', 'owner');

    $communityId = $response->json('data.community.id');
    expect(Community::where('id', $communityId)->exists())->toBeTrue();
    expect(CommunityMember::where('community_id', $communityId)->where('client_id', $viewer->id)->exists())->toBeTrue();
});

it('lists communities and allows join and leave lifecycle for non-owner', function () {
    $owner = Client::factory()->create();
    ClientProfile::create(['client_id' => $owner->id, 'display_name' => 'Owner']);
    $viewer = Client::factory()->create();
    ClientProfile::create(['client_id' => $viewer->id, 'display_name' => 'Viewer']);
    Sanctum::actingAs($viewer);

    $community = Community::create([
        'owner_client_id' => $owner->id,
        'name' => 'Gym Crew',
        'slug' => 'gym-crew',
        'primary_niche' => 'gym',
        'visibility' => 'public',
        'is_active' => true,
    ]);
    CommunityMember::create([
        'community_id' => $community->id,
        'client_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $this->getJson('/api/v1/communities?niche=gym')
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->postJson("/api/v1/communities/{$community->id}/join")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'active');

    expect(CommunityMember::where('community_id', $community->id)->where('client_id', $viewer->id)->exists())->toBeTrue();

    $this->postJson("/api/v1/communities/{$community->id}/leave")
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(CommunityMember::where('community_id', $community->id)->where('client_id', $viewer->id)->exists())->toBeFalse();
});

it('prevents owner from leaving community', function () {
    $owner = Client::factory()->create();
    ClientProfile::create(['client_id' => $owner->id, 'display_name' => 'Owner']);
    Sanctum::actingAs($owner);

    $community = Community::create([
        'owner_client_id' => $owner->id,
        'name' => 'Hybrid Hub',
        'slug' => 'hybrid-hub',
        'primary_niche' => 'hybrid',
        'visibility' => 'public',
        'is_active' => true,
    ]);
    CommunityMember::create([
        'community_id' => $community->id,
        'client_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $this->postJson("/api/v1/communities/{$community->id}/leave")
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

it('allows active members to create and list community posts', function () {
    $owner = Client::factory()->create();
    ClientProfile::create(['client_id' => $owner->id, 'display_name' => 'Owner']);
    $member = Client::factory()->create();
    ClientProfile::create(['client_id' => $member->id, 'display_name' => 'Member']);
    Sanctum::actingAs($member);

    $community = Community::create([
        'owner_client_id' => $owner->id,
        'name' => 'Runners PH',
        'slug' => 'runners-ph',
        'primary_niche' => 'running',
        'visibility' => 'public',
        'is_active' => true,
    ]);
    CommunityMember::create([
        'community_id' => $community->id,
        'client_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'joined_at' => now(),
    ]);
    CommunityMember::create([
        'community_id' => $community->id,
        'client_id' => $member->id,
        'role' => 'member',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $create = $this->postJson("/api/v1/communities/{$community->id}/posts", [
        'body' => 'Morning run done!',
        'media_urls' => ['/storage/workout-photos/a.jpg'],
    ]);
    $create->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.post.body', 'Morning run done!')
        ->assertJsonPath('data.post.is_mine', true);

    $this->getJson("/api/v1/communities/{$community->id}/posts?page=1&per_page=10")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.pagination.page', 1)
        ->assertJsonPath('data.pagination.per_page', 10);
});

it('allows owner to moderate and delete another member post', function () {
    $owner = Client::factory()->create();
    ClientProfile::create(['client_id' => $owner->id, 'display_name' => 'Owner']);
    $member = Client::factory()->create();
    ClientProfile::create(['client_id' => $member->id, 'display_name' => 'Member']);

    $community = Community::create([
        'owner_client_id' => $owner->id,
        'name' => 'Gym PH',
        'slug' => 'gym-ph',
        'primary_niche' => 'gym',
        'visibility' => 'public',
        'is_active' => true,
    ]);
    CommunityMember::create([
        'community_id' => $community->id,
        'client_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'joined_at' => now(),
    ]);
    CommunityMember::create([
        'community_id' => $community->id,
        'client_id' => $member->id,
        'role' => 'member',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $post = CommunityPost::create([
        'community_id' => $community->id,
        'client_id' => $member->id,
        'body' => 'Heavy leg day!',
        'status' => 'published',
    ]);

    Sanctum::actingAs($owner);
    $this->deleteJson("/api/v1/communities/{$community->id}/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('success', true);

    $fresh = CommunityPost::withTrashed()->find($post->id);
    expect($fresh)->not->toBeNull();
    expect($fresh->status)->toBe('removed');
    expect($fresh->trashed())->toBeTrue();
});

it('sets requested membership status when joining a private community', function () {
    $owner = Client::factory()->create();
    ClientProfile::create(['client_id' => $owner->id, 'display_name' => 'Owner']);
    $viewer = Client::factory()->create();
    ClientProfile::create(['client_id' => $viewer->id, 'display_name' => 'Viewer']);

    $community = Community::create([
        'owner_client_id' => $owner->id,
        'name' => 'Private Runners',
        'slug' => 'private-runners',
        'primary_niche' => 'running',
        'visibility' => 'private',
        'is_active' => true,
    ]);
    CommunityMember::create([
        'community_id' => $community->id,
        'client_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    Sanctum::actingAs($viewer);
    $this->postJson("/api/v1/communities/{$community->id}/join")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'requested');

    $membership = CommunityMember::where('community_id', $community->id)
        ->where('client_id', $viewer->id)
        ->first();

    expect($membership)->not->toBeNull();
    expect($membership->status)->toBe('requested');
});

it('blocks non-members from viewing private community posts', function () {
    $owner = Client::factory()->create();
    ClientProfile::create(['client_id' => $owner->id, 'display_name' => 'Owner']);
    $outsider = Client::factory()->create();
    ClientProfile::create(['client_id' => $outsider->id, 'display_name' => 'Outsider']);

    $community = Community::create([
        'owner_client_id' => $owner->id,
        'name' => 'Private Lifters',
        'slug' => 'private-lifters',
        'primary_niche' => 'gym',
        'visibility' => 'private',
        'is_active' => true,
    ]);
    CommunityMember::create([
        'community_id' => $community->id,
        'client_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    Sanctum::actingAs($outsider);
    $this->getJson("/api/v1/communities/{$community->id}/posts")
        ->assertStatus(403)
        ->assertJsonPath('success', false);
});

it('blocks requested members from creating posts until active', function () {
    $owner = Client::factory()->create();
    ClientProfile::create(['client_id' => $owner->id, 'display_name' => 'Owner']);
    $requester = Client::factory()->create();
    ClientProfile::create(['client_id' => $requester->id, 'display_name' => 'Requester']);

    $community = Community::create([
        'owner_client_id' => $owner->id,
        'name' => 'Private Hybrid Hub',
        'slug' => 'private-hybrid-hub',
        'primary_niche' => 'hybrid',
        'visibility' => 'private',
        'is_active' => true,
    ]);
    CommunityMember::create([
        'community_id' => $community->id,
        'client_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'joined_at' => now(),
    ]);
    CommunityMember::create([
        'community_id' => $community->id,
        'client_id' => $requester->id,
        'role' => 'member',
        'status' => 'requested',
        'joined_at' => null,
    ]);

    Sanctum::actingAs($requester);
    $this->postJson("/api/v1/communities/{$community->id}/posts", [
        'body' => 'Let me in coach!',
    ])->assertStatus(403)
      ->assertJsonPath('success', false);
});

it('prevents regular members from deleting another member post', function () {
    $owner = Client::factory()->create();
    ClientProfile::create(['client_id' => $owner->id, 'display_name' => 'Owner']);
    $memberOne = Client::factory()->create();
    ClientProfile::create(['client_id' => $memberOne->id, 'display_name' => 'Member One']);
    $memberTwo = Client::factory()->create();
    ClientProfile::create(['client_id' => $memberTwo->id, 'display_name' => 'Member Two']);

    $community = Community::create([
        'owner_client_id' => $owner->id,
        'name' => 'Open Runners',
        'slug' => 'open-runners',
        'primary_niche' => 'running',
        'visibility' => 'public',
        'is_active' => true,
    ]);
    CommunityMember::create([
        'community_id' => $community->id,
        'client_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'joined_at' => now(),
    ]);
    CommunityMember::create([
        'community_id' => $community->id,
        'client_id' => $memberOne->id,
        'role' => 'member',
        'status' => 'active',
        'joined_at' => now(),
    ]);
    CommunityMember::create([
        'community_id' => $community->id,
        'client_id' => $memberTwo->id,
        'role' => 'member',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $post = CommunityPost::create([
        'community_id' => $community->id,
        'client_id' => $memberTwo->id,
        'body' => 'Intervals done.',
        'status' => 'published',
    ]);

    Sanctum::actingAs($memberOne);
    $this->deleteJson("/api/v1/communities/{$community->id}/posts/{$post->id}")
        ->assertStatus(403)
        ->assertJsonPath('success', false);
});

