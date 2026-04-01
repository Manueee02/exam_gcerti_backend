<?php

namespace App\Services;

use App\Models\User;

class UserService
{
    public function getAdminUsersByNotificationType($type)
    {
        return User::query()
            ->whereHas('role', function ($q) {
                $q->whereIn('name', ['admin', 'superAdmin']);
            })
            ->whereHas('mailNotifications', function ($q) use ($type) {
                $q->where('active', 'true')
                    ->where('type', $type);
            })
            ->get();
    }
}
