<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

final class CleanupInvalidPermissions extends Command
{
    protected $signature = 'permissions:cleanup {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Remove invalid/old permissions that are not defined in the seeder';

    /**
     * Valid permissions from RolePermissionSeeder
     *
     * @return array<string>
     */
    private function getValidPermissions(): array
    {
        $basePermissions = ['list', 'create', 'edit', 'delete'];

        $permissionGroups = [
            'dashboard' => $basePermissions,
            'courses' => [...$basePermissions, 'approve', 'reject', 'requests', 'restore', 'trash'],
            'course-chapters' => $basePermissions,
            'course-languages' => [...$basePermissions, 'restore', 'trash'],
            'course-tags' => $basePermissions,
            'categories' => [...$basePermissions, 'restore', 'trash', 'reorder', 'subcategories'],
            'custom-form-fields' => $basePermissions,
            'faqs' => [...$basePermissions, 'restore', 'trash'],
            'pages' => $basePermissions,
            'taxes' => $basePermissions,
            'promo-codes' => $basePermissions,
            'certificates' => $basePermissions,
            'instructors' => [...$basePermissions, 'show-form', 'status-update'],
            'users' => $basePermissions,
            'wallets' => $basePermissions,
            'withdrawals' => [...$basePermissions, 'process'],
            'staff' => [...$basePermissions, 'change-password'],
            'roles' => $basePermissions,
            'notifications' => $basePermissions,
            'orders' => $basePermissions,
            'enrollments' => $basePermissions,
            'refunds' => [...$basePermissions, 'process'],
            'assignments' => [...$basePermissions, 'review'],
            'ratings' => $basePermissions,
            'tracking' => $basePermissions,
            'sliders' => $basePermissions,
            'feature-sections' => $basePermissions,
            'reports-sales' => [...$basePermissions, 'export'],
            'reports-commission' => [...$basePermissions, 'export'],
            'reports-course' => [...$basePermissions, 'export'],
            'reports-instructor' => $basePermissions,
            'reports-enrollment' => $basePermissions,
            'reports-revenue' => $basePermissions,
            'settings-system' => $basePermissions,
            'settings-firebase' => $basePermissions,
            'settings-refund' => $basePermissions,
            'settings-instructor-terms' => $basePermissions,
            'settings-app' => $basePermissions,
            'settings-payment-gateway' => $basePermissions,
            'settings-language' => $basePermissions,
            'settings-hls' => $basePermissions,
            'helpdesk-groups' => [...$basePermissions, 'update-rank'],
            'helpdesk-group-requests' => $basePermissions,
            'helpdesk-questions' => $basePermissions,
            'helpdesk-replies' => $basePermissions,
            'contact-messages' => $basePermissions,
            'common' => [...$basePermissions, 'change-status'],
            'webhooks' => $basePermissions,
        ];

        $validPermissions = [];
        foreach ($permissionGroups as $group => $actions) {
            foreach ($actions as $action) {
                $validPermissions[] = "{$group}-{$action}";
            }
        }

        return $validPermissions;
    }

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        $validPermissions = $this->getValidPermissions();
        $this->info('Valid permissions count: ' . count($validPermissions));

        // Get all permissions currently in database
        $dbPermissions = Permission::pluck('name', 'id')->toArray();
        $this->info('Database permissions count: ' . count($dbPermissions));

        // Find invalid permissions
        $invalidPermissions = [];
        foreach ($dbPermissions as $id => $name) {
            if (!in_array($name, $validPermissions, true)) {
                $invalidPermissions[$id] = $name;
            }
        }

        if (empty($invalidPermissions)) {
            $this->info('No invalid permissions found. Database is clean!');
            return Command::SUCCESS;
        }

        $this->warn('Found ' . count($invalidPermissions) . ' invalid permissions:');
        $this->newLine();

        // Group invalid permissions for display
        $grouped = [];
        foreach ($invalidPermissions as $name) {
            $lastDash = strrpos($name, '-');
            $prefix = $lastDash !== false ? substr($name, 0, $lastDash) : 'other';
            $grouped[$prefix][] = $name;
        }
        ksort($grouped);

        foreach ($grouped as $prefix => $perms) {
            $this->line(
                "  <comment>{$prefix}:</comment> "
                . implode(', ', array_map(fn($p) => str_replace($prefix . '-', '', $p), $perms)),
            );
        }

        $this->newLine();

        if ($isDryRun) {
            $this->warn('DRY RUN - No changes made. Run without --dry-run to delete.');
            return Command::SUCCESS;
        }

        if (!$this->confirm('Do you want to delete these ' . count($invalidPermissions) . ' invalid permissions?')) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        DB::beginTransaction();
        try {
            $invalidIds = array_keys($invalidPermissions);

            // Delete from role_has_permissions
            $rolePermDeleted = DB::table('role_has_permissions')->whereIn('permission_id', $invalidIds)->delete();
            $this->info("Deleted {$rolePermDeleted} role-permission associations");

            // Delete from model_has_permissions (direct user permissions)
            $modelPermDeleted = DB::table('model_has_permissions')->whereIn('permission_id', $invalidIds)->delete();
            $this->info("Deleted {$modelPermDeleted} model-permission associations");

            // Delete permissions
            $permDeleted = Permission::whereIn('id', $invalidIds)->delete();
            $this->info("Deleted {$permDeleted} permissions");

            // Clear permission cache
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            DB::commit();
            $this->newLine();
            $this->info('Cleanup completed successfully!');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Error during cleanup: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
