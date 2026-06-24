<?php

namespace App\Enums;

enum EnrollmentSegment: int
{
    case Student = 1;
    case Academic = 2;
    case Administrative = 3;
    case Operational = 4;
}
