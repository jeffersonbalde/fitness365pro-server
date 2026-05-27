<?php

use App\Models\Client;
use App\Models\ClientProfile;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('supports sending and polling messages in active conversation membership', function () {
    $sender = Client::factory()->create();
    $recipient = Client::factory()->create();
    ClientProfile::create(['client_id' => $sender->id, 'display_name' => 'Sender']);
    ClientProfile::create(['client_id' => $recipient->id, 'display_name' => 'Recipient']);

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
    $send = $this->postJson("/api/v1/chat/conversations/{$conversation->id}/messages", [
        'body' => 'Let us train at 6!',
    ]);
    $send->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.message.delivery_status', 'sent');

    Sanctum::actingAs($recipient);
    $poll = $this->getJson("/api/v1/chat/conversations/{$conversation->id}/messages?per_page=20");
    $poll->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.messages.0.body', 'Let us train at 6!');

    $freshMessage = Message::find($send->json('data.message.id'));
    expect($freshMessage)->not->toBeNull();
    expect($freshMessage->delivery_status)->toBe('delivered');
    expect($freshMessage->delivered_at)->not->toBeNull();
});

it('marks delivered messages as read when member marks conversation read', function () {
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
        'body' => 'Check your run splits.',
        'delivery_status' => 'delivered',
        'delivered_at' => now(),
    ]);

    Sanctum::actingAs($recipient);
    $this->postJson("/api/v1/chat/conversations/{$conversation->id}/read")
        ->assertOk()
        ->assertJsonPath('success', true);

    $freshMessage = Message::find($message->id);
    expect($freshMessage->delivery_status)->toBe('read');
    expect($freshMessage->read_at)->not->toBeNull();

    $membership = ConversationMember::where('conversation_id', $conversation->id)
        ->where('client_id', $recipient->id)
        ->first();
    expect($membership)->not->toBeNull();
    expect($membership->last_read_at)->not->toBeNull();
});

it('blocks non-members from polling or sending conversation messages', function () {
    $owner = Client::factory()->create();
    $outsider = Client::factory()->create();

    $conversation = Conversation::create([
        'type' => 'direct',
        'created_by_client_id' => $owner->id,
        'is_active' => true,
    ]);
    ConversationMember::create([
        'conversation_id' => $conversation->id,
        'client_id' => $owner->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    Sanctum::actingAs($outsider);
    $this->getJson("/api/v1/chat/conversations/{$conversation->id}/messages")
        ->assertStatus(403)
        ->assertJsonPath('success', false);

    $this->postJson("/api/v1/chat/conversations/{$conversation->id}/messages", [
        'body' => 'I should not be able to send this.',
    ])->assertStatus(403)
      ->assertJsonPath('success', false);
});

