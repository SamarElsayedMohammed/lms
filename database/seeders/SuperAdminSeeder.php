<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Creates a Super Admin user with credentials:
     * Email: superadmin@elms.com
     * Password: Super@Admin#2024!ELMS
     * Assigns all permissions to Super Admin role
     */
    public function run(): void
    {
        // Ensure Super Admin role exists
        $role = Role::updateOrCreate(['name' => 'Super Admin'], ['guard_name' => 'web', 'custom_role' => false]);

        // Assign ALL permissions to Super Admin role
        $allPermissions = Permission::where('guard_name', 'web')->pluck('name')->toArray();

        if (!empty($allPermissions)) {
            $role->syncPermissions($allPermissions);
            $this->command->info('Assigned ' . count($allPermissions) . ' permissions to Super Admin role.');
        } else {
            // If no permissions exist, assign all permissions from RolePermissionSeeder
            $this->assignAllPermissionsToSuperAdmin($role);
        }

        // Complex password: Super@Admin#2024!ELMS
        $password = 'Super@Admin#2024!ELMS';

        // Create or update Super Admin user
        $user = User::updateOrCreate(['email' => 'superadmin@elms.com'], [
            'name' => 'Super Admin',
            'slug' => 'super-admin',
            'email' => 'superadmin@elms.com',
            'password' => Hash::make($password),
            'is_active' => 1,
            'type' => 'email',
        ]);

        // Assign Super Admin role
        $user->syncRoles('Super Admin');

        $this->command->info('Super Admin user created successfully!');
        $this->command->info('Email: superadmin@elms.com');
        $this->command->info('Password: Super@Admin#2024!ELMS');
        $this->command->info('All permissions have been assigned to Super Admin role.');
        $this->command->warn('Please save these credentials securely!');
    }

    /**
     * Assign all permissions to Super Admin role (same as Admin role)
     */
    private function assignAllPermissionsToSuperAdmin($role)
    {
        // Get all permissions that Admin role has (from RolePermissionSeeder)
        $allPermissions = [
            // Dashboard
            'dashboard-list',
            'dashboard-create',
            'dashboard-edit',
            'dashboard-delete',
            // Course Management
            'courses-list',
            'courses-create',
            'courses-edit',
            'courses-delete',
            'courses-approve',
            'courses-reject',
            'courses-requests',
            'courses-restore',
            'courses-trash',
            'course-chapters-list',
            'course-chapters-create',
            'course-chapters-edit',
            'course-chapters-delete',
            'course-languages-list',
            'course-languages-create',
            'course-languages-edit',
            'course-languages-delete',
            'course-languages-restore',
            'course-languages-trash',
            'course-tags-list',
            'course-tags-create',
            'course-tags-edit',
            'course-tags-delete',
            // Content Management
            'categories-list',
            'categories-create',
            'categories-edit',
            'categories-delete',
            'categories-restore',
            'categories-trash',
            'categories-reorder',
            'categories-subcategories',
            'custom-form-fields-list',
            'custom-form-fields-create',
            'custom-form-fields-edit',
            'custom-form-fields-delete',
            'faqs-list',
            'faqs-create',
            'faqs-edit',
            'faqs-delete',
            'faqs-restore',
            'faqs-trash',
            'taxes-list',
            'taxes-create',
            'taxes-edit',
            'taxes-delete',
            'promo-codes-list',
            'promo-codes-create',
            'promo-codes-edit',
            'promo-codes-delete',
            // User Management
            'instructors-list',
            'instructors-create',
            'instructors-edit',
            'instructors-delete',
            'instructors-show-form',
            'instructors-status-update',
            'staff-list',
            'staff-create',
            'staff-edit',
            'staff-delete',
            'staff-change-password',
            'roles-list',
            'roles-create',
            'roles-edit',
            'roles-delete',
            // Communication & Notifications
            'notifications-list',
            'notifications-create',
            'notifications-edit',
            'notifications-delete',
            // Refund Management
            'refunds-list',
            'refunds-create',
            'refunds-edit',
            'refunds-delete',
            'refunds-process',
            // Home Screen Management
            'sliders-list',
            'sliders-create',
            'sliders-edit',
            'sliders-delete',
            'feature-sections-list',
            'feature-sections-create',
            'feature-sections-edit',
            'feature-sections-delete',
            // Reports
            'reports-sales-list',
            'reports-sales-create',
            'reports-sales-edit',
            'reports-sales-delete',
            'reports-sales-export',
            'reports-commission-list',
            'reports-commission-create',
            'reports-commission-edit',
            'reports-commission-delete',
            'reports-commission-export',
            'reports-course-list',
            'reports-course-create',
            'reports-course-edit',
            'reports-course-delete',
            'reports-course-export',
            'reports-instructor-list',
            'reports-instructor-create',
            'reports-instructor-edit',
            'reports-instructor-delete',
            'reports-enrollment-list',
            'reports-enrollment-create',
            'reports-enrollment-edit',
            'reports-enrollment-delete',
            'reports-revenue-list',
            'reports-revenue-create',
            'reports-revenue-edit',
            'reports-revenue-delete',
            // Settings
            'settings-system-list',
            'settings-system-create',
            'settings-system-edit',
            'settings-system-delete',
            'settings-firebase-list',
            'settings-firebase-create',
            'settings-firebase-edit',
            'settings-firebase-delete',
            'settings-refund-list',
            'settings-refund-create',
            'settings-refund-edit',
            'settings-refund-delete',
            'settings-instructor-terms-list',
            'settings-instructor-terms-create',
            'settings-instructor-terms-edit',
            'settings-instructor-terms-delete',
            'settings-app-list',
            'settings-app-create',
            'settings-app-edit',
            'settings-app-delete',
            'settings-payment-gateway-list',
            'settings-payment-gateway-create',
            'settings-payment-gateway-edit',
            'settings-payment-gateway-delete',
            'settings-language-list',
            'settings-language-create',
            'settings-language-edit',
            'settings-language-delete',
            'settings-hls-list',
            'settings-hls-create',
            'settings-hls-edit',
            'settings-hls-delete',
            // Help Desk
            'helpdesk-groups-list',
            'helpdesk-groups-create',
            'helpdesk-groups-edit',
            'helpdesk-groups-delete',
            'helpdesk-groups-update-rank',
            // System Operations
            'common-list',
            'common-create',
            'common-edit',
            'common-delete',
            'common-change-status',
            'webhooks-list',
            'webhooks-create',
            'webhooks-edit',
            'webhooks-delete',
        ];

        // Only assign permissions that exist in database
        $existingPermissions = Permission::whereIn('name', $allPermissions)
            ->where('guard_name', 'web')
            ->pluck('name')
            ->toArray();

        if (!empty($existingPermissions)) {
            $role->syncPermissions($existingPermissions);
            $this->command->info('Assigned ' . count($existingPermissions) . ' permissions to Super Admin role.');
        } else {
            // If permissions don't exist yet, create them first
            $this->command->warn(
                'Permissions not found. Please run RolePermissionSeeder first: php artisan db:seed --class=RolePermissionSeeder',
            );
        }
    }
}
