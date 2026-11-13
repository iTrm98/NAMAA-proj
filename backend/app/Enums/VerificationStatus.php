<?php

namespace App\Enums;

enum VerificationStatus: string
{
    case Verified = 'verified';
    case Unverified = 'unverified';
}
