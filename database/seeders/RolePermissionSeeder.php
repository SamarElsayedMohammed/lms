<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        /**** Create All the Roles ****/
        $this->createRoles();

        /**** Create All the Permission ****/
        $this->createPermissions();

        /**** Assign Permissions to Roles ****/
        $this->assignPermissionsToAdminRole();
        $this->assignPermissionsToInstructorRole();
        $this->assignPermissionsToSupervisorRole();
        $this->assignPermissionsToSalesRole();
        $this->assignPermissionsToAccountantRole();
        $this->assignPermissionsToModeratorRole();
        $this->assignPermissionsToStaffRole();
    }

    // Create Roles
    public function createRoles()
    {
        \App\Support\RoleManager::ensureCanonicalRolesExist();
    }

    // Create Permissions
    public function createPermissions()
    {
        // Create Permissions based on actual admin panel operations (avoiding duplicates)
        $permissions = [
            // Dashboard
            ...self::permission('dashboard'),
            // Course Management
            ...self::permission('courses', ['approve', 'reject', 'requests', 'restore', 'trash']),
            ...self::permission('course-chapters'),
            ...self::permission('course-languages', ['restore', 'trash']),
            ...self::permission('course-tags'),
            // Content Management
            ...self::permission('categories', ['restore', 'trash', 'reorder', 'subcategories']),
            ...self::permission('custom-form-fields'),
            ...self::permission('faqs', ['restore', 'trash']),
            ...self::permission('pages'),
            ...self::permission('taxes'),
            ...self::permission('promo-codes'),
            ...self::permission('subscription-plans', ['restore', 'trash', 'toggle']),
            ...self::permission('countries', ['toggle']),
            ...self::permission('certificates'),
            // User Management
            ...self::permission('instructors', ['show-form', 'status-update']),
            ...self::permission('users'),
            ...self::permission('wallets'),
            ...self::permission('withdrawals', ['process']),
            ...self::permission('staff', ['change-password']),
            ...self::permission('roles'),
            // Communication & Notifications
            ...self::permission('notifications'),
            // Orders & Enrollments
            ...self::permission('orders'),
            ...self::permission('enrollments'),
            // Refund Management
            ...self::permission('refunds', ['process']),
            // Assignments
            ...self::permission('assignments', ['review']),
            // Ratings
            ...self::permission('ratings'),
            // Progress Tracking
            ...self::permission('tracking'),
            // Home Screen Management
            ...self::permission('sliders'),
            ...self::permission('feature-sections'),
            // Reports (Specific report types)
            ...self::permission('reports-sales', ['export']),
            ...self::permission('reports-commission', ['export']),
            ...self::permission('reports-course', ['export']),
            ...self::permission('reports-instructor'),
            ...self::permission('reports-enrollment'),
            ...self::permission('reports-revenue'),
            // Settings (Specific setting types)
            ...self::permission('settings-system'),
            ...self::permission('settings-firebase'),
            ...self::permission('settings-refund'),
            ...self::permission('settings-instructor-terms'),
            ...self::permission('settings-app'),
            ...self::permission('settings-payment-gateway'),
            ...self::permission('settings-language'),
            ...self::permission('settings-hls'),
            // Help Desk
            ...self::permission('helpdesk-groups', ['update-rank']),
            ...self::permission('helpdesk-group-requests'),
            ...self::permission('helpdesk-questions'),
            ...self::permission('helpdesk-replies'),
            // Contact Messages
            ...self::permission('contact-messages'),
            // System Operations
            ...self::permission('common', ['change-status']),
            ...self::permission('webhooks'),
            // Supervisor granular permissions (US10)
            ['name' => 'manage_accounts'],
            ['name' => 'manage_courses'],
            ['name' => 'upload_courses'],
            ['name' => 'manage_subscriptions'],
            ['name' => 'manage_finances'],
            ['name' => 'approve_comments'],
            ['name' => 'approve_ratings'],
            ['name' => 'manage_affiliates'],
            ['name' => 'manage_settings'],
            ['name' => 'manage_plans'],
            ['name' => 'view_reports'],
            // Marketing (Popup Campaigns, Promo Campaigns, etc.)
            ...self::permission('marketing'),
            // Finance (Wallets, Payouts, Affiliate Finance, etc.)
            ...self::permission('finance'),
            // Feature Flags
            ...self::permission('feature-flags'),
        ];

        // Remove duplicates by name
        $uniquePermissions = [];
        $seenNames = [];

        foreach ($permissions as $permission) {
            if (in_array($permission['name'], $seenNames)) {
                continue;
            }

            $uniquePermissions[] = $permission;
            $seenNames[] = $permission['name'];
        }

        $permissions = $uniquePermissions;

        // Set Guard Name
        $permissions = array_map(static function ($data) {
            $data['guard_name'] = 'web';
            return $data;
        }, $permissions);

        Permission::upsert($permissions, ['name', 'guard_name'], ['name']); // Upsert Permissions
    }

    // Assign Permissions to Roles
    public function assignPermissionsToAdminRole()
    {
        $adminRole = Role::where('name', config('constants.SYSTEM_ROLES.SUPER_ADMIN'))->first(); // Get Super Admin Role

        // Admin Has Access To Everything - Based on Actual Admin Panel Operations
        $adminHasAccessTo = [
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
            'pages-list',
            'pages-create',
            'pages-edit',
            'pages-delete',
            'taxes-list',
            'taxes-create',
            'taxes-edit',
            'taxes-delete',
            'promo-codes-list',
            'promo-codes-create',
            'promo-codes-edit',
            'promo-codes-delete',
            'subscription-plans-list',
            'subscription-plans-create',
            'subscription-plans-edit',
            'subscription-plans-delete',
            'subscription-plans-restore',
            'subscription-plans-trash',
            'subscription-plans-toggle',
            'countries-list',
            'countries-create',
            'countries-edit',
            'countries-delete',
            'countries-toggle',
            'certificates-list',
            'certificates-create',
            'certificates-edit',
            'certificates-delete',
            // User Management
            'instructors-list',
            'instructors-create',
            'instructors-edit',
            'instructors-delete',
            'instructors-show-form',
            'instructors-status-update',
            'users-list',
            'users-create',
            'users-edit',
            'users-delete',
            'wallets-list',
            'wallets-create',
            'wallets-edit',
            'wallets-delete',
            'withdrawals-list',
            'withdrawals-create',
            'withdrawals-edit',
            'withdrawals-delete',
            'withdrawals-process',
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
            // Orders & Enrollments
            'orders-list',
            'orders-create',
            'orders-edit',
            'orders-delete',
            'enrollments-list',
            'enrollments-create',
            'enrollments-edit',
            'enrollments-delete',
            // Refund Management
            'refunds-list',
            'refunds-create',
            'refunds-edit',
            'refunds-delete',
            'refunds-process',
            // Assignments
            'assignments-list',
            'assignments-create',
            'assignments-edit',
            'assignments-delete',
            'assignments-review',
            // Ratings
            'ratings-list',
            'ratings-create',
            'ratings-edit',
            'ratings-delete',
            // Progress Tracking
            'tracking-list',
            'tracking-create',
            'tracking-edit',
            'tracking-delete',
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
            'helpdesk-group-requests-list',
            'helpdesk-group-requests-create',
            'helpdesk-group-requests-edit',
            'helpdesk-group-requests-delete',
            'helpdesk-questions-list',
            'helpdesk-questions-create',
            'helpdesk-questions-edit',
            'helpdesk-questions-delete',
            'helpdesk-replies-list',
            'helpdesk-replies-create',
            'helpdesk-replies-edit',
            'helpdesk-replies-delete',
            // Contact Messages
            'contact-messages-list',
            'contact-messages-create',
            'contact-messages-edit',
            'contact-messages-delete',
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
            // Supervisor granular permissions (Admin has all)
            'manage_accounts',
            'manage_courses',
            'upload_courses',
            'manage_subscriptions',
            'manage_finances',
            'approve_comments',
            'approve_ratings',
            'manage_affiliates',
            'manage_settings',
            'manage_plans',
            'view_reports',
            // Marketing (Popup Campaigns, Promo Campaigns, etc.)
            'marketing-list',
            'marketing-create',
            'marketing-edit',
            'marketing-delete',
            // Finance (Wallets, Payouts, Affiliate Finance, etc.)
            'finance-list',
            'finance-create',
            'finance-edit',
            'finance-delete',
            // Feature Flags
            'feature-flags-list',
            'feature-flags-create',
            'feature-flags-edit',
            'feature-flags-delete',
        ];

        $adminRole->syncPermissions($adminHasAccessTo); // Assign Permissions to Super Admin Role
    }

    public function assignPermissionsToInstructorRole()
    {
        $instructorRole = Role::where('name', config('constants.SYSTEM_ROLES.INSTRUCTOR'))->first();

        $permissions = [
            // Dashboard (Limited)
            'dashboard-list',
            // Course Management (Full Access to own courses)
            'courses-list',
            'courses-create',
            'courses-edit',
            'courses-delete',
            'course-chapters-list',
            'course-chapters-create',
            'course-chapters-edit',
            'course-chapters-delete',
            'course-languages-list', // View only
            'course-tags-list',
            'course-tags-create',
            'course-tags-edit',
            'course-tags-delete',
            // Content Management (Limited)
            'categories-list', // View only
            'taxes-list', // View only
            // Communication & Notifications
            'notifications-list',
            'notifications-create',
            // Reports (Limited to their own data)
            'reports-course-list', // Their own course reports
            'reports-instructor-list', // Their own instructor reports
            'reports-enrollment-list', // Their own enrollment reports
            'reports-revenue-list', // Their own revenue reports
        ];

        $instructorRole->givePermissionTo($permissions);
    }

    /**
     * Assign granular permissions to Supervisor role (US10)
     */
    public function assignPermissionsToSupervisorRole(): void
    {
        $supervisorRole = Role::where('name', config('constants.SYSTEM_ROLES.SUPERVISOR'))->first();

        if (!$supervisorRole) {
            return;
        }

        $supervisorPermissions = [
            'manage_accounts',
            'manage_courses',
            'upload_courses',
            'manage_subscriptions',
            'manage_finances',
            'approve_comments',
            'approve_ratings',
            'manage_affiliates',
            'manage_settings',
            'manage_plans',
            'view_reports',
        ];

        $supervisorRole->syncPermissions($supervisorPermissions);
    }

    /**
     * Assign permissions to Sales role
     */
    public function assignPermissionsToSalesRole(): void
    {
        $salesRole = Role::where('name', config('constants.SYSTEM_ROLES.SALES'))->first();
        if (!$salesRole) return;

        $permissions = [
            'dashboard-list',
            'promo-codes-list', 'promo-codes-create', 'promo-codes-edit', 'promo-codes-delete',
            'sliders-list', 'sliders-create', 'sliders-edit', 'sliders-delete',
            'feature-sections-list', 'feature-sections-create', 'feature-sections-edit', 'feature-sections-delete',
            'categories-list', 'categories-create', 'categories-edit',
            'reports-sales-list', 'reports-sales-export',
            'reports-revenue-list',
            'settings-app-list', 'settings-app-edit',
        ];

        $salesRole->syncPermissions($permissions);
    }

    /**
     * Assign permissions to Accountant role
     */
    public function assignPermissionsToAccountantRole(): void
    {
        $accountantRole = Role::where('name', config('constants.SYSTEM_ROLES.ACCOUNTANT'))->first();
        if (!$accountantRole) return;

        $permissions = [
            'dashboard-list',
            'wallets-list', 'wallets-create', 'wallets-edit',
            'withdrawals-list', 'withdrawals-process',
            'refunds-list', 'refunds-process',
            'reports-sales-list', 'reports-sales-export',
            'reports-commission-list', 'reports-commission-export',
            'reports-revenue-list',
            'reports-enrollment-list',
            'taxes-list', 'taxes-create', 'taxes-edit',
        ];

        $accountantRole->syncPermissions($permissions);
    }

    /**
     * Assign permissions to Moderator role
     */
    public function assignPermissionsToModeratorRole(): void
    {
        $moderatorRole = Role::where('name', config('constants.SYSTEM_ROLES.MODERATOR'))->first();
        if (!$moderatorRole) return;

        $permissions = [
            'dashboard-list',
            'users-list', 'users-edit', 'users-delete',
            'instructors-list', 'instructors-edit', 'instructors-show-form', 'instructors-status-update',
            'helpdesk-groups-list', 'helpdesk-groups-create', 'helpdesk-groups-edit',
            'helpdesk-group-requests-list', 'helpdesk-questions-list', 'helpdesk-replies-list',
            'contact-messages-list', 'contact-messages-delete',
            'ratings-list', 'ratings-edit', 'ratings-delete',
            'faqs-list', 'faqs-create', 'faqs-edit', 'faqs-delete',
            'pages-list', 'pages-edit',
        ];

        $moderatorRole->syncPermissions($permissions);
    }

    /**
     * Assign permissions to Staff role
     * Staff has access to core management areas but not financial/settings
     */
    public function assignPermissionsToStaffRole(): void
    {
        $staffRole = Role::where('name', config('constants.SYSTEM_ROLES.STAFF'))->first();
        if (!$staffRole) return;

        $permissions = [
            'dashboard-list',
            // Course Management
            'courses-list', 'courses-create', 'courses-edit', 'courses-delete',
            'courses-approve', 'courses-reject', 'courses-requests',
            'course-chapters-list', 'course-chapters-create', 'course-chapters-edit', 'course-chapters-delete',
            'course-languages-list', 'course-tags-list',
            // Content
            'categories-list', 'categories-create', 'categories-edit',
            'faqs-list', 'faqs-create', 'faqs-edit', 'faqs-delete',
            'pages-list', 'pages-create', 'pages-edit',
            // User Management
            'users-list', 'users-create', 'users-edit',
            'instructors-list', 'instructors-edit', 'instructors-show-form', 'instructors-status-update',
            'staff-list',
            // Orders & Enrollments
            'orders-list', 'enrollments-list', 'enrollments-create',
            // Communication
            'notifications-list', 'notifications-create',
            // Assignments & Ratings
            'assignments-list', 'assignments-review',
            'ratings-list', 'ratings-edit',
            // Reports (read-only)
            'reports-course-list', 'reports-enrollment-list',
            'reports-instructor-list',
            // Help Desk
            'helpdesk-groups-list', 'helpdesk-group-requests-list',
            'helpdesk-questions-list', 'helpdesk-replies-list',
            'contact-messages-list',
        ];

        $staffRole->syncPermissions($permissions);
    }

    /**
     * Generate List , Create , Edit , Delete Permissions
     * @param $prefix
     * @param array $customPermissions - Prefix will be set Automatically
     * @return string[]
     */
    private function permission($prefix, array $customPermissions = [])
    {
        $list = [['name' => $prefix . '-list']]; // Create List Permission
        $create = [['name' => $prefix . '-create']]; // Create Create Permission
        $edit = [['name' => $prefix . '-edit']]; // Create Edit Permission
        $delete = [['name' => $prefix . '-delete']]; // Create Delete Permission

        $finalArray = array_merge($list, $create, $edit, $delete); // Merge All Permissions

        // Merge Custom Permissions
        foreach ($customPermissions as $customPermission) {
            $finalArray[] = ['name' => $prefix . '-' . $customPermission];
        }

        return $finalArray; // Return Final Array
    }
}
