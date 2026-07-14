<?php

namespace App\Enums;

enum TransferType: int
{
    case Institute = 1;
    case MunicipalHealthServices = 2;
    case OwnResources = 3;
}
