<?php

namespace App\Enums;

enum InventoryMovementType: int
{
    case Entry = 1;
    case Dispensation = 2;
    case Adjustment = 3;
    case DispensationReversal = 4;
}
