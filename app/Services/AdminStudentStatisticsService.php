<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\RoleManager;

class AdminStudentStatisticsService
{
    /**
     * Get statistics for users filtered by role.
     * 
     * @param string $roleFilter e.g., 'all', 'student', 'admin', 'instructor'
     * @return array{total: int, active: int, inactive: int, role_count: int}
     */
    public function getStatistics(string $roleFilter = 'all'): array
    {
        $query = User::query();

        RoleManager::applyRoleFilter($query, $roleFilter);

        // Execute multiple aggregates safely on cloned base query
        $total = (clone $query)->count();
        $active = (clone $query)->where('is_active', true)->count();
        $inactive = (clone $query)->where(function ($q) {
            $q->where('is_active', false)->orWhereNull('is_active');
        })->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'role_count' => $total,
        ];
    }
}
