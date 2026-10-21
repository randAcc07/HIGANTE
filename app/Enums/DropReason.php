<?php

namespace App\Enums;

/**
 * Why an enrollment was dropped. Only an absence drop can be reinstated by
 * the dean; a withdrawal is the registrar's, a removed subject the dean's
 * placement.
 */
enum DropReason: string
{
    case Absences = 'absences';
    case Withdrawn = 'withdrawn';
    case Removed = 'removed';
}
