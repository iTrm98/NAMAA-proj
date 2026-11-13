<?php

namespace App\Enums;

enum GeometrySource: string
{
    case Osm = 'osm';
    case Traced = 'traced';
    case RoughLegacy = 'rough_legacy';
}
