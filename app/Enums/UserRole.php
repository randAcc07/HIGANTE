<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Registrar = 'registrar';
    case Dean = 'dean';
    case Teacher = 'teacher';
    case Learner = 'learner';
}
