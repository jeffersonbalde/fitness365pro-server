<?php

use App\Models\Client;
use App\Models\ClientFollow;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('starts direct chat for follow-connected users and reuses same conversation', function () {
    $me = Client::factory()->create();
    $buddy = Client::factory()->create();
    ClientFollow::create([
        'follower_client_id' => $me->id,
        'followed_client_id' => $buddy->id,
    ]);

    Sanctum::actingAs($me);
    $first = $this->postJson('/api/v1/chat/direct/start', ['client_id' => $buddy->id]);
    $first->assertOk()->assertJsonPath('success', true);
    $conversationId = $first->json('data.conversation.id');

    expect(Conversation::where('id', $conversationId)->exists())->toBeTrue();
    expect(ConversationMember::where('conversation_id', $conversationId)->where('client_id', $me->id)->exists())->toBeTrue();
    expect(ConversationMember::where('conversation_id', $conversationId)->where('client_id', $buddy->id)->exists())->toBeTrue();

    $second = $this->postJson('/api/v1/chat/direct/start', ['client_id' => $buddy->id]);
    $second->assertOk()->assertJsonPath('data.conversation.id', $conversationId);
});

it('blocks direct chat start without follow connection', function () {
    $me = Client::factory()->create();
    $stranger = Client::factory()->create();

    Sanctum::actingAs($me);
    $this->postJson('/api/v1/chat/direct/start', ['client_id' => $stranger->id])
        ->assertStatus(403)
        ->assertJsonPath('success', false);
});

it('returns unread counts in inbox conversation list', function () {
    $me = Client::factory()->create();
    $buddy = Client::factory()->create();

    $conversation = Conversation::create([
        'type' => 'direct',
        'created_by_client_id' => $buddy->id,
        'is_active' => true,
    ]);
    ConversationMember::create([
        'conversation_id' => $conversation->id,
        'client_id' => $me->id,
        'status' => 'active',
        'joined_at' => now()->subDay(),
        'last_read_at' => now()->subHour(),
    ]);
    ConversationMember::create([
        'conversation_id' => $conversation->id,
        'client_id' => $buddy->id,
        'status' => 'active',
        'joined_at' => now()->subDay(),
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'sender_client_id' => $buddy->id,
        'body' => 'First unread',
        'message_type' => 'text',
        'delivery_status' => 'sent',
        'created_at' => now()->subMinutes(20),
        'updated_at' => now()->subMinutes(20),
    ]);
    Message::create([
        'conversation_id' => $conversation->id,
        'sender_client_id' => $buddy->id,
        'body' => 'Second unread',
        'message_type' => 'text',
        'delivery_status' => 'sent',
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    Sanctum::actingAs($me);
    $response = $this->getJson('/api/v1/chat/conversations');
    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.conversations.0.unread_count', 2);
});

