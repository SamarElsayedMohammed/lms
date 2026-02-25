<div class="main-sidebar sidebar-style-2">
    <aside id="sidebar-wrapper">
        <div class="sidebar-brand">
            <a href="{{ route('dashboard') }}">
                @if (!empty($settingLogos['horizontal_logo']))
                <img src="{{ $settingLogos['horizontal_logo'] }}" alt="{{ __('Logo') }}" class="img-fluid rounded"
                    style="max-height: auto; width: 150px;">
                @else
                <img src="{{ asset('images/logo.jpeg') }}" alt="{{ __('Logo') }}" class="img-fluid" style="max-height: 40px; width: auto;">
                @endif
            </a>
        </div>
        <div class="sidebar-brand sidebar-brand-sm">
            <a href="{{ route('dashboard') }}">
                @if (!empty($settingLogos['horizontal_logo']))
                {{ config('app.name') }}
                @else
                <img src="{{ asset('images/logo.jpeg') }}" alt="{{ __('Logo') }}" style="max-height: 32px; width: auto;">
                @endif
            </a>
        </div>
        <ul class="sidebar-menu">

            {{-- ********************************************************************** --}}
            {{-- Dashboard --}}
            @can('dashboard-list')
            <li class="menu-header">{{ __('Dashboard') }}</li>
            <li class="nav-item {{ $type_menu === 'dashboard' ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('dashboard') }}">
                    <i class="fas fa-fire"></i><span>{{ __('Dashboard') }}</span>
                </a>
            </li>
            @endcan
            {{-- ********************************************************************** --}}

            {{-- ********************************************************************** --}}
            {{-- Management --}}
            @if(auth()->user()->can('categories-list') || auth()->user()->can('faqs-list') ||
            auth()->user()->can('promo-codes-list') || auth()->user()->can('certificates-list') ||
            auth()->user()->can('subscription-plans-list') || auth()->user()->can('instructors-list') ||
            auth()->user()->can('notifications-list') || auth()->user()->can('ratings-list') ||
            auth()->user()->can('orders-list') || auth()->user()->can('enrollments-list') ||
            auth()->user()->can('tracking-list') || auth()->user()->can('users-list'))
            <li class="menu-header">{{ __('Management') }}</li>
            @endif

            {{-- Category Management --}}
            @can('categories-list')
            <li class="nav-item {{ $type_menu === 'categories' ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('categories.index') }}">
                    <i class="fas fa-tags"></i> <span>{{ __('Categories') }}</span>
                </a>
            </li>
            @endcan

            {{-- FAQ Management --}}
            @can('faqs-list')
            <li class="nav-item {{ $type_menu === 'faqs' ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('faqs.index') }}">
                    <i class="fas fa-question-circle"></i><span>{{ __('FAQ') }}</span>
                </a>
            </li>
            @endcan

            {{-- Promo Code Management --}}
            @can('promo-codes-list')
            <li class="nav-item {{ $type_menu === 'promo-codes' ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('promo-codes.index') }}">
                    <i class="fas fa-ticket-alt"></i><span>{{ __('Promo Code') }}</span>
                </a>
            </li>
            @endcan

            {{-- Certificate Management --}}
            @can('certificates-list')
            <li class="nav-item {{ $type_menu === 'certificates' ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('admin.certificates.index') }}">
                    <i class="fas fa-certificate"></i><span>{{ __('Certificates') }}</span>
                </a>
            </li>
            @endcan

            {{-- Subscriptions / باقات الاشتراك --}}
            @can('subscription-plans-list')
            <li class="nav-item {{ $type_menu === 'subscription-plans' ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('subscription-plans.index') }}">
                    <i class="fas fa-gem"></i><span>{{ __('Subscription Plans') }}</span>
                </a>
            </li>
            @endcan

            {{-- Countries / الدول --}}
            <li class="nav-item {{ $type_menu === 'countries' ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('countries.index') }}">
                    <i class="fas fa-globe"></i><span>{{ __('Countries') }}</span>
                </a>
            </li>

            {{-- Supervisor Management (مشرف) --}}
            @can('instructors-list')
            @if(!isset($isSingleInstructorMode) || !$isSingleInstructorMode)
            <li
                class="nav-item dropdown {{ $type_menu === 'instructor' || $type_menu === 'instructor-wallet' || $type_menu === 'instructor-withdrawals' ? 'active' : '' }}">
                <a href="#" class="nav-link has-dropdown" data-toggle="dropdown">
                    <i class="fas fa-user-tie"></i><span>{{ __('Supervisors') }}</span>
                </a>
                <ul class="dropdown-menu">
                    <li class="{{ $type_menu === 'instructor' ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('instructor.index') }}">
                            <i class="fas fa-users mr-1"></i> {{ __('Supervisor List') }}
                        </a>
                    </li>
                    <li class="{{ $type_menu === 'instructor-wallet' ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('instructor.wallet-history') }}">
                            <i class="fas fa-wallet mr-1"></i> {{ __('Wallet History') }}
                        </a>
                    </li>
                    <li class="{{ $type_menu === 'instructor-withdrawals' ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('instructor.withdrawal-requests') }}">
                            <i class="fas fa-money-bill-wave mr-1"></i> {{ __('Withdrawal Requests') }}
                        </a>
                    </li>
                </ul>
            </li>
            @endif
            @endcan

            {{-- Notification Management --}}
            @can('notifications-list')
            <li class="nav-item {{ $type_menu === 'notifications' ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('notifications.index') }}">
                    <i class="fas fa-bell"></i><span>{{ __('Notifications') }}</span>
                </a>
            </li>
            @endcan

            {{-- Ratings Management --}}
            @can('ratings-list')
            <li class="nav-item {{ $type_menu === 'ratings' ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('admin.ratings.index') }}">
                    <i class="fas fa-star"></i> <span>{{ __('Ratings & Reviews') }}</span>
                </a>
            </li>
            @endcan

            {{-- Marketing Pixels --}}
            @canany('settings-system-list', 'manage_settings')
            <li class="nav-item {{ $type_menu === 'marketing-pixels' ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('marketing-pixels.index') }}">
                    <i class="fas fa-chart-line"></i> <span>{{ __('Marketing Pixels') }}</span>
                </a>
            </li>
            <li class="nav-item {{ $type_menu === 'currencies' ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('currencies.index') }}">
                    <i class="fas fa-coins"></i> <span>{{ __('Currencies') }}</span>
                </a>
            </li>
            @endcanany

            {{-- Pending Approvals --}}
            @canany('approve_ratings', 'approve_comments')
            <li class="nav-item {{ $type_menu === 'approvals' ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('admin.approvals.index') }}">
                    <i class="fas fa-check-double"></i> <span>{{ __('Pending Approvals') }}</span>
                </a>
            </li>
            @endcanany

            {{-- Orders Management --}}
            @can('orders-list')
            <li class="nav-item {{ $type_menu === 'orders' ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('admin.orders.index') }}">
                    <i class="fas fa-shopping-cart"></i> <span>{{ __('Orders') }}</span>
                </a>
            </li>
            @endcan

            {{-- Enrollment Management --}}
            @can('enrollments-list')
            <li class="nav-item {{ $type_menu === 'enrollments' ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('admin.enrollments.index') }}">
                    <i class="fas fa-graduation-cap"></i> <span>{{ __('Enrollments') }}</span>
                </a>
            </li>
            @endcan

            {{-- Tracking Management --}}
            @can('tracking-list')
            <li class="nav-item {{ $type_menu === 'tracking' ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('admin.tracking.index') }}">
                    <i class="fas fa-chart-line"></i> <span>{{ __('Progress Tracking') }}</span>
                </a>
            </li>
            @endcan

            {{-- Users Management --}}
            @if(auth()->user()->can('users-list') || auth()->user()->can('wallets-list') ||
            auth()->user()->can('withdrawals-list'))
            <li
                class="nav-item dropdown {{ $type_menu === 'users' || $type_menu === 'wallets' || $type_menu === 'withdrawals' ? 'active' : '' }}">
                <a href="#" class="nav-link has-dropdown" data-toggle="dropdown">
                    <i class="fas fa-users"></i> <span>{{ __('Users') }}</span>
                    @php
                    try {
                    $pendingWithdrawals = \App\Models\WithdrawalRequest::where('status', 'pending')->count();
                    } catch (\Exception $e) {
                    $pendingWithdrawals = 0;
                    }
                    @endphp
                    @if($pendingWithdrawals > 0)
                    <span class="badge badge-warning ml-2">{{ $pendingWithdrawals }}</span>
                    @endif
                </a>
                <ul class="dropdown-menu">
                    @can('users-list')
                    <li class="{{ $type_menu === 'users' ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('admin.users.index') }}">
                            <i class="fas fa-users mr-1"></i> {{ __('User List') }}
                        </a>
                    </li>
                    @endcan
                    @can('wallets-list')
                    <li class="{{ $type_menu === 'wallets' ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('admin.wallets.index') }}">
                            <i class="fas fa-wallet mr-1"></i> {{ __('Wallet Management') }}
                        </a>
                    </li>
                    @endcan
                    @can('withdrawals-list')
                    <li class="{{ $type_menu === 'withdrawals' ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('admin.withdrawals.index') }}">
                            <i class="fas fa-money-bill-wave mr-1"></i> {{ __('Withdrawal Requests') }}
                            @if($pendingWithdrawals > 0)
                            <span class="badge badge-warning ml-2">{{ $pendingWithdrawals }}</span>
                            @endif
                        </a>
                    </li>
                    @endcan
                </ul>
            </li>
            @endif

            {{-- ********************************************************************** --}}
            {{-- Course Management --}}
            @if(auth()->user()->can('courses-list') || auth()->user()->can('course-chapters-list') ||
            auth()->user()->can('course-tags-list'))
            <li class="menu-header">{{ __('Course Management') }}</li>
            <li
                class="nav-item dropdown {{ $type_menu === 'courses' || $type_menu === 'course-chapters' || $type_menu === 'course-tags' || $type_menu === 'course-requests' ? 'active' : '' }}">
                <a href="#" class="nav-link has-dropdown" data-toggle="dropdown"><i class="fas fa-book"></i>
                    <span>{{ __('Course Management') }}</span>
                </a>
                <ul class="dropdown-menu">
                    @can('course-tags-list')
                    <li class="{{ $type_menu === 'course-tags' ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('tags.index') }}">{{ __('Tags') }}</a>
                    </li> @endcan

                    @can('courses-list')
                    <li class="{{ $type_menu === 'courses' ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('courses.index') }}">{{ __('Course') }}</a>
                    </li> @endcan

                    @can('courses-requests')
                    <li class="{{ $type_menu === 'course-requests' ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('courses.requests') }}">{{ __('Course Requests') }}</a>
                    </li> @endcan
                </ul>
            </li>
            @endif
            {{-- ********************************************************************** --}}
            {{-- Home Screen Management --}}
            @if(auth()->user()->can('feature-sections-list'))
            <li class="menu-header">{{ __('Home Screen Management') }}</li>
            @endif

            {{-- Feature Section Management --}}
            @can('feature-sections-list')
            <li class="nav-item {{ $type_menu === 'feature-sections' ? 'active' : '' }}">
                <a class="nav-link" href="{{ route('feature-sections.index') }}">
                    <i class="fas fa-star "></i><span>{{ __('Feature Sections') }}</span>
                </a>
            </li>
            @endcan

            {{-- ********************************************************************** --}}
            {{-- Staff Management --}}
            @if(auth()->user()->can('roles-list') || auth()->user()->can('staff-list'))
            <li class="menu-header">{{ __('Staff Management') }}</li>
            <li class="nav-item dropdown {{ $type_menu === 'roles' || $type_menu === 'staffs' ? 'active' : '' }}">
                <a href="#" class="nav-link has-dropdown" data-toggle="dropdown"><i class="fas fa-user-shield"></i>
                    <span>{{ __('Staff Management') }}</span>
                </a>
                <ul class="dropdown-menu"> @can('roles-list')
                    <li class="{{ request()->is('roles') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('roles.index') }}">
                            <i class="fas fa-user-shield mr-1"></i> {{ __('Role') }}
                        </a>
                    </li> @endcan

                    @can('staff-list')
                    <li class="{{ request()->is('staffs') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('staffs.index') }}">
                            <i class="fas fa-user-tie mr-1"></i> {{ __('Staff') }}
                        </a>
                    </li> @endcan
                </ul>
            </li>
            @endif

            {{-- ********************************************************************** --}}
            {{-- Reports --}}
            @if(auth()->user()->can('reports-sales-list') || auth()->user()->can('reports-commission-list') ||
            auth()->user()->can('reports-course-list') || auth()->user()->can('reports-instructor-list') ||
            auth()->user()->can('reports-enrollment-list') || auth()->user()->can('reports-revenue-list'))
            <li class="menu-header">{{ __('Reports') }}</li>
            <li class="nav-item dropdown {{ $type_menu === 'reports' ? 'active' : '' }}">
                <a href="#" class="nav-link has-dropdown" data-toggle="dropdown"><i class="fas fa-chart-bar"></i>
                    <span>{{ __('Reports') }}</span>
                </a>
                <ul class="dropdown-menu"> @can('reports-sales-list')
                    <li class="{{ request()->is('reports/sales*') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('reports.sales') }}">
                            <i class="fas fa-shopping-cart mr-1"></i> {{ __('Sales Reports') }}
                        </a>
                    </li> @endcan

                    @can('reports-commission-list')
                    <li class="{{ request()->is('reports/commission*') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('reports.commission') }}">
                            <i class="fas fa-handshake mr-1"></i> {{ __('Commission Reports') }}
                        </a>
                    </li> @endcan

                    @can('reports-course-list')
                    <li class="{{ request()->is('reports/course*') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('reports.course') }}">
                            <i class="fas fa-book mr-1"></i> {{ __('Course Reports') }}
                        </a>
                    </li> @endcan

                    @can('reports-instructor-list')
                    @if(!isset($isSingleInstructorMode) || !$isSingleInstructorMode)
                    <li class="{{ request()->is('reports/instructor*') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('reports.instructor') }}">
                            <i class="fas fa-chalkboard-teacher mr-1"></i> {{ __('Instructor Reports') }}
                        </a>
                    </li>
                    @endif
                    @endcan

                    @can('reports-enrollment-list')
                    <li class="{{ request()->is('reports/enrollment*') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('reports.enrollment') }}">
                            <i class="fas fa-user-graduate mr-1"></i> {{ __('Enrollment Reports') }}
                        </a>
                    </li> @endcan

                    @can('reports-revenue-list')
                    <li class="{{ request()->is('reports/revenue*') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('reports.revenue') }}">
                            <i class="fas fa-money-bill-wave mr-1"></i> {{ __('Revenue Reports') }}
                        </a>
                    </li> @endcan
                </ul>
            </li>
            @endif

            {{-- ********************************************************************** --}}
            {{-- Settings --}}
            @if(auth()->user()->can('settings-system-list') || auth()->user()->can('settings-firebase-list') ||
            auth()->user()->can('settings-refund-list') || auth()->user()->can('settings-app-list') ||
            auth()->user()->can('settings-payment-gateway-list'))
            <li class="menu-header">{{ __('Settings') }}</li>
            <li class="nav-item dropdown {{ $type_menu === 'settings' ? 'active' : '' }}">
                <a href="#" class="nav-link has-dropdown"><i class="fas fa-cog"></i><span>{{ __('Settings')
                        }}</span></a>
                <ul class="dropdown-menu"> @can('settings-system-list')
                    <li class="{{ request()->is('system-settings') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('settings.system') }}">{{ __('System Settings') }}</a>
                    </li> @endcan

                    @can('settings-firebase-list')
                    <li class="{{ request()->is('firebase-settings') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('settings.firebase') }}">{{ __('Firebase Settings') }}</a>
                    </li> @endcan

                    @can('settings-system-list')
                    <li class="{{ request()->is('why-choose-us-settings') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('settings.why-choose-us') }}">{{ __('Why Choose Us Settings')
                            }}</a>
                    </li> @endcan

                    @can('settings-system-list')
                    <li class="{{ request()->is('seo-settings*') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('admin.seo-settings.index') }}">{{ __('SEO Settings') }}</a>
                    </li> @endcan

                    @can('settings-app-list')
                    <li class="{{ request()->is('app-settings') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('settings.app') }}">{{ __('App Settings') }}</a>
                    </li> @endcan

                    @can('settings-payment-gateway-list')
                    <li class="{{ request()->is('payment-gateway-settings') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('settings.payment-gateway') }}">{{ __('Payment Gateway
                            Settings') }}</a>
                    </li> @endcan

                </ul>
            </li>
            @endif

            {{-- ********************************************************************** --}}
            {{-- Help Desk - HIDDEN (removed from sidebar) --}}
            {{-- ********************************************************************** --}}

            {{-- Contact Messages --}}
            @can('contact-messages-list')
            <li class="menu-header">{{ __('Contact Messages') }}</li>
            <li class="nav-item {{ $type_menu === 'contact-messages' ? 'active' : '' }}">
                <a href="{{ route('admin.contact-messages.index') }}" class="nav-link">
                    <i class="fas fa-envelope"></i>
                    <span>{{ __('Contact Messages') }}</span>
                    @php
                    try {
                    $newMessagesCount = \App\Models\ContactMessage::new()->count();
                    } catch (\Exception $e) {
                    $newMessagesCount = 0;
                    }
                    @endphp
                    @if($newMessagesCount > 0)
                    <span class="badge badge-warning ml-auto">{{ $newMessagesCount }}</span>
                    @endif
                </a>
            </li>
            @endcan
            {{-- ********************************************************************** --}}
        </ul>
    </aside>
</div>