<?php

namespace App\Enums;

enum ParkStatus: string
{
    case Ok = 'ok';
    case Dry = 'dry';
    case Over = 'over';
    case Offline = 'offline';
}
