<?php

namespace App\Enums;

enum Semester: string
{
    case First = 'first';
    case Second = 'second';
    case Summer = 'summer';

    public function label(): string
    {
        return match ($this) {
            self::First => '1st Semester',
            self::Second => '2nd Semester',
            self::Summer => 'Summer',
        };
    }
}
