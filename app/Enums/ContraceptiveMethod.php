<?php

namespace App\Enums;

enum ContraceptiveMethod: int
{
    case Condom = 1;
    case OralPills = 2;
    case IUD = 3;
    case Implant = 4;
    case Injection = 5;
    case Patch = 6;
    case Ring = 7;
    case BarrierMethods = 8;
    case Sterilization = 9;
    case Other = 10;
    case None = 11;
}
