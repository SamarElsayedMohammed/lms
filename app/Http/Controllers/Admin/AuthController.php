<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    /**
     * Permission to route mapping for redirect after login.
     * Order matters - first matching permission determines redirect.
     *
     * @var array<string, string>
     */
    private const PERMISSION_ROUTES = [
        'dashboard-list' => 'dashboard',
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
        'reports-commission-list' => 'reports.commission',
        'reports-course-list' => 'reports.course',
        'reports-instructor-list' => 'reports.instructor',
        'reports-enrollment-list' => 'reports.enrollment',
        'reports-revenue-list' => 'reports.revenue',
        'settings-system-list' => 'settings.system',
        'settings-firebase-list' => 'settings.firebase',
        'settings-instructor-terms-list' => 'settings.instructor-terms',
        'settings-app-list' => 'settings.app',
        'settings-payment-gateway-list' => 'settings.payment-gateway',
        'settings-language-list' => 'settings.language',
        'settings-hls-list' => 'settings.hls.index',
        'helpdesk-groups-list' => 'groups.index',
        'helpdesk-group-requests-list' => 'admin.helpdesk.group-requests.index',
        'helpdesk-questions-list' => 'admin.helpdesk.questions.index',
        'helpdesk-replies-list' => 'admin.helpdesk.replies.index',
        'contact-messages-list' => 'admin.contact-messages.index',
    ];

    public function showLoginForm(): RedirectResponse|View
    {
        if (Auth::check()) {
            return redirect()->intended($this->getDefaultRouteForUser(Auth::user()));
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            return redirect()->intended($this->getDefaultRouteForUser(Auth::user()));
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login-page');
    }

    /**
     * Get the first available route for the user based on their permissions.
     */
    private function getDefaultRouteForUser(null|User $user): string
    {
        if ($user === null) {
            return route('login-page');
        }

        foreach (self::PERMISSION_ROUTES as $permission => $routeName) {
            if ($user->can($permission)) {
                return route($routeName);
            }
        }

        // Fallback - if user has no permissions, redirect to a "no access" page
        // For now, redirect to login with error
        return route('login-page');
    }
}
