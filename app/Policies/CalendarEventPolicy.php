<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\CalendarEvent;
use App\Models\User;

/**
 * The admin keeps the campus-wide calendar; each dean keeps their own
 * college's events. Everyone can read the events that apply to them.
 */
class CalendarEventPolicy
{
    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::Dean);
    }

    public function update(User $user, CalendarEvent $event): bool
    {
        return match ($user->role) {
            UserRole::Admin => $event->college_id === null,
            UserRole::Dean => $event->college_id !== null && $event->college_id === $user->college_id,
            default => false,
        };
    }

    public function delete(User $user, CalendarEvent $event): bool
    {
        return $this->update($user, $event);
    }
}
