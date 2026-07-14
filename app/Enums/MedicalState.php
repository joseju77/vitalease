<?php

namespace App\Enums;

enum MedicalState: int
{
    case Good = 1;
    case Fair = 2;
    case Serious = 3;
    case Critical = 4;
    case Guarded = 5;
}
