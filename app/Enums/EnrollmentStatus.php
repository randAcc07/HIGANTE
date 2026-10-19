<?php

namespace App\Enums;

enum EnrollmentStatus: string
{
    case Enrolled = 'enrolled';
    case ForDropping = 'for_dropping';
    case Dropped = 'dropped';
}
