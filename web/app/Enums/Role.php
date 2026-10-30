<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Doctor = 'doctor';
    case Secretary = 'secretary';
    case Cashier = 'cashier';

    /** Renvoie le libellé français du rôle pour les interfaces. */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur', self::Doctor => 'Médecin',
            self::Secretary => 'Secrétaire', self::Cashier => 'Caissier',
        };
    }

    /** Liste les permissions connues à partir des rôles pour enregistrer les Gates et les props web. */
    public static function permissionNames(): array
    {
        return array_values(array_unique(array_merge(...array_map(fn (self $role) => $role->permissions(), self::cases()))));
    }

    /** Définit les capacités du rôle ; les politiques ajoutent les contrôles par dossier et par auteur. */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => ['dashboard.view', 'patients.view', 'patients.write', 'users.manage', 'appointments.view', 'appointments.write', 'appointments.status', 'availabilities.manage', 'billing.view', 'billing.manage', 'billing.pay', 'billing.reverse', 'catalog.manage', 'personnel.manage', 'personnel.self', 'administration.manage', 'administration.export'],
            self::Doctor => ['dashboard.view', 'patients.view', 'appointments.view', 'appointments.status', 'availabilities.manage', 'consultations.view', 'consultations.write', 'medical-record.view', 'medical-record.write', 'personnel.self'],
            self::Secretary => ['dashboard.view', 'patients.view', 'patients.write', 'appointments.view', 'appointments.write', 'appointments.status', 'personnel.self'],
            self::Cashier => ['dashboard.view', 'billing.view', 'billing.manage', 'billing.pay', 'personnel.self'],
        };
    }
}
