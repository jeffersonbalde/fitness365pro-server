<?php

use App\Http\Controllers\BadgeShareWebController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/share/badge/{clientId}/{eventId}/{badgeKey}', [BadgeShareWebController::class, 'show'])
    ->where('badgeKey', '.*')
    ->name('share.badge');
