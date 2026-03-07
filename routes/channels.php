<?php

use App\Enums\UserRole;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('admin.token-tts', function ($user) {
    return $user->role === UserRole::Admin;
});

Broadcast::channel('admin.station-tts', function ($user) {
    return $user->role === UserRole::Admin;
});
