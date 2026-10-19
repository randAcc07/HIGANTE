<?php

namespace App\Enums;

enum RoomType: string
{
    case Lecture = 'lecture';
    case Laboratory = 'laboratory';

    /** Neither a lecture room nor a lab: the gym, the covered court, an AVR. */
    case Venue = 'venue';
}
