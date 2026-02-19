<?php

namespace App\Services;

use App\Services\HelperService;

class InstructorModeService
{
    /**
     * Check if the system is in single instructor mode
     *
     * @return bool
     */
    public static function isSingleInstructorMode(): bool
    {
        $instructorMode = HelperService::systemSettings('instructor_mode');
        return $instructorMode === 'single';
    }

    /**
     * Check if the system is in multi instructor mode
     *
     * @return bool
     */
    public static function isMultiInstructorMode(): bool
    {
        return !self::isSingleInstructorMode();
    }

    /**
     * Get the instructor mode setting
     *
     * @return string
     */
    public static function getInstructorMode(): string
    {
        return HelperService::systemSettings('instructor_mode') ?? 'multi';
    }

    /**
     * Check if instructor lists should be shown
     * In single instructor mode, instructor lists are hidden
     *
     * @return bool
     */
    public static function shouldShowInstructorLists(): bool
    {
        return self::isMultiInstructorMode();
    }

    /**
     * Check if instructor filters should be shown
     * In single instructor mode, instructor filters are hidden
     *
     * @return bool
     */
    public static function shouldShowInstructorFilters(): bool
    {
        return self::isMultiInstructorMode();
    }

    /**
     * Check if instructor management features should be available
     *
     * @return bool
     */
    public static function shouldShowInstructorManagement(): bool
    {
        return self::isMultiInstructorMode();
    }
}
