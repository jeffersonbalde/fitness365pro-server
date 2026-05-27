<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientFollow;
use App\Models\ClientProfile;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Goal;
use App\Models\WorkoutLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class FitnessDemoSeeder extends Seeder
{
    private function upsertClientWithProfile(array $payload): Client
    {
        $client = Client::updateOrCreate(
            ['email' => $payload['email']],
            [
                'password' => Hash::make($payload['password'] ?? 'password123'),
                'email_verified_at' => now(),
            ]
        );

        ClientProfile::updateOrCreate(
            ['client_id' => $client->id],
            [
                'first_name' => $payload['first_name'],
                'last_name' => $payload['last_name'],
                'display_name' => $payload['display_name'],
                'profile_picture_url' => $payload['profile_picture_url'] ?? null,
                'city' => $payload['city'] ?? null,
                'province' => $payload['province'] ?? null,
                'country' => $payload['country'] ?? 'Philippines',
                'primary_niche' => $payload['primary_niche'] ?? 'gym',
                'experience_level' => $payload['experience_level'] ?? 'intermediate',
                'onboarding_step' => 6,
                'onboarding_completed' => true,
            ]
        );

        $goalIds = Goal::query()
            ->whereIn('slug', $payload['goals'] ?? ['build-muscle', 'stay-active'])
            ->pluck('id')
            ->all();
        if (!empty($goalIds)) {
            $client->goals()->syncWithoutDetaching($goalIds);
        }

        return $client;
    }

    private function seedWorkoutLogs(Client $client, int $days, float $distanceBase): void
    {
        for ($i = 0; $i < $days; $i++) {
            $workoutDate = Carbon::now()->subDays($i)->toDateString();
            $distance = round(max(1.5, $distanceBase + (($i % 4) * 0.6)), 2);
            $duration = 40 + (($i % 5) * 8);
            $seconds = $duration * 60;
            $pace = round(($seconds / 60) / $distance, 2);

            WorkoutLog::updateOrCreate(
                [
                    'client_id' => $client->id,
                    'workout_date' => $workoutDate,
                    'workout_type' => $i % 2 === 0 ? 'Strength Session' : 'Conditioning',
                ],
                [
                    'duration_minutes' => $duration,
                    'distance_km' => $distance,
                    'duration_seconds' => $seconds,
                    'pace_min_per_km' => $pace,
                    'status' => 'completed',
                    'notes' => $i % 2 === 0
                        ? 'Focused on progressive overload and form.'
                        : 'Conditioning block with intervals and mobility finisher.',
                    'plan_day' => $i + 1,
                ]
            );
        }
    }

    public function run(): void
    {
        $people = [
            [
                'email' => 'client@example.com',
                'first_name' => 'Client',
                'last_name' => 'Demo',
                'display_name' => 'Client',
                'profile_picture_url' => 'https://i.pravatar.cc/300?img=12',
                'city' => 'Pagadian City',
                'province' => 'Zamboanga del Sur',
                'primary_niche' => 'gym',
                'goals' => ['build-muscle', 'stay-active'],
            ],
            [
                'email' => 'alex.gym@example.com',
                'first_name' => 'Alex',
                'last_name' => 'Reyes',
                'display_name' => 'Alex Reyes',
                'profile_picture_url' => 'https://i.pravatar.cc/300?img=14',
                'city' => 'Cebu City',
                'province' => 'Cebu',
                'primary_niche' => 'gym',
                'goals' => ['build-muscle', 'improve-cardio'],
            ],
            [
                'email' => 'maria.fit@example.com',
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'display_name' => 'Maria Santos',
                'profile_picture_url' => 'https://i.pravatar.cc/300?img=47',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'primary_niche' => 'gym',
                'goals' => ['lose-weight', 'stay-active'],
            ],
            [
                'email' => 'leo.train@example.com',
                'first_name' => 'Leo',
                'last_name' => 'Garcia',
                'display_name' => 'Leo Garcia',
                'profile_picture_url' => 'https://i.pravatar.cc/300?img=53',
                'city' => 'Iloilo City',
                'province' => 'Iloilo',
                'primary_niche' => 'gym',
                'goals' => ['build-muscle', 'increase-strength'],
            ],
            [
                'email' => 'nina.run@example.com',
                'first_name' => 'Nina',
                'last_name' => 'Torres',
                'display_name' => 'Nina Torres',
                'profile_picture_url' => 'https://i.pravatar.cc/300?img=32',
                'city' => 'Quezon City',
                'province' => 'Metro Manila',
                'primary_niche' => 'running',
                'goals' => ['improve-cardio', 'run-longer'],
            ],
        ];

        $clientsByEmail = [];
        foreach ($people as $person) {
            $client = $this->upsertClientWithProfile($person);
            $clientsByEmail[$person['email']] = $client;
        }

        $primary = $clientsByEmail['client@example.com'] ?? null;
        if ($primary) {
            foreach ($clientsByEmail as $email => $target) {
                if ($target->id === $primary->id) {
                    continue;
                }
                ClientFollow::firstOrCreate([
                    'follower_client_id' => $primary->id,
                    'followed_client_id' => $target->id,
                ]);
            }
        }

        $distanceBaseByEmail = [
            'client@example.com' => 3.4,
            'alex.gym@example.com' => 4.0,
            'maria.fit@example.com' => 3.1,
            'leo.train@example.com' => 4.6,
            'nina.run@example.com' => 5.2,
        ];
        $daysByEmail = [
            'client@example.com' => 9,
            'alex.gym@example.com' => 11,
            'maria.fit@example.com' => 8,
            'leo.train@example.com' => 12,
            'nina.run@example.com' => 10,
        ];

        foreach ($clientsByEmail as $email => $client) {
            $this->seedWorkoutLogs(
                $client,
                $daysByEmail[$email] ?? 7,
                $distanceBaseByEmail[$email] ?? 3.0
            );
        }

        if (!$primary) {
            return;
        }

        $challengeDefinitions = [
            [
                'name' => 'Virtual Run Challenge 5K',
                'description' => 'Complete a 5K run within the challenge window and submit your result.',
                'primary_niche' => 'running',
                'visibility' => 'public',
                'cover_image_url' => 'https://images.unsplash.com/photo-1461896836934-ffe607ba8211?auto=format&fit=crop&w=1200&q=80',
            ],
            [
                'name' => 'Virtual Run Challenge 10K',
                'description' => 'Build your distance and complete a 10K run before registration closes.',
                'primary_niche' => 'running',
                'visibility' => 'public',
                'cover_image_url' => 'https://images.unsplash.com/photo-1552674605-db6ffd4facb5?auto=format&fit=crop&w=1200&q=80',
            ],
            [
                'name' => 'Virtual Half Marathon Challenge',
                'description' => 'Train progressively and finish a 21K half marathon effort virtually.',
                'primary_niche' => 'running',
                'visibility' => 'public',
                'cover_image_url' => 'https://images.unsplash.com/photo-1549576490-b0b4831ef60a?auto=format&fit=crop&w=1200&q=80',
            ],
        ];

        $members = collect($clientsByEmail)->values();
        foreach ($challengeDefinitions as $challenge) {
            $baseSlug = Str::slug($challenge['name']);
            $community = Community::firstOrCreate(
                ['slug' => $baseSlug],
                [
                    'owner_client_id' => $primary->id,
                    'name' => $challenge['name'],
                    'description' => $challenge['description'],
                    'primary_niche' => $challenge['primary_niche'],
                    'city' => 'Online',
                    'province' => 'Global',
                    'country' => 'Philippines',
                    'visibility' => $challenge['visibility'],
                    'cover_image_url' => $challenge['cover_image_url'],
                    'is_active' => true,
                ]
            );

            foreach ($members as $idx => $member) {
                if ($idx > 3) {
                    break;
                }
                CommunityMember::updateOrCreate(
                    [
                        'community_id' => $community->id,
                        'client_id' => $member->id,
                    ],
                    [
                        'role' => $member->id === $primary->id ? 'owner' : 'member',
                        'status' => 'active',
                        'joined_at' => now()->subDays(rand(1, 20)),
                    ]
                );
            }
        }
    }
}

