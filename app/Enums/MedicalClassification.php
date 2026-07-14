<?php

namespace App\Enums;

enum MedicalClassification: int
{
    case Trauma = 1;
    case Otolaryngology = 2;
    case Respiratory = 3;
    case Gastroenterology = 4;
    case Ophthalmology = 5;
    case Cardiology = 6;
    case Dermatology = 7;
    case Neurology = 8;
    case Endocrinology = 9;
    case Psychiatry = 10;
}
