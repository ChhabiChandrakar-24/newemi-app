<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'users.view', 'users.create', 'users.update', 'users.delete', 'users.restore',
        'roles.view', 'roles.create', 'roles.update', 'roles.delete',
        'customers.view', 'customers.create', 'customers.update', 'customers.delete', 'customers.restore',
        'emi.view', 'emi.create', 'emi.update', 'emi.close',
        'payments.view', 'payments.create', 'payments.update', 'payments.verify',
        'payments.reverse', 'payments.settlement', 'payments.discount', 'payment_methods.manage',
        'payment_gateways.view', 'payment_gateways.create', 'payment_gateways.update', 'payment_gateways.test', 'payment_gateways.delete',
        'devices.view', 'devices.create', 'devices.update', 'devices.enroll',
        'devices.events.view', 'devices.events.manage', 'devices.warning',
        'devices.partial_lock', 'devices.full_lock', 'devices.unlock', 'devices.release',
        'devices.re-enroll',
        'devices.commands.view', 'devices.commands.cancel', 'lock_policies.view', 'lock_policies.manage',
        'devices.location.view', 'devices.location.manage',
        'consents.view', 'notifications.view', 'releases.view',
        'retention.view', 'retention.manage', 'emi.complete',
        'reports.view', 'reports.export', 'alerts.view', 'alerts.manage',
        'reports.schedule', 'reports.financial', 'reports.devices', 'reports.audit', 'reports.staff', 'reports.location',
        'audit.view', 'settings.view', 'settings.update',
        'settings.integrations.view', 'settings.integrations.update', 'settings.security.manage',
        'crm.leads.view', 'crm.leads.manage', 'crm.visits.view', 'crm.visits.manage',
        'crm.projects.view', 'crm.projects.manage', 'crm.messages.view', 'crm.messages.manage',
    ];

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $legacyAdmin = Role::query()->where('name', 'admin-owner')->where('guard_name', 'web')->first();
        if ($legacyAdmin && ! Role::query()->where('name', 'admin')->where('guard_name', 'web')->exists()) {
            $legacyAdmin->update(['name' => 'admin']);
        }

        collect(self::PERMISSIONS)->each(
            fn (string $permission) => Permission::findOrCreate($permission, 'web'),
        );

        $all = collect(self::PERMISSIONS);
        Role::findOrCreate('super-admin', 'web')->forceFill(['is_system' => true])->save();
        Role::findOrCreate('super-admin', 'web')->syncPermissions($all);
        Role::findOrCreate('admin', 'web')->forceFill(['is_system' => true])->save();
        Role::findOrCreate('admin', 'web')->syncPermissions($all);
        Role::findOrCreate('manager', 'web')->forceFill(['is_system' => true])->save();
        Role::findOrCreate('manager', 'web')->syncPermissions([
            'users.view', 'users.create', 'users.update', 'roles.view',
            'customers.view', 'customers.create', 'customers.update', 'customers.delete',
            'emi.view', 'emi.create', 'emi.update', 'emi.close',
            'payments.view', 'payments.create', 'payments.update', 'payments.verify',
            'payments.reverse', 'payments.settlement',
            'devices.view', 'devices.create', 'devices.update', 'devices.enroll',
            'devices.events.view', 'devices.events.manage', 'devices.release', 'devices.warning',
            'devices.re-enroll',
            'devices.partial_lock', 'devices.unlock', 'reports.view', 'reports.export',
            'devices.commands.view', 'devices.commands.cancel', 'lock_policies.view', 'lock_policies.manage',
            'devices.location.view', 'devices.location.manage',
            'consents.view', 'notifications.view', 'releases.view', 'retention.view',
            'alerts.view', 'alerts.manage', 'audit.view', 'settings.view',
            'crm.leads.view', 'crm.leads.manage', 'crm.visits.view', 'crm.visits.manage',
            'crm.projects.view', 'crm.projects.manage', 'crm.messages.view', 'crm.messages.manage',
        ]);
        Role::findOrCreate('staff', 'web')->forceFill(['is_system' => true])->save();
        Role::findOrCreate('staff', 'web')->syncPermissions([
            'customers.view', 'customers.create', 'customers.update',
            'emi.view', 'emi.create', 'emi.update',
            'payments.view', 'payments.create',
            'devices.view', 'devices.create', 'devices.update', 'devices.events.view',
            'devices.warning', 'devices.commands.view',
            'reports.view', 'alerts.view', 'notifications.view',
        ]);
        Role::findOrCreate('auditor', 'web')->forceFill(['is_system' => true])->save();
        Role::findOrCreate('auditor', 'web')->syncPermissions([
            'users.view', 'roles.view', 'customers.view', 'emi.view', 'payments.view',
            'devices.view', 'devices.events.view', 'devices.commands.view', 'lock_policies.view',
            'reports.view', 'alerts.view', 'audit.view', 'settings.view',
            'consents.view', 'notifications.view', 'releases.view', 'retention.view',
        ]);
        Role::findOrCreate('sales-person', 'web')->forceFill(['is_system' => true])->save();
        Role::findOrCreate('sales-person', 'web')->syncPermissions([
            'customers.view', 'customers.create', 'customers.update',
            'emi.view', 'emi.create', 'emi.update',
            'payments.view', 'payments.create',
            'devices.view', 'devices.create', 'devices.update', 'devices.events.view',
            'devices.enroll', 'devices.commands.view', 'devices.location.view',
            'crm.leads.view', 'crm.leads.manage', 'crm.visits.view', 'crm.visits.manage',
            'crm.projects.view', 'crm.projects.manage', 'reports.view',
        ]);

        Permission::query()->where('guard_name', 'web')->whereNotIn('name', self::PERMISSIONS)->delete();
        $registrar->forgetCachedPermissions();
    }
}
