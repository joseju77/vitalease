<?php

namespace App\Enums;

enum Permission: string
{
    case UsersManage = 'users.manage';
    case ReportsGenerate = 'reports.generate';
    case PatientsView = 'patients.view';
    case PatientsCreate = 'patients.create';
    case PatientsUpdate = 'patients.update';
    case PatientsDisable = 'patients.disable';
    case PatientsAudit = 'patients.audit';
    case ConsultationsView = 'consultations.view';
    case ConsultationsCreate = 'consultations.create';
    case ConsultationsUpdate = 'consultations.update';
    case ConsultationsVoid = 'consultations.void';
    case ConsultationsVoidAny = 'consultations.void-any';
    case ConsultationsViewVoided = 'consultations.view-voided';
    case ConsultationsAudit = 'consultations.audit';
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

    /**
     * Get the permissions this permission directly depends on.
     *
     * A permission is only meaningful when its direct dependencies are also
     * granted (e.g. creating a patient requires seeing patients). Use
     * {@see self::allRequirements()} for the transitive closure.
     *
     * @return list<self>
     */
    public function requires(): array
    {
        return match ($this) {
            self::PatientsCreate,
            self::PatientsUpdate,
            self::PatientsDisable,
            self::PatientsAudit,
            self::ConsultationsView => [self::PatientsView],
            self::ConsultationsCreate,
            self::ConsultationsUpdate,
            self::ConsultationsVoid,
            self::ConsultationsAudit,
            self::ConsultationsViewVoided => [self::ConsultationsView],
            self::ConsultationsVoidAny => [self::ConsultationsVoid],
            self::InventoryCreate,
            self::InventoryUpdate,
            self::InventoryDelete => [self::InventoryView],
            self::UsersManage,
            self::RolesManage,
            self::ReportsGenerate,
            self::PatientsView,
            self::InventoryView => [],
        };
    }

    /**
     * Get every permission this permission depends on, directly or
     * transitively, ordered from the nearest dependency outwards.
     *
     * @return list<self>
     */
    public function allRequirements(): array
    {
        $requirements = [];
        $pending = $this->requires();

        while ($pending !== []) {
            $requirement = array_shift($pending);

            if (in_array($requirement, $requirements, true)) {
                continue;
            }

            $requirements[] = $requirement;
            array_push($pending, ...$requirement->requires());
        }

        return $requirements;
    }

    /**
     * Get the direct dependency map of the whole catalog, keyed by
     * permission value, for clients that enforce it before submitting.
     *
     * @return array<string, list<string>>
     */
    public static function dependencyMap(): array
    {
        $map = [];

        foreach (self::cases() as $permission) {
            $map[$permission->value] = array_map(
                fn (self $requirement): string => $requirement->value,
                $permission->requires(),
            );
        }

        return $map;
    }

    /**
     * Find the dependencies a set of granted permissions leaves unsatisfied.
     *
     * Each granted permission is checked against its transitive
     * requirements, so `consultations.void-any` alone reports
     * `consultations.void`, `consultations.view` and `patients.view`.
     * The result is keyed by the granted permission's value; permissions
     * with no missing dependency are omitted.
     *
     * @param  array<int, self>  $granted
     * @return array<string, list<self>>
     */
    public static function missingDependencies(array $granted): array
    {
        $missingByPermission = [];

        foreach ($granted as $permission) {
            $missing = array_values(array_filter(
                $permission->allRequirements(),
                fn (self $requirement): bool => ! in_array($requirement, $granted, true),
            ));

            if ($missing !== []) {
                $missingByPermission[$permission->value] = $missing;
            }
        }

        return $missingByPermission;
    }

    /**
     * Get the human-readable Spanish label of the permission.
     */
    public function label(): string
    {
        return __("modules/roles/permissions.labels.{$this->value}");
    }
}
