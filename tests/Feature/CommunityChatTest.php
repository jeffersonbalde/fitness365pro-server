<?php

use App\Models\Client;
use App\Models\ClientProfile;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('allows active community members to read and send in general channel', function () {
    $owner = Client::factory()->create();
    $member = Client::factory()->create();
    ClientProfile::create(['client_id' => $owner->id, 'display_name' => 'Owner']);
    ClientProfile::create(['client_id' => $member->id, 'display_name' => 'Member']);

    $community = Community::create([
        'owner_client_id' => $owner->id,
        'name' => 'General Chat Club',
        'slug' => 'general-chat-club',
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

    Sanctum::actingAs($member);
    $this->getJson("/api/v1/communities/{$community->id}/chat/channel")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.channel.channel_name', 'general');

    $send = $this->postJson("/api/v1/communities/{$community->id}/chat/messages", [
        'body' => 'Warmup done, starting intervals.',
    ]);
    $send->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.message.body', 'Warmup done, starting intervals.');

    $this->getJson("/api/v1/communities/{$community->id}/chat/messages")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.messages.0.body', 'Warmup done, starting intervals.');
});

it('blocks requested members from community chat', function () {
    $owner = Client::factory()->create();
    $requested = Client::factory()->create();

    $community = Community::create([
        'owner_client_id' => $owner->id,
        'name' => 'Private Chat Club',
        'slug' => 'private-chat-club',
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
    CommunityMember::create([
        'community_id' => $community->id,
        'client_id' => $requested->id,
        'role' => 'member',
        'status' => 'requested',
        'joined_at' => null,
    ]);

    Sanctum::actingAs($requested);
    $this->getJson("/api/v1/communities/{$community->id}/chat/messages")
        ->assertStatus(403)
        ->assertJsonPath('success', false);

    $this->postJson("/api/v1/communities/{$community->id}/chat/messages", [
        'body' => 'Please accept me.',
    ])->assertStatus(403)
      ->assertJsonPath('success', false);
});

it('allows community owner to moderate and delete member chat message', function () {
    $owner = Client::factory()->create();
    $member = Client::factory()->create();
    $community = Community::create([
        'owner_client_id' => $owner->id,
        'name' => 'Moderation Club',
        'slug' => 'moderation-club',
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
    CommunityMember::create([
        'community_id' => $community->id,
        'client_id' => $member->id,
        'role' => 'member',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    Sanctum::actingAs($member);
    $send = $this->postJson("/api/v1/communities/{$community->id}/chat/messages", [
        'body' => 'Temporary message',
    ]);
    $send->assertStatus(201);
    $messageId = $send->json('data.message.id');

    Sanctum::actingAs($owner);
    $this->deleteJson("/api/v1/communities/{$community->id}/chat/messages/{$messageId}")
        ->assertOk()
        ->assertJsonPath('success', true);

    $message = Message::withTrashed()->find($messageId);
    expect($message)->not->toBeNull();
    expect($message->trashed())->toBeTrue();
});

