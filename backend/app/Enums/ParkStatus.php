<?php

namespace App\Enums;

enum ParkStatus: string
{
    case Ok = 'ok';
    case Dry = 'dry';
    case Over = 'over';
    case Offline = 'offline';
    /** Sprint 6: every sensor node is real and has never reported (not installed yet). */
    case AwaitingInstall = 'awaiting_install';
    /** Sprint 6.2: monitoring mode satellite_only, no sensor feed. */
    case NoSensor = 'no_sensor';
}
