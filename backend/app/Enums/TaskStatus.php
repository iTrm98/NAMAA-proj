<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Done = 'done';
}
