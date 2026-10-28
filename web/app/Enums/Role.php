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
            self::Admin => ['dashboard.view', 'patients.view', 'patients.write', 'users.manage'],
            self::Doctor => ['dashboard.view', 'patients.view'],
            self::Secretary => ['dashboard.view', 'patients.view', 'patients.write'],
            self::Cashier => ['dashboard.view'],
        };
    }
}
