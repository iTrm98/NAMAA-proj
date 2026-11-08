<?php

namespace App\Enums;

enum ParkType: string
{
    case PublicPark = 'public_park';
    case Median = 'median';
    case MosqueGarden = 'mosque_garden';
    case Other = 'other';
}
