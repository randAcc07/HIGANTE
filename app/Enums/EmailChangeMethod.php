<?php

namespace App\Enums;

enum EmailChangeMethod: string
{
    case SelfService = 'self_service';
    case Admin = 'admin';
    /** By whoever runs the server (app:admin-email), for admin accounts. */
    case Server = 'server';
}
