<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RaceResultsController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PublicCmsController;
use App\Http\Controllers\Api\WorkoutController;
use App\Http\Controllers\Api\SocialController;
use App\Http\Controllers\Api\CommunityController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\EventGymSelectionController;
use App\Http\Controllers\Api\EventRegistrationController;
use App\Http\Controllers\Api\EventRunningSelectionController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\MayaWebhookController;
use App\Http\Controllers\Api\Admin\AdminCmsController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminEventProgressController;
use App\Http\Controllers\Api\Admin\AdminEventParticipantsController;
use App\Http\Controllers\Api\Admin\AdminModuleController;
use App\Http\Controllers\Api\Admin\AdminMembersController;
use App\Http\Controllers\Api\BadgeShareController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Legacy path used in Maya Business Manager (same as older projects): POST /api/webhooks/paymaya
Route::post('/webhooks/paymaya', [MayaWebhookController::class, 'handle']);

Route::prefix('v1')->group(function () {
    // Public authentication routes
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/google', [AuthController::class, 'google']);
    Route::post('/auth/verify-email', [EmailVerificationController::class, 'verify'])->middleware('throttle:10,1');
    Route::post('/auth/resend-otp', [EmailVerificationController::class, 'resend'])->middleware('throttle:5,1');
    
    // Password reset routes
    Route::post('/auth/forgot-password', [ForgotPasswordController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/auth/verify-reset-token', [ForgotPasswordController::class, 'verifyToken']);
    Route::post('/auth/reset-password', [ForgotPasswordController::class, 'resetPassword'])->middleware('throttle:5,1');
    Route::get('/profile/media/{path}', [ProfileController::class, 'media'])->where('path', '.*');
    Route::get('/public/badges/{clientId}/{eventId}/{badgeKey}', [BadgeShareController::class, 'show'])
        ->where('badgeKey', '.*');
    Route::post('/paymaya/webhook', [MayaWebhookController::class, 'handle']);

    // Protected routes (require authentication)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // Profile routes
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::put('/profile/goals', [ProfileController::class, 'updateGoals']);
        Route::post('/profile/picture', [ProfileController::class, 'uploadPicture']);
        Route::delete('/profile/picture', [ProfileController::class, 'removePicture']);
        Route::post('/profile/picture/from-library', [ProfileController::class, 'setPictureFromLibrary']);
        Route::post('/profile/cover-photo', [ProfileController::class, 'uploadCoverPhoto']);
        Route::delete('/profile/cover-photo', [ProfileController::class, 'removeCoverPhoto']);
        Route::post('/profile/cover-photo/from-library', [ProfileController::class, 'setCoverFromLibrary']);
        Route::get('/profile/media-library', [ProfileController::class, 'mediaLibrary']);
        Route::get('/profile/badges', [ProfileController::class, 'badges']);
        Route::get('/profile/transactions', [ProfileController::class, 'transactions']);
        Route::get('/profile/race-results/events', [RaceResultsController::class, 'completedEvents']);
        Route::get('/profile/race-results/events/{id}', [RaceResultsController::class, 'eventResults']);

        // Onboarding routes
        Route::get('/onboarding/status', [OnboardingController::class, 'getStatus']);
        Route::get('/onboarding/goals', [OnboardingController::class, 'getGoals']);
        Route::post('/onboarding/step/{step}', [OnboardingController::class, 'updateStep']);
        Route::get('/onboarding/fitness-plan-status', [OnboardingController::class, 'getFitnessPlanStatus']);

        // Workout routes
        Route::post('/workouts', [WorkoutController::class, 'log']);
        Route::put('/workouts/{id}', [WorkoutController::class, 'update']);
        Route::delete('/workouts/{id}', [WorkoutController::class, 'destroy']);
        Route::get('/workouts', [WorkoutController::class, 'index']);
        Route::get('/workouts/feed', [WorkoutController::class, 'feed']);
        Route::get('/workouts/search', [WorkoutController::class, 'search']);
        Route::get('/workouts/today', [WorkoutController::class, 'today']);
        Route::get('/workouts/stats', [WorkoutController::class, 'stats']);
        Route::get('/workouts/{id}/likes', [WorkoutController::class, 'likes']);
        Route::post('/workouts/{id}/likes', [WorkoutController::class, 'like']);
        Route::delete('/workouts/{id}/likes', [WorkoutController::class, 'unlike']);
        Route::get('/workouts/{id}/comments', [WorkoutController::class, 'comments']);
        Route::post('/workouts/{id}/comments', [WorkoutController::class, 'addComment']);
        Route::get('/workout-comments/{id}/likes', [WorkoutController::class, 'commentLikes']);
        Route::post('/workout-comments/{id}/likes', [WorkoutController::class, 'likeComment']);
        Route::delete('/workout-comments/{id}/likes', [WorkoutController::class, 'unlikeComment']);

        // Social routes
        Route::get('/social/stats', [SocialController::class, 'stats']);
        Route::get('/social/followers', [SocialController::class, 'followers']);
        Route::get('/social/following', [SocialController::class, 'following']);
        Route::get('/social/discover', [SocialController::class, 'discover']);
        Route::get('/social/leaderboard', [SocialController::class, 'leaderboard']);
        Route::get('/social/suggested-buddies', [SocialController::class, 'suggestedBuddies']);
        Route::get('/social/profile/{clientId}', [SocialController::class, 'userProfile']);
        Route::get('/social/profile/{clientId}/followers', [SocialController::class, 'userFollowers']);
        Route::get('/social/profile/{clientId}/following', [SocialController::class, 'userFollowing']);
        Route::get('/social/profile/{clientId}/events/{eventId}/challenge-history', [EventRegistrationController::class, 'memberChallengeHistory']);
        Route::post('/social/follow', [SocialController::class, 'follow']);
        Route::post('/social/unfollow', [SocialController::class, 'unfollow']);

        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

        // Community routes
        Route::get('/communities', [CommunityController::class, 'index']);
        Route::post('/communities', [CommunityController::class, 'store']);
        Route::get('/communities/{communityId}', [CommunityController::class, 'show']);
        Route::get('/communities/{communityId}/posts', [CommunityController::class, 'posts']);
        Route::post('/communities/{communityId}/posts', [CommunityController::class, 'createPost']);
        Route::delete('/communities/{communityId}/posts/{postId}', [CommunityController::class, 'deletePost']);
        Route::post('/communities/{communityId}/join', [CommunityController::class, 'join']);
        Route::post('/communities/{communityId}/leave', [CommunityController::class, 'leave']);
        Route::get('/communities/{communityId}/chat/channel', [ChatController::class, 'communityChannel']);
        Route::get('/communities/{communityId}/chat/messages', [ChatController::class, 'communityMessages']);
        Route::post('/communities/{communityId}/chat/messages', [ChatController::class, 'sendCommunityMessage']);
        Route::delete('/communities/{communityId}/chat/messages/{messageId}', [ChatController::class, 'deleteCommunityMessage']);

        // Chat polling routes
        Route::get('/chat/conversations', [ChatController::class, 'conversations']);
        Route::post('/chat/blocks', [ChatController::class, 'blockClient']);
        Route::delete('/chat/blocks/{clientId}', [ChatController::class, 'unblockClient']);
        Route::post('/chat/messages/{messageId}/report', [ChatController::class, 'reportMessage']);
        Route::post('/chat/direct/start', [ChatController::class, 'startDirect']);
        Route::get('/chat/conversations/{conversationId}/messages', [ChatController::class, 'messages']);
        Route::post('/chat/conversations/{conversationId}/messages', [ChatController::class, 'sendMessage']);
        Route::post('/chat/conversations/{conversationId}/read', [ChatController::class, 'markRead']);

        // Public CMS feed for client home
        Route::get('/cms/feed', [PublicCmsController::class, 'feed']);
        Route::get('/cms/announcements', [PublicCmsController::class, 'announcements']);
        Route::get('/cms/events', [PublicCmsController::class, 'events']);
        Route::get('/cms/events/{id}', [PublicCmsController::class, 'eventShow']);
        Route::get('/cms/events/{id}/leaderboard', [PublicCmsController::class, 'eventLeaderboard']);
        Route::get('/cms/events/{id}/registration', [EventRegistrationController::class, 'state']);
        Route::put('/cms/events/{id}/registration/participant', [EventRegistrationController::class, 'saveParticipant']);
        Route::put('/cms/events/{id}/registration/delivery', [EventRegistrationController::class, 'saveDelivery']);
        Route::post('/cms/events/{id}/registration/confirm', [EventRegistrationController::class, 'confirm']);
        Route::post('/cms/events/{id}/registration/paymaya/checkout', [EventRegistrationController::class, 'paymayaCheckout']);
        Route::post('/cms/events/{id}/registration/paymaya/verify', [EventRegistrationController::class, 'paymayaVerify']);
        Route::post('/cms/events/{id}/registration/paymaya/sync', [EventRegistrationController::class, 'paymayaSync']);
        Route::patch('/cms/events/{id}/registration/progress', [EventRegistrationController::class, 'logProgress']);
        Route::get('/cms/events/{id}/my-challenge-history', [EventRegistrationController::class, 'myChallengeHistory']);
        Route::post('/cms/events/{id}/register', [EventRegistrationController::class, 'register']);
        Route::get('/cms/events/{id}/running-selection', [EventRunningSelectionController::class, 'show']);
        Route::put('/cms/events/{id}/running-selection', [EventRunningSelectionController::class, 'update']);
        Route::get('/cms/events/{id}/gym-selection', [EventGymSelectionController::class, 'show']);
        Route::put('/cms/events/{id}/gym-selection', [EventGymSelectionController::class, 'update']);
    });

    // Refresh is public: it uses refresh_token, not access token
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);

    // Admin auth (separate from clients)
    Route::prefix('admin')->group(function () {
        Route::post('/auth/login', [AdminAuthController::class, 'login']);
        Route::post('/auth/refresh', [AdminAuthController::class, 'refresh']);

        Route::middleware('auth:admin')->group(function () {
            Route::post('/auth/logout', [AdminAuthController::class, 'logout']);
            Route::get('/auth/me', [AdminAuthController::class, 'me']);

            Route::get('/dashboard/stats', [AdminDashboardController::class, 'stats']);

            Route::get('/posts', [AdminCmsController::class, 'posts']);
            Route::post('/posts', [AdminCmsController::class, 'createPost']);
            Route::put('/posts/{id}', [AdminCmsController::class, 'updatePost']);
            Route::delete('/posts/{id}', [AdminCmsController::class, 'deletePost']);

            Route::get('/announcements', [AdminCmsController::class, 'announcements']);
            Route::post('/announcements', [AdminCmsController::class, 'createAnnouncement']);
            Route::put('/announcements/{id}', [AdminCmsController::class, 'updateAnnouncement']);
            Route::delete('/announcements/{id}', [AdminCmsController::class, 'deleteAnnouncement']);

            Route::get('/events', [AdminCmsController::class, 'events']);
            Route::post('/events/upload-image', [AdminCmsController::class, 'uploadEventImage']);
            Route::post('/events/upload-badge-image', [AdminCmsController::class, 'uploadEventBadgeImage']);
            Route::post('/events/upload-trophy-image', [AdminCmsController::class, 'uploadEventTrophyImage']);
            Route::post('/events', [AdminCmsController::class, 'createEvent']);
            Route::put('/events/{id}', [AdminCmsController::class, 'updateEvent']);
            Route::delete('/events/{id}', [AdminCmsController::class, 'deleteEvent']);
            Route::get('/events/{id}/registrations', [AdminEventParticipantsController::class, 'registrations']);
            Route::post('/events/{id}/registrations/manual', [AdminEventParticipantsController::class, 'manualRegister']);
            Route::post('/events/{eventId}/registrations/{registrationId}/sync-payment', [AdminEventParticipantsController::class, 'syncPayment']);

            Route::get('/event-progress-submissions', [AdminEventProgressController::class, 'index']);
            Route::post('/event-progress-submissions/{id}/approve', [AdminEventProgressController::class, 'approve']);
            Route::post('/event-progress-submissions/{id}/reject', [AdminEventProgressController::class, 'reject']);

            Route::get('/client-records', [AdminModuleController::class, 'clientRecords']);
            Route::get('/members', [AdminMembersController::class, 'index']);
            Route::get('/members/{id}', [AdminMembersController::class, 'show']);
            Route::get('/program-enrollments', [AdminModuleController::class, 'programEnrollments']);
            Route::get('/progress-updates', [AdminModuleController::class, 'progressUpdates']);
            Route::get('/due-notifications', [AdminModuleController::class, 'dueNotifications']);
            Route::get('/reports/summary', [AdminModuleController::class, 'reportsSummary']);

            Route::get('/program-catalog', [AdminModuleController::class, 'programCatalog']);
            Route::post('/program-catalog', [AdminModuleController::class, 'createProgramCatalog']);
            Route::put('/program-catalog/{id}', [AdminModuleController::class, 'updateProgramCatalog']);
            Route::delete('/program-catalog/{id}', [AdminModuleController::class, 'deleteProgramCatalog']);

            Route::get('/users', [AdminModuleController::class, 'users']);
            Route::post('/users', [AdminModuleController::class, 'createUser']);
            Route::put('/users/{id}', [AdminModuleController::class, 'updateUser']);
            Route::delete('/users/{id}', [AdminModuleController::class, 'deleteUser']);
            Route::get('/activity-logs', [AdminModuleController::class, 'activityLogs']);
        });
    });
});

