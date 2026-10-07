@php
    $topPhone = \App\Models\Setting::get('business_phone', '+2348153667923');
    $topEmail = \App\Models\Setting::get('business_email', 'humphreybuildingmaterials@gmail.com');
@endphp
<div class="top-bar">
    <div class="container">
        <span>
            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $topPhone) }}">📞 {{ $topPhone }}</a>
            &nbsp;·&nbsp;
            <a href="mailto:{{ $topEmail }}">{{ $topEmail }}</a>
        </span>
        <span class="hide-mobile">🚚 Delivery available — Contact us for a quote</span>
    </div>
</div>

<header class="site-header">
    <div class="container">
        <a href="{{ route('home') }}" class="logo" aria-label="Humphrey Building Materials home">
            @php $siteLogo = \App\Models\Setting::get('logo_path'); @endphp
            @if ($siteLogo)
                <img src="{{ asset('storage/' . $siteLogo) }}" alt="{{ \App\Models\Setting::get('business_name', config('app.name')) }}" class="logo-img">
            @else
                <span class="logo-mark">H</span>
            @endif
            <span class="logo-text">
                {{ \Illuminate\Support\Str::before(\App\Models\Setting::get('business_name', 'Humphrey Building Materials'), ' ') ?: 'Humphrey' }}
                <small>{{ \App\Models\Setting::get('business_name', 'Humphrey Building Materials') }}</small>
            </span>
        </a>

        <button class="nav-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false">
            ☰
        </button>

        <nav class="main-nav" aria-label="Main navigation">
            <ul>
                <li><a href="{{ route('home') }}" class="{{ request()->routeIs('home') ? 'active' : '' }}">Home</a></li>
                <li><a href="{{ route('products.index') }}" class="{{ request()->routeIs('products.*') ? 'active' : '' }}">Products</a></li>
                <li><a href="{{ route('categories.index') }}" class="{{ request()->routeIs('categories.*') ? 'active' : '' }}">Categories</a></li>
                <li><a href="{{ route('home') }}#why-us">About</a></li>
                <li><a href="{{ route('home') }}#contact">Contact</a></li>
                <li>
                    <a href="{{ route('cart.index') }}" class="cart-link {{ request()->routeIs('cart.*') || request()->routeIs('checkout.*') ? 'active' : '' }}">
                        🛒 Cart
                        @if (($cartCount ?? 0) > 0)
                            <span class="cart-badge">{{ $cartCount }}</span>
                        @endif
                    </a>
                </li>

                @guest
                    <li><a href="{{ route('login') }}" class="{{ request()->routeIs('login') ? 'active' : '' }}">Login</a></li>
                    <li><a href="{{ route('register') }}" class="nav-cta {{ request()->routeIs('register') ? 'active' : '' }}">Register</a></li>
                @else
                    @php
                        $unreadCount = auth()->user()->unreadNotifications()->count();
                    @endphp
                    <li>
                        <a href="{{ route('notifications.index') }}"
                           class="notif-bell {{ request()->routeIs('notifications.*') ? 'active' : '' }}"
                           title="Notifications">
                            🔔
                            @if ($unreadCount > 0)
                                <span class="cart-badge">{{ $unreadCount }}</span>
                            @endif
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('orders.index') }}" class="{{ request()->routeIs('orders.*') ? 'active' : '' }}">
                            📦 My Orders
                        </a>
                    </li>
                    @if (auth()->user()->isStaff())
                        <li>
                            <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.*') ? 'active' : '' }}">
                                🛠️ Dashboard
                            </a>
                        </li>
                    @endif
                    <li>
                        <a href="{{ route('profile.edit') }}" class="{{ request()->routeIs('profile.*') || request()->routeIs('password.change*') ? 'active' : '' }}">
                            👤 {{ \Illuminate\Support\Str::limit(auth()->user()->name, 14) }}
                        </a>
                    </li>
                    <li>
                        <form action="{{ route('logout') }}" method="POST" class="nav-inline-form"
                              onsubmit="return confirm('Are you sure you want to log out?');">
                            @csrf
                            <button type="submit" class="nav-logout-btn">Logout</button>
                        </form>
                    </li>
                @endguest
            </ul>
        </nav>
    </div>
</header>
