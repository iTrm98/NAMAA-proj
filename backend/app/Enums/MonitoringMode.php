<?php

namespace App\Enums;

enum MonitoringMode: string
{
    case Simulated = 'simulated';
    case RealSensors = 'real_sensors';
    case SatelliteOnly = 'satellite_only';
}
