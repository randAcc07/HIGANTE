<?php

namespace App\Enums;

/**
 * One class's decision on an excuse.
 */
enum DecisionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
    case Revoked = 'revoked';
}
