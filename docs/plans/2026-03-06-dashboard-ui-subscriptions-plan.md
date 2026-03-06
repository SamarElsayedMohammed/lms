# Dashboard UI Enhancements & User Subscriptions Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add user subscription statistics to the dashboard, filter users by subscription type, and update sidebar logo/dashboard styling.

**Architecture:** 
1. Backend updates to `DashboardApiController` to provide subscription stats and `UserController` for filtering.
2. Blade template updates for dashboard cards and user table.
3. CSS adjustments for sidebar logo and stat card light mode styling.

**Tech Stack:** Laravel, Blade, Bootstrap/Tailwind CSS, Vue.js (if applicable in user list)

---

### Task 1: Update Sidebar Logo

**Files:**
- Modify: `resources/views/components/sidebar.blade.php`

**Step 1: Update Logo HTML**

```html
// Find the img tag with `images/logo.jpeg` and replace it
<img src="{{ asset('images/logo-transparent.png') }}" alt="{{ __('Logo') }}" class="img-fluid" style="height: 120px; width: auto; background-color: transparent;">
```

**Step 2: Verify Sidebar Styling**
Check that the logo renders cleanly at 120px height without background.

**Step 3: Commit**

```bash
git add resources/views/components/sidebar.blade.php
git commit -m "style: update sidebar logo size to 120px and use transparent image"
```

### Task 2: Implement Light Mode Hover Effects for Stat Cards

**Files:**
- Modify/Create: `resources/views/pages/admin-dashboard.blade.php` (in the `<style>` push block) OR `public/css/custom.css` (assuming inline style in blade for simplicity as requested).

**Step 1: Add CSS to Dashboard Blade**

```html
// In resources/views/pages/admin-dashboard.blade.php within <style> block:
.card-statistic-1, .card-statistic-2, .card-stat {
    background-color: #ffffff !important;
}
.card-statistic-1 .card-wrap .card-header h4,
.card-statistic-2 .card-wrap .card-header h4,
.card-stat .title {
    color: #000000 !important;
    transition: color 0.3s ease;
}
.card-statistic-1 .card-wrap .card-body,
.card-statistic-2 .card-wrap .card-body,
.card-stat .value {
    color: #000000 !important;
    transition: color 0.3s ease;
}

.card-statistic-1:hover .card-wrap .card-header h4,
.card-statistic-1:hover .card-wrap .card-body,
.card-statistic-1:hover i,
.card-statistic-2:hover .card-wrap .card-header h4,
.card-statistic-2:hover .card-wrap .card-body,
.card-statistic-2:hover i,
.card-stat:hover .title,
.card-stat:hover .value,
.card-stat:hover i {
    color: red !important;
}
```

**Step 2: Commit**

```bash
git add resources/views/pages/admin-dashboard.blade.php
git commit -m "style: add light mode hover effects to dashboard stat cards"
```

### Task 3: Backend - Fetch Subscription Stats for Dashboard

**Files:**
- Modify: `app/Http/Controllers/API/DashboardController.php` (or equivalent API controller providing `/api/dashboard-data`).

**Step 1: Identify Controller and Update**
- Ensure the controller aggregates active subscriptions.

```php
// In the method handling /api/dashboard-data
$subscriptionStats = [
    'monthly' => \App\Models\Subscription::where('status', 'active')->where('plan_type', 'monthly')->count(),
    'quarterly' => \App\Models\Subscription::where('status', 'active')->where('plan_type', 'quarterly')->count(),
    'annual' => \App\Models\Subscription::where('status', 'active')->where('plan_type', 'annual')->count(),
];

// Merge into return data
$data['subscription_stats'] = $subscriptionStats;
```

**Step 2: Commit**

```bash
git add app/Http/Controllers/API/DashboardController.php
git commit -m "feat: add subscription stats to dashboard api payload"
```

### Task 4: Frontend - Display Subscription Stats on Dashboard

**Files:**
- Modify: `resources/views/pages/admin-dashboard.blade.php`

**Step 1: Add HTML Structure**
Add a new row under overview stats:

```html
<div class="row">
    <div class="col-lg-4 col-md-4 col-sm-12 col-12 mb-4">
        <x-stat-card icon="fas fa-calendar-alt" color="primary" :title="__('Monthly Subscriptions')"
            title-id="monthly-subs-label" value-id="monthly-subs-count" />
    </div>
    <div class="col-lg-4 col-md-4 col-sm-12 col-12 mb-4">
        <x-stat-card icon="fas fa-calendar-check" color="success" :title="__('Quarterly Subscriptions')"
            title-id="quarterly-subs-label" value-id="quarterly-subs-count" />
    </div>
    <div class="col-lg-4 col-md-4 col-sm-12 col-12 mb-4">
        <x-stat-card icon="fas fa-calendar-plus" color="info" :title="__('Annual Subscriptions')"
            title-id="annual-subs-label" value-id="annual-subs-count" />
    </div>
</div>
```

**Step 2: Update JS `updateOverviewStats()`**
```javascript
// In updateOverviewStats()
if (dashboardData.subscription_stats) {
    document.getElementById('monthly-subs-count').textContent = dashboardData.subscription_stats.monthly || 0;
    document.getElementById('quarterly-subs-count').textContent = dashboardData.subscription_stats.quarterly || 0;
    document.getElementById('annual-subs-count').textContent = dashboardData.subscription_stats.annual || 0;
}
```

**Step 3: Commit**

```bash
git add resources/views/pages/admin-dashboard.blade.php
git commit -m "feat: display subscription stats on admin dashboard"
```

### Task 5: Backend - Add Subscription Filter to Users List

**Files:**
- Modify: `app/Http/Controllers/Admin/UserController.php` (or wherever user index is handled)

**Step 1: Update Controller Query**

```php
// In index() method
$query = User::query();

if (request()->filled('subscription_type')) {
    $type = request('subscription_type');
    $query->whereHas('subscriptions', function($q) use ($type) {
        $q->where('status', 'active')->where('plan_type', $type);
    });
}
```

**Step 2: Commit**

```bash
git add app/Http/Controllers/Admin/UserController.php
git commit -m "feat: support filtering users by active subscription type"
```

### Task 6: Frontend - Add Subscription Filter Dropdown to Users List

**Files:**
- Modify: `resources/views/users/index.blade.php` (or equivalent blade file for users index)

**Step 1: Add Dropdown**

```html
<form method="GET" action="{{ route('admin.users.index') }}">
    <select name="subscription_type" class="form-control" onchange="this.form.submit()">
        <option value="">{{ __('All Subscriptions') }}</option>
        <option value="monthly" {{ request('subscription_type') == 'monthly' ? 'selected' : '' }}>{{ __('Monthly') }}</option>
        <option value="quarterly" {{ request('subscription_type') == 'quarterly' ? 'selected' : '' }}>{{ __('Quarterly') }}</option>
        <option value="annual" {{ request('subscription_type') == 'annual' ? 'selected' : '' }}>{{ __('Annual') }}</option>
    </select>
</form>
```

**Step 2: Commit**

```bash
git add resources/views/users/index.blade.php
git commit -m "feat: add subscription filter dropdown to users list"
```