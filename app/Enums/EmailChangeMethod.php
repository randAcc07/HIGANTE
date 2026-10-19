<?php

namespace App\Enums;

enum EmailChangeMethod: string
{
    case SelfService = 'self_service';
    case Admin = 'admin';
}
