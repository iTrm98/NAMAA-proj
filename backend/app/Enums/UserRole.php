<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Worker = 'worker';
    case Municipality = 'municipality';
}
