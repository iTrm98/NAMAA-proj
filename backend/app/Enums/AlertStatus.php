<?php

namespace App\Enums;

enum AlertStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
