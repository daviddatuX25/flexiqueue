<?php

use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('admin.token-tts', function ($user) {
    return $user->can(PermissionCatalog::ADMIN_MANAGE) || $user->can(PermissionCatalog::PLATFORM_MANAGE);
});

Broadcast::channel('admin.station-tts', function ($user) {
    return $user->can(PermissionCatalog::ADMIN_MANAGE) || $user->can(PermissionCatalog::PLATFORM_MANAGE);
});
