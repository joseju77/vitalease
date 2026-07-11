<?php

namespace App\Enums;

enum KinshipType: int
{
    case Parent = 1;
    case Sibling = 2;
    case Spouse = 3;
    case Child = 4;
    case Friend = 5;
    case Other = 6;
}
