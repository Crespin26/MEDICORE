<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Doctor = 'doctor';
    case Secretary = 'secretary';
    case Cashier = 'cashier';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur', self::Doctor => 'Médecin',
            self::Secretary => 'Secrétaire', self::Cashier => 'Caissier',
        };
    }

    public function permissions(): array
    {
        return match ($this) {
            self::Admin => ['dashboard.view', 'patients.view', 'patients.write', 'users.manage', 'appointments.view', 'appointments.write', 'appointments.status', 'availabilities.manage', 'billing.view', 'billing.manage', 'billing.pay', 'billing.reverse', 'catalog.manage', 'personnel.manage', 'personnel.self'],
            self::Doctor => ['dashboard.view', 'patients.view', 'appointments.view', 'appointments.status', 'availabilities.manage', 'consultations.view', 'consultations.write', 'medical-record.view', 'medical-record.write', 'personnel.self'],
            self::Secretary => ['dashboard.view', 'patients.view', 'patients.write', 'appointments.view', 'appointments.write', 'appointments.status', 'personnel.self'],
            self::Cashier => ['dashboard.view', 'billing.view', 'billing.manage', 'billing.pay', 'personnel.self'],
        };
    }
}
