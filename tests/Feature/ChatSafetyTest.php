<?php

use App\Models\ChatBlock;
use App\Models\ChatReport;
use App\Models\Client;
use App\Models\ClientFollow;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('blocks direct chat bootstrap when users are blocked', function () {
    $me = Client::factory()->create();
    $buddy = Client::factory()->create();
    ClientFollow::create([
        'follower_client_id' => $me->id,
        'followed_client_id' => $buddy->id,
    ]);
    ChatBlock::create([
        'blocker_client_id' => $buddy->id,
        'blocked_client_id' => $me->id,
    ]);

    Sanctum::actingAs($me);
    $this->postJson('/api/v1/chat/direct/start', ['client_id' => $buddy->id])
        ->assertStatus(403)
        ->assertJsonPath('success', false);
});

it('supports block and unblock endpoints', function () {
    $me = Client::factory()->create();
    $target = Client::factory()->create();

    Sanctum::actingAs($me);
    $this->postJson('/api/v1/chat/blocks', [
        'client_id' => $target->id,
        'reason' => 'spam',
    ])->assertOk()->assertJsonPath('success', true);

    expect(ChatBlock::where('blocker_client_id', $me->id)->where('blocked_client_id', $target->id)->exists())->toBeTrue();

    $this->deleteJson("/api/v1/chat/blocks/{$target->id}")
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(ChatBlock::where('blocker_client_id', $me->id)->where('blocked_client_id', $target->id)->exists())->toBeFalse();
});

it('enforces moderation blocked terms policy on send', function () {
    config()->set('chat.moderation.blocked_terms', ['badword']);

    $sender = Client::factory()->create();
    $recipient = Client::factory()->create();
    $conversation = Conversation::create([
        'type' => 'direct',
        'created_by_client_id' => $sender->id,
        'is_active' => true,
    ]);
    ConversationMember::create([
        'conversation_id' => $conversation->id,
        'client_id' => $sender->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);
    ConversationMember::create([
        'conversation_id' => $conversation->id,
        'client_id' => $recipient->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    Sanctum::actingAs($sender);
    $this->postJson("/api/v1/chat/conversations/{$conversation->id}/messages", [
        'body' => 'contains badword here',
    ])->assertStatus(422)
      ->assertJsonPath('success', false);
});

it('hides messages older than retention window from poll results', function () {
    config()->set('chat.retention_days', 1);

    $sender = Client::factory()->create();
    $recipient = Client::factory()->create();
    $conversation = Conversation::create([
        'type' => 'direct',
        'created_by_client_id' => $sender->id,
        'is_active' => true,
    ]);
    ConversationMember::create([
        'conversation_id' => $conversation->id,
        'client_id' => $sender->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);
    ConversationMember::create([
        'conversation_id' => $conversation->id,
        'client_id' => $recipient->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $oldMessage = Message::create([
        'conversation_id' => $conversation->id,
        'sender_client_id' => $sender->id,
        'message_type' => 'text',
        'body' => 'old message',
    ]);
    $oldMessage->created_at = now()->subDays(5);
    $oldMessage->updated_at = now()->subDays(5);
    $oldMessage->saveQuietly();
    Message::create([
        'conversation_id' => $conversation->id,
        'sender_client_id' => $sender->id,
        'message_type' => 'text',
        'body' => 'fresh message',
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);

    Sanctum::actingAs($recipient);
    $res = $this->getJson("/api/v1/chat/conversations/{$conversation->id}/messages");
    $res->assertOk()->assertJsonPath('success', true);
    expect($res->json('data.messages'))->toHaveCount(1);
    expect($res->json('data.messages.0.body'))->toBe('fresh message');
});

it('allows reporting accessible messages', function () {
    $sender = Client::factory()->create();
    $recipient = Client::factory()->create();
    $conversation = Conversation::create([
        'type' => 'direct',
        'created_by_client_id' => $sender->id,
        'is_active' => true,
    ]);
    ConversationMember::create([
        'conversation_id' => $conversation->id,
        'client_id' => $sender->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);
    ConversationMember::create([
        'conversation_id' => $conversation->id,
        'client_id' => $recipient->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);
    $message = Message::create([
        'conversation_id' => $conversation->id,
        'sender_client_id' => $sender->id,
        'message_type' => 'text',
        'body' => 'suspicious',
    ]);

    Sanctum::actingAs($recipient);
    $this->postJson("/api/v1/chat/messages/{$message->id}/report", [
        'reason' => 'spam',
        'notes' => 'Looks suspicious',
    ])->assertStatus(201)
      ->assertJsonPath('success', true);

    expect(ChatReport::where('message_id', $message->id)->where('reporter_client_id', $recipient->id)->exists())->toBeTrue();
});

