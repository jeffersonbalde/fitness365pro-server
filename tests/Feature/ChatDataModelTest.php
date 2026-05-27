<?php

use App\Models\Client;
use App\Models\ClientProfile;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('supports creating a community channel conversation with members and messages', function () {
    $owner = Client::factory()->create();
    $member = Client::factory()->create();
    ClientProfile::create(['client_id' => $owner->id, 'display_name' => 'Owner']);
    ClientProfile::create(['client_id' => $member->id, 'display_name' => 'Member']);

    $community = Community::create([
        'owner_client_id' => $owner->id,
        'name' => 'Runners Community',
        'slug' => 'runners-community',
        'primary_niche' => 'running',
        'visibility' => 'public',
        'is_active' => true,
    ]);

    $conversation = Conversation::create([
        'type' => 'community_channel',
        'community_id' => $community->id,
        'channel_name' => 'general',
        'created_by_client_id' => $owner->id,
        'is_active' => true,
    ]);

    ConversationMember::create([
        'conversation_id' => $conversation->id,
        'client_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'joined_at' => now(),
    ]);
    ConversationMember::create([
        'conversation_id' => $conversation->id,
        'client_id' => $member->id,
        'role' => 'member',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'sender_client_id' => $member->id,
        'message_type' => 'text',
        'body' => 'Morning run done!',
        'attachments' => [],
    ]);

    expect($message->conversation->id)->toBe($conversation->id);
    expect($conversation->members()->count())->toBe(2);
    expect($conversation->messages()->count())->toBe(1);
});

it('supports direct conversations without community reference', function () {
    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();

    $conversation = Conversation::create([
        'type' => 'direct',
        'community_id' => null,
        'channel_name' => null,
        'created_by_client_id' => $clientA->id,
        'is_active' => true,
    ]);

    ConversationMember::create([
        'conversation_id' => $conversation->id,
        'client_id' => $clientA->id,
        'role' => 'member',
        'status' => 'active',
        'joined_at' => now(),
    ]);
    ConversationMember::create([
        'conversation_id' => $conversation->id,
        'client_id' => $clientB->id,
        'role' => 'member',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'sender_client_id' => $clientA->id,
        'message_type' => 'text',
        'body' => 'Yo! Ready for tonight lift?',
    ]);

    expect($conversation->type)->toBe('direct');
    expect($conversation->community_id)->toBeNull();
    expect($conversation->members()->count())->toBe(2);
    expect($conversation->messages()->count())->toBe(1);
});

