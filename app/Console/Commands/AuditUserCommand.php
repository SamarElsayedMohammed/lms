<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\RoleManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class AuditUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auth:audit-user {email : The email to audit} {--password= : Optional password to test Hash::check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit user account data, Spatie roles, and password hash status safely';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $testPassword = $this->option('password');

        $users = User::withTrashed()->where('email', $email)->get();

        if ($users->isEmpty()) {
            $this->error("No user found with email: {$email}");
            return 1;
        }

        $this->info("Found {$users->count()} record(s) for email: {$email}");

        foreach ($users as $index => $user) {
            $this->line("----------------------------------------");
            $this->line("Record #" . ($index + 1));
            $this->line("ID: {$user->id}");
            $this->line("Name: {$user->name}");
            $this->line("Email: {$user->email}");
            $this->line("Status: " . ($user->trashed() ? "DEACTIVATED/TRASHED" : ($user->is_active ? "ACTIVE" : "INACTIVE")));
            $this->line("Type: {$user->type}");
            $this->line("Roles: " . implode(', ', $user->getRoleNames()->toArray()));
            $this->line("Hash Algorithm Prefix: " . substr((string)$user->password, 0, 7));

            if (!empty($testPassword)) {
                $hashMatches = Hash::check($testPassword, $user->password ?? '');
                $this->line("Hash check for provided password: " . ($hashMatches ? "MATCH SUCCESS" : "MATCH FAILURE"));
            }

            $userCandidateMatch = $user->hasAnyRole(RoleManager::getCandidateRoleNames('user'));
            $this->line("User candidate role check: " . ($userCandidateMatch ? "PASSED" : "FAILED"));
        }

        return 0;
    }
}
