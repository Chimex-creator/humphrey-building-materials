<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Admin') — {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="admin-body">

    <div class="admin-shell">

        {{-- Sidebar --}}
        <aside class="admin-sidebar" id="adminSidebar">
            <div class="admin-brand">
                @php $logo = \App\Models\Setting::get('logo_path'); @endphp
                @if ($logo)
                    <img src="{{ asset('storage/' . $logo) }}" alt="{{ config('app.name') }}" class="admin-brand-logo">
                @else
                    <span class="logo-mark">H</span>
                @endif
                <div>
                    <strong>{{ \App\Models\Setting::get('business_name', config('app.name')) }}</strong>
                    <small>Management System</small>
                </div>
            </div>

            <nav class="admin-nav" aria-label="Admin navigation">
                <p class="admin-nav-label">Main</p>
                <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                    📊 Dashboard
                </a>

                @if (auth()->user()->isAdmin())
                    <p class="admin-nav-label">Business</p>
                    <a href="{{ route('admin.settings') }}" class="{{ request()->routeIs('admin.settings') ? 'active' : '' }}">
                        ⚙️ Business Settings
                    </a>
                    {{-- Phases 14–15 — where we deliver and what it costs --}}
                    <a href="{{ route('admin.delivery-settings.index') }}" class="{{ request()->routeIs('admin.delivery-settings.*') ? 'active' : '' }}">
                        🚚 Delivery Zones &amp; Fees
                    </a>
                    {{-- Final Spec §24 — customer-care phone numbers --}}
                    <a href="{{ route('admin.contacts.index') }}" class="{{ request()->routeIs('admin.contacts.*') ? 'active' : '' }}">
                        📞 Customer Contacts
                    </a>
                    <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                        👥 Users / Staff
                    </a>
                    {{-- Phase 12 - audit trail (Master 47) --}}
                    <a href="{{ route('admin.activity-logs.index') }}" class="{{ request()->routeIs('admin.activity-logs.*') ? 'active' : '' }}">
                        📜 Activity Log
                    </a>
                @endif

                {{-- Inventory — Phases 4 & 9 (live) --}}
                @if (auth()->user()->isAdmin() || auth()->user()->isInventory())
                    <p class="admin-nav-label">Inventory</p>
                    <a href="{{ route('admin.inventory.index') }}" class="{{ request()->routeIs('admin.inventory.*') || request()->routeIs('inventory.home') ? 'active' : '' }}">
                        📊 Stock Overview
                    </a>
                    <a href="{{ route('admin.products.index') }}" class="{{ request()->routeIs('admin.products.*') ? 'active' : '' }}">
                        📦 Products
                    </a>
                    <a href="{{ route('admin.categories.index') }}" class="{{ request()->routeIs('admin.categories.*') ? 'active' : '' }}">
                        🗂️ Categories
                    </a>
                    <a href="{{ route('admin.stock-adjustments.index') }}" class="{{ request()->routeIs('admin.stock-adjustments.*') ? 'active' : '' }}">
                        🧮 Stock Adjustments
                    </a>
                    <a href="{{ route('admin.returns.index') }}" class="{{ request()->routeIs('admin.returns.*') ? 'active' : '' }}">
                        ↩️ Returns
                    </a>
                @endif

                @if (auth()->user()->isAdmin() || auth()->user()->isSales())
                    <p class="admin-nav-label">Sales</p>
                    <a href="{{ route('admin.orders.index') }}" class="{{ request()->routeIs('admin.orders.*') ? 'active' : '' }}">
                        📋 Orders
                    </a>
                    <a href="{{ route('admin.payments.index') }}" class="{{ request()->routeIs('admin.payments.*') ? 'active' : '' }}">
                        💳 Payments
                    </a>
                    {{-- Phase 7 (completion) --}}
                    <a href="{{ route('admin.deliveries.index') }}" class="{{ request()->routeIs('admin.deliveries.*') ? 'active' : '' }}">
                        🚚 Deliveries
                    </a>
                    {{-- Phase 11 --}}
                    <a href="{{ route('admin.reports.index') }}" class="{{ request()->routeIs('admin.reports.*') ? 'active' : '' }}">
                        📈 Sales Reports
                    </a>
                    {{-- Phase 12 --}}
                    <a href="{{ route('admin.customers.index') }}" class="{{ request()->routeIs('admin.customers.*') ? 'active' : '' }}">
                        📇 Customers
                    </a>
                    {{-- Phase 6 — Customer Care --}}
                    <a href="{{ route('admin.support.index') }}" class="{{ request()->routeIs('admin.support.*') ? 'active' : '' }}">
                        💬 Customer Care
                    </a>
                @endif

                <p class="admin-nav-label">Account</p>
                <a href="{{ route('profile.edit') }}">👤 My Profile</a>
                <a href="{{ route('home') }}">🏠 View Website</a>
                <form action="{{ route('logout') }}" method="POST" class="admin-logout-form"
                      onsubmit="return confirm('Are you sure you want to log out?');">
                    @csrf
                    <button type="submit" class="admin-logout-btn">🚪 Logout</button>
                </form>
            </nav>
        </aside>

        {{-- Main content --}}
        <div class="admin-main">
            <header class="admin-topbar">
                <button type="button" class="admin-sidebar-toggle" id="adminSidebarToggle" aria-label="Toggle sidebar">☰</button>
                <div class="admin-topbar-title">@yield('title', 'Dashboard')</div>
                <div class="admin-topbar-user">
                    <a href="{{ route('notifications.index') }}" class="notif-bell" title="Notifications">
                        🔔
                        @php $adminUnread = auth()->user()->unreadNotifications()->count(); @endphp
                        @if ($adminUnread > 0)
                            <span class="cart-badge">{{ $adminUnread }}</span>
                        @endif
                    </a>
                    <span class="role-badge role-{{ auth()->user()->role }}">{{ auth()->user()->roleLabel() }}</span>
                    <strong>{{ auth()->user()->name }}</strong>
                </div>
            </header>

            {{-- Flash messages: static without JS, auto-dismissing toasts with JS --}}
            @if (session('status') || session('error'))
                <div id="flash-data"
                     data-status="{{ session('status') }}"
                     data-error="{{ session('error') }}">
                    @if (session('status'))
                        <div class="flash flash-success"><div class="admin-content-inner">{{ session('status') }}</div></div>
                    @endif
                    @if (session('error'))
                        <div class="flash flash-error"><div class="admin-content-inner">{{ session('error') }}</div></div>
                    @endif
                </div>
            @endif
            @if ($errors->any())
                <div class="flash flash-error">
                    <div class="admin-content-inner">
                        <strong>Please fix the following:</strong>
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <main class="admin-content">
                @yield('content')
            </main>
        </div>
    </div>

    <script src="{{ asset('js/app.js') }}"></script>
    <script>
        // Mobile sidebar toggle
        document.addEventListener('DOMContentLoaded', function () {
            var toggle = document.getElementById('adminSidebarToggle');
            var sidebar = document.getElementById('adminSidebar');
            if (toggle && sidebar) {
                toggle.addEventListener('click', function () {
                    sidebar.classList.toggle('open');
                });
            }
        });
    </script>
    @stack('scripts')
</body>
</html>
