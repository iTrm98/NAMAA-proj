<?php

namespace App\Enums;

enum AlertType: string
{
    case Dry = 'dry';
    case Over = 'over';
    case NoFlow = 'no_flow';
    case Leak = 'leak';
    case Offline = 'offline';
    case SensorFault = 'sensor_fault';
    case Litter = 'litter';
    case NdviDrop = 'ndvi_drop';
}
