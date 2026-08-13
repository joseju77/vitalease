<?php

namespace App\Enums;

enum Permission: string
{
    case UsersManage = 'users.manage';
    case ReportsGenerate = 'reports.generate';
    case PatientsView = 'patients.view';
    case PatientsCreate = 'patients.create';
    case PatientsUpdate = 'patients.update';
    case PatientsDelete = 'patients.delete';
    case ConsultationsView = 'consultations.view';
    case ConsultationsCreate = 'consultations.create';
    case ConsultationsUpdate = 'consultations.update';
    case ConsultationsDelete = 'consultations.delete';
    case RolesManage = 'roles.manage';
    case InventoryView = 'inventory.view';
    case InventoryCreate = 'inventory.create';
    case InventoryUpdate = 'inventory.update';
    case InventoryDelete = 'inventory.delete';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
