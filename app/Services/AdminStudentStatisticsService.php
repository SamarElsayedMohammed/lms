<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

class AdminStudentStatisticsService
{
    /**
     * Get statistics for users filtered by role.
     * 
     * @param string $roleFilter e.g., 'all', 'student', 'admin', 'instructor'
     * @return array
     */
    public function getStatistics(string $roleFilter = 'all'): array
    {
        $query = User::query();

        if ($roleFilter !== 'all') {
            if ($roleFilter === 'admin') {
                // Admin page usually manages both admins and super_admins
                $query->role(['admin', 'super_admin']);
            } else {
                $query->role($roleFilter);
            }
        }

        // We clone to execute multiple aggregates on the same base query
        $total = (clone $query)->count();
        $active = (clone $query)->where('is_active', true)->count();
        $inactive = (clone $query)->where(function ($q) {
            $q->where('is_active', false)->orWhereNull('is_active');
        })->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'role_count' => $total, // If role filter is applied, role_count is equal to total.
        ];
    }
}
