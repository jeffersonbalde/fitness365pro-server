<?php

use App\Models\Admin;
use App\Models\AdminPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('allows authenticated admin to create cms post', function () {
    $admin = Admin::create([
        'name' => 'CMS Admin',
        'email' => 'cmsadmin@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    Sanctum::actingAs($admin, ['*'], 'admin');

    $response = $this->postJson('/api/v1/admin/posts', [
        'title' => 'New CMS Card',
        'body' => 'This should appear on home feed.',
        'status' => 'published',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.post.title', 'New CMS Card');

    expect(AdminPost::count())->toBe(1);
});

it('returns published admin cms posts in public cms feed endpoint for authenticated client', function () {
    $admin = Admin::create([
        'name' => 'CMS Admin',
        'email' => 'cmsadmin2@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    AdminPost::create([
        'admin_id' => $admin->id,
        'title' => 'Published Post',
        'body' => 'Visible on feed',
        'status' => 'published',
        'publish_at' => now()->subMinute(),
    ]);

    AdminPost::create([
        'admin_id' => $admin->id,
        'title' => 'Draft Post',
        'body' => 'Not visible on feed',
        'status' => 'draft',
    ]);

    $client = \App\Models\Client::factory()->create();
    Sanctum::actingAs($client);

    $response = $this->getJson('/api/v1/cms/feed');
    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data.posts')
        ->assertJsonPath('data.posts.0.title', 'Published Post');
});

