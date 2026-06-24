<?php

namespace App\Enums;

enum BloodType: int
{
    case APositive = 1;
    case ANegative = 2;
    case BPositive = 3;
    case BNegative = 4;
    case ABPositive = 5;
    case ABNegative = 6;
    case OPositive = 7;
    case ONegative = 8;
}
