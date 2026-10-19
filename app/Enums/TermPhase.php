<?php

namespace App\Enums;

/**
 * Where a term is in its life. Upcoming, in session and ended come from the
 * dates; closed is set by the admin after a term ends.
 */
enum TermPhase: string
{
    case Upcoming = 'upcoming';
    case InSession = 'in_session';
    case Ended = 'ended';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Upcoming => 'Upcoming',
            self::InSession => 'In session',
            self::Ended => 'Ended',
            self::Closed => 'Closed',
        };
    }
}
