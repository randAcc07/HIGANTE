<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Dean = 'dean';
    case Teacher = 'teacher';
    case Learner = 'learner';
}
