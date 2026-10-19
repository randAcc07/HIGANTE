<?php

namespace App\Enums;

enum ExcuseReason: string
{
    case Medical = 'medical';
    case Illness = 'illness';
    case SchoolActivity = 'school_activity';
    case Other = 'other';

    /**
     * A medical excuse cannot be approved without at least one document
     * (the physician-signed certificate the handbook asks for).
     */
    public function requiresDocument(): bool
    {
        return $this === self::Medical;
    }
}
