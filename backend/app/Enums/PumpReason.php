<?php

namespace App\Enums;

/** Why pump_target changed (pump_logs.reason). */
enum PumpReason: string
{
    case Manual = 'manual';   // an admin, or the worker on their task
    case Task = 'task';       // legacy value, kept for old rows (workers now log "manual")
    case Rule = 'rule';       // automatic irrigation
    case System = 'system';   // demo scenario reset
    case Test = 'test';       // relay test on the hardware page (10 s)
    case Safety = 'safety';   // safety cut-off (max run time) or the no_flow rule
}
