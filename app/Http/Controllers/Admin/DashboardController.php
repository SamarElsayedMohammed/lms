<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Permission to route mapping for fallback redirect.
     * Order matters - first matching permission determines redirect.
     *
     * @var array<string, string>
     */
    private const PERMISSION_ROUTES = [
        'categories-list' => 'categories.index',
        'custom-form-fields-list' => 'custom-form-fields.index',
        'faqs-list' => 'faqs.index',
        'pages-list' => 'pages.index',
        'taxes-list' => 'taxes.index',
        'promo-codes-list' => 'promo-codes.index',
        'certificates-list' => 'admin.certificates.index',
        'instructors-list' => 'instructor.index',
        'notifications-list' => 'notifications.index',
        'assignments-list' => 'admin.assignments.index',
        'ratings-list' => 'admin.ratings.index',
        'orders-list' => 'admin.orders.index',
        'refunds-list' => 'admin.refunds.index',
        'enrollments-list' => 'admin.enrollments.index',
        'tracking-list' => 'admin.tracking.index',
        'users-list' => 'admin.users.index',
        'wallets-list' => 'admin.wallets.index',
        'withdrawals-list' => 'admin.withdrawals.index',
        'courses-list' => 'courses.index',
        'course-chapters-list' => 'course-chapters.index',
        'course-languages-list' => 'courses.languages.index',
        'course-tags-list' => 'tags.index',
        'sliders-list' => 'sliders.index',
        'feature-sections-list' => 'feature-sections.index',
        'roles-list' => 'roles.index',
        'staff-list' => 'staffs.index',
        'reports-sales-list' => 'reports.sales',
        'settings-system-list' => 'settings.system',
        'helpdesk-groups-list' => 'groups.index',
        'contact-messages-list' => 'admin.contact-messages.index',
    ];

    public function index(): View|RedirectResponse
    {
        $user = Auth::user();

        if ($user === null || !$user->can('dashboard-list')) {
            // Redirect to first available route based on permissions
            foreach (self::PERMISSION_ROUTES as $permission => $routeName) {
                if ($user?->can($permission)) {
                    return redirect()->route($routeName);
                }
            }

            // No permissions at all - logout and show error
            Auth::logout();

            return redirect()
                ->route('login-page')
                ->withErrors([
                    'message' => trans("You don't have any permissions assigned. Please contact administrator."),
                ]);
        }

        return view('pages.admin-dashboard', ['type_menu' => 'dashboard']);
    }
}
