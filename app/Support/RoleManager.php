<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleManager
{
    public const DEFAULT_GUARD = 'web';

    public const ROLE_STUDENT = 'student';
    public const ROLE_USER = 'User';
    public const ROLE_INSTRUCTOR = 'Instructor';
    public const ROLE_ADMIN = 'Admin';
    public const ROLE_SUPER_ADMIN = 'Super Admin';
    public const ROLE_SUPERVISOR = 'Supervisor';
    public const ROLE_STAFF = 'Staff';
    public const ROLE_ACCOUNTANT = 'Accountant';
    public const ROLE_SALES = 'Sales';
    public const ROLE_MODERATOR = 'Moderator';
    public const ROLE_TEAM = 'Team';
    public const ROLE_TEAM_INSTRUCTOR = 'Team Instructor';

    /**
     * Map a role filter string to all candidate role names.
     *
     * @return array<int, string>
     */
    public static function getCandidateRoleNames(string $roleFilter): array
    {
        $normalized = strtolower(trim($roleFilter));

        return match ($normalized) {
            'student', 'user', 'learner', 'subscriber' => [
                self::ROLE_STUDENT,
                self::ROLE_USER,
                'student',
                'Student',
                'user',
                'User',
            ],
            'instructor', 'teacher' => [
                self::ROLE_INSTRUCTOR,
                self::ROLE_TEAM_INSTRUCTOR,
                'instructor',
                'Instructor',
            ],
            'admin' => [
                self::ROLE_ADMIN,
                self::ROLE_SUPER_ADMIN,
                self::ROLE_SUPERVISOR,
                self::ROLE_STAFF,
                self::ROLE_MODERATOR,
                'admin',
                'Admin',
                'super_admin',
                'Super Admin',
            ],
            'super_admin', 'super-admin' => [
                self::ROLE_SUPER_ADMIN,
                'Super Admin',
                'super_admin',
            ],
            'supervisor' => [
                self::ROLE_SUPERVISOR,
                'Supervisor',
            ],
            'staff' => [
                self::ROLE_STAFF,
                'Staff',
            ],
            'accountant' => [
                self::ROLE_ACCOUNTANT,
                'Accountant',
            ],
            'sales' => [
                self::ROLE_SALES,
                'Sales',
            ],
            'moderator' => [
                self::ROLE_MODERATOR,
                'Moderator',
            ],
            default => [$roleFilter],
        };
    }

    /**
     * Safely apply role filter to an Eloquent query without throwing RoleDoesNotExist 500 exception.
     */
    public static function applyRoleFilter(Builder $query, string $roleFilter): Builder
    {
        $normalized = strtolower(trim($roleFilter));
        if ($normalized === '' || $normalized === 'all') {
            return $query;
        }

        $candidateNames = self::getCandidateRoleNames($normalized);

        try {
            $existingRoleNames = Role::whereIn('name', $candidateNames)
                ->where('guard_name', self::DEFAULT_GUARD)
                ->pluck('name')
                ->toArray();
        } catch (\Throwable) {
            $existingRoleNames = [];
        }

        if (in_array($normalized, ['student', 'user', 'learner', 'subscriber'], true)) {
            return $query->where(function (Builder $q) use ($existingRoleNames) {
                if (!empty($existingRoleNames)) {
                    $q->whereHas('roles', function (Builder $rq) use ($existingRoleNames) {
                        $rq->whereIn('name', $existingRoleNames);
                    });
                }
                $q->orWhere(function (Builder $legacyQ) {
                    $legacyQ->whereDoesntHave('roles')
                        ->whereDoesntHave('instructor_details')
                        ->where(function (Builder $typeQ) {
                            $typeQ->whereNull('is_webinar_guest')
                                ->orWhere('is_webinar_guest', false);
                        });
                });
            });
        }

        if (empty($existingRoleNames)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('roles', function (Builder $rq) use ($existingRoleNames) {
            $rq->whereIn('name', $existingRoleNames);
        });
    }

    /**
     * Ensure all canonical roles exist in the database with guard_name = 'web'.
     */
    public static function ensureCanonicalRolesExist(): void
    {
        $rolesToSeed = [
            self::ROLE_STUDENT => false,
            self::ROLE_USER => false,
            self::ROLE_INSTRUCTOR => false,
            self::ROLE_ADMIN => false,
            self::ROLE_SUPER_ADMIN => false,
            self::ROLE_SUPERVISOR => false,
            self::ROLE_TEAM => false,
            self::ROLE_TEAM_INSTRUCTOR => false,
            self::ROLE_ACCOUNTANT => false,
            self::ROLE_SALES => false,
            self::ROLE_MODERATOR => false,
            self::ROLE_STAFF => false,
        ];

        foreach ($rolesToSeed as $roleName => $customRole) {
            Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => self::DEFAULT_GUARD],
                ['custom_role' => $customRole]
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Safely assign the canonical student role to a user.
     */
    public static function assignStudentRole(User $user): void
    {
        self::ensureCanonicalRolesExist();
        $studentRole = Role::where('name', self::ROLE_STUDENT)
            ->where('guard_name', self::DEFAULT_GUARD)
            ->first();

        if ($studentRole && !$user->hasRole(self::ROLE_STUDENT)) {
            $user->assignRole($studentRole);
        }
    }
}
