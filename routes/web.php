<?php

use App\Http\Controllers\BadgeShareWebController;
use App\Http\Controllers\EventShareWebController;
use App\Http\Controllers\LeaderboardShareImageController;
use App\Http\Controllers\LeaderboardShareWebController;
use App\Http\Controllers\PersonalizedRewardImageController;
use App\Http\Controllers\ShareMediaProxyController;
use App\Http\Controllers\TrophyShareWebController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/share/badge/{clientId}/{eventId}/{badgeKey}', [BadgeShareWebController::class, 'show'])
    ->where('badgeKey', '.*')
    ->name('share.badge');

Route::get('/share/trophy/{clientId}/{eventId}/{trophyKey}', [TrophyShareWebController::class, 'show'])
    ->where('trophyKey', '.*')
    ->name('share.trophy');

Route::get('/share/event/{eventId}/standing/{clientId}', [EventShareWebController::class, 'showStanding'])
    ->name('share.event.standing');

Route::get('/share/event/{eventId}', [EventShareWebController::class, 'show'])
    ->name('share.event');

Route::get('/share/leaderboard/{eventId}/{clientId}', [LeaderboardShareWebController::class, 'show'])
    ->name('share.leaderboard');

Route::get('/share/leaderboard/{eventId}/{clientId}/card.png', [LeaderboardShareImageController::class, 'show'])
    ->name('share.leaderboard.card');

Route::get('/share/leaderboard/{eventId}/{clientId}/card.svg', [LeaderboardShareImageController::class, 'showSvg'])
    ->name('share.leaderboard.card.svg');

Route::get('/share/media-proxy', [ShareMediaProxyController::class, 'show'])
    ->name('share.media-proxy');

Route::get('/share/reward/{clientId}/{eventId}/{kind}/{rewardKey}.png', [PersonalizedRewardImageController::class, 'show'])
    ->where('rewardKey', '.*')
    ->name('share.reward.image');

Route::get('/share/reward/{clientId}/{eventId}/{kind}/{rewardKey}.svg', [PersonalizedRewardImageController::class, 'showSvg'])
    ->where('rewardKey', '.*')
    ->name('share.reward.svg');
