<?php

use App\Http\Controllers\BadgeShareWebController;
use App\Http\Controllers\EventShareWebController;
use App\Http\Controllers\PersonalizedRewardImageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/share/badge/{clientId}/{eventId}/{badgeKey}', [BadgeShareWebController::class, 'show'])
    ->where('badgeKey', '.*')
    ->name('share.badge');

Route::get('/share/event/{eventId}', [EventShareWebController::class, 'show'])
    ->name('share.event');

Route::get('/share/reward/{clientId}/{eventId}/{kind}/{rewardKey}.png', [PersonalizedRewardImageController::class, 'show'])
    ->where('rewardKey', '.*')
    ->name('share.reward.image');

Route::get('/share/reward/{clientId}/{eventId}/{kind}/{rewardKey}.svg', [PersonalizedRewardImageController::class, 'showSvg'])
    ->where('rewardKey', '.*')
    ->name('share.reward.svg');
