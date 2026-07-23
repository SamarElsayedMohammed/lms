<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\RoleManager;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

final class RepairUserRolesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'roles:repair {--assign-unassigned-students : Backfill users missing any role assignment as student}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Idempotently create canonical system roles and backfill users missing role assignments.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting canonical roles repair...');

        RoleManager::ensureCanonicalRolesExist();
        $this->info('Canonical roles created/verified with guard "web".');

        if ($this->option('assign-unassigned-students')) {
            $studentRole = Role::where('name', RoleManager::ROLE_STUDENT)
                ->where('guard_name', RoleManager::DEFAULT_GUARD)
                ->first();

            if ($studentRole) {
                $unassignedUsers = User::whereDoesntHave('roles')
                    ->whereDoesntHave('instructor_details')
                    ->get();

                $count = 0;
                foreach ($unassignedUsers as $user) {
                    if (!$user->isAdmin) {
                        $user->assignRole($studentRole);
                        $count++;
                    }
                }
                $this->info("Assigned student role to {$count} legacy unassigned users.");
            }
        }

        $this->info('Flushing Spatie permission cache...');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info('Role repair completed successfully!');

        return Command::SUCCESS;
    }
}
