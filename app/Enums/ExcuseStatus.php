<?php

namespace App\Enums;

/**
 * An excuse's status, summarized from its per-class decisions: pending while
 * any class is undecided, else approved, partial, rejected (all declined)
 * or revoked (every approval revoked).
 */
enum ExcuseStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    /** Approved in some classes, declined or revoked in others. */
    case Partial = 'partial';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
}
