# Design Document: Subscription UI, Sidebar Logo, and Dashboard Theming

**Date:** 2026-03-06
**Topic:** Dashboard UI Enhancements & User Subscriptions

## Overview
This document outlines the design for adding subscription-based filtering and statistics for users, updating the sidebar logo size and format, and improving the hover states for dashboard statistic cards in light mode.

## 1. User Subscriptions Filtering & Stats

### 1.1 Admin Dashboard (`resources/views/pages/admin-dashboard.blade.php`)
- **New Section:** Add a new row of `x-stat-card` components immediately below the "Overview Statistics Cards" or integrate them logically.
- **Cards to Add:**
  - Total Monthly Subscribers.
  - Total Quarterly Subscribers.
  - Total Annual Subscribers.
- **Data Source:** The backend API (`/api/dashboard-data`) must be updated to return these subscription statistics within the existing payload (likely under `user_stats` or a new `subscription_stats` key).

### 1.2 User Management Table (`resources/views/users/index.blade.php` or equivalent)
- **Filters:** Add a dropdown filter above the users table allowing the admin to filter users by their active subscription type (All, Monthly, Quarterly, Annual).
- **Data Column:** Add a column to the users' table indicating their current "Active Subscription Plan".
- **Backend:** The `UserController` (or Livewire component depending on stack) must support filtering by the `Subscription` model relation on `User`.

## 2. Sidebar Logo Update

### 2.1 Component (`resources/views/components/sidebar.blade.php`)
- **Asset Update:** Change the source of the logo image from the default/current logo to the transparent version `public/images/logo-transparent.png`.
- **Styling:**
  - Remove any background styles.
  - Set CSS `height: 120px; width: auto; max-height: none;` to significantly increase the size as requested while maintaining aspect ratio.

## 3. Light Mode Theming for Stat Cards

### 3.1 Component & CSS
- **Target:** The `x-stat-card` elements on the admin dashboard.
- **Light Mode Base:**
  - Ensure the card background is distinctly white (`background: #ffffff;`).
  - Ensure standard text color inside the card is black or dark gray (`color: #000000;`).
- **Hover State:**
  - Apply a custom hover effect via CSS (`.card-statistic-1:hover`, etc. depending on Stisla/Tailwind implementation).
  - On hover, transition text color and icon color to Red (`color: red;`).
  - *Note: Dark mode colors will remain unaffected per user instructions to focus on the light mode adjustments for now.*

## Implementation Steps (Next Phase)
1. Update backend API/Controllers to provide subscription counts.
2. Add Stat Cards to Dashboard Blade view.
3. Update User list view and controller for filtering.
4. Modify `sidebar.blade.php` logo img tags.
5. Add custom CSS for Light Mode Hover effects on stat cards.
