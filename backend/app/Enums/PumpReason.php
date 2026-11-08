<?php

namespace App\Enums;

enum PumpReason: string
{
    case Manual = 'manual';
    case Task = 'task';
    case Rule = 'rule';
    case System = 'system';
}
