<?php

namespace App\Enums;

enum RegistrationStatus: string
{
    case Registered = 'registered';
    case Withdrawn = 'withdrawn';
}
