<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Registrar = 'registrar';
    case Dean = 'dean';
    case Teacher = 'teacher';
    case Learner = 'learner';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Registrar => 'Registrar',
            self::Dean => 'Dean',
            self::Teacher => 'Teacher',
            self::Learner => 'Learner',
        };
    }

    /**
     * Everyone but learners. Staff may change their own name.
     */
    public function isStaff(): bool
    {
        return $this !== self::Learner;
    }

    /**
     * Deans, teachers and learners belong to one college; the admin and the
     * registrar are campus-wide.
     */
    public function belongsToCollege(): bool
    {
        return in_array($this, [self::Dean, self::Teacher, self::Learner], true);
    }
}
