<?php

namespace App\Enums;

enum LearnerStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Graduated = 'graduated';
    case DroppedOut = 'dropped_out';
}
