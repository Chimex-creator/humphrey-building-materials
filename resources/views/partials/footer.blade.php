@php
    $footerAddress = \App\Models\Setting::get('business_address', 'Eda plaza beside abacha road mararaba, nasarawa');
    $footerPhone = \App\Models\Setting::get('business_phone', '+2348153667923');
    $footerEmail = \App\Models\Setting::get('business_email', 'humphreybuildingmaterials@gmail.com');
    $footerName = \App\Models\Setting::get('business_name', config('app.name'));
    $footerCats = \App\Models\Category::orderBy('name')->take(6)->get();
@endphp
<footer class="site-footer">
    <div class="container footer-main">
        <div>
            <h4>{{ $footerName }}</h4>
            <p>
                Your trusted supplier of quality building materials.
                We supply cement, steel, timber, sand, tiles and more —
                to homeowners, contractors and builders at fair prices.
            </p>
        </div>

        <div>
            <h4>Quick Links</h4>
            <ul>
                <li><a href="{{ route('home') }}">Home</a></li>
                <li><a href="{{ route('products.index') }}">Products</a></li>
                <li><a href="{{ route('categories.index') }}">Categories</a></li>
                <li><a href="{{ route('home') }}#why-us">About Us</a></li>
                <li><a href="{{ route('home') }}#contact">Contact</a></li>
                @auth
                    <li><a href="{{ route('support.index') }}">Customer Care</a></li>
                @else
                    <li><a href="{{ route('home') }}#contact">Customer Care</a></li>
                @endauth
            </ul>
        </div>

        <div>
            <h4>Categories</h4>
            <ul>
                @forelse ($footerCats as $cat)
                    <li>
                        <a href="{{ route('products.index', ['category' => $cat->slug]) }}">
                            {{ $cat->name }}
                        </a>
                    </li>
                @empty
                    <li><a href="{{ route('categories.index') }}">Browse categories</a></li>
                @endforelse
            </ul>
        </div>

        <div>
            <h4>Contact Us</h4>
            <ul class="footer-contact">
                <li>
                    <span class="icon">📍</span>
                    <span>{{ $footerAddress }}</span>
                </li>
                <li>
                    <span class="icon">📞</span>
                    <span>
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $footerPhone) }}">{{ $footerPhone }}</a>
                    </span>
                </li>
                <li>
                    <span class="icon">✉️</span>
                    <span>
                        <a href="mailto:{{ $footerEmail }}">{{ $footerEmail }}</a>
                    </span>
                </li>
                <li>
                    <span class="icon">🕐</span>
                    <span>Mon – Sat: 8:00 AM – 6:00 PM</span>
                </li>
            </ul>
        </div>
    </div>

    <div class="footer-bottom">
        <div class="container">
            &copy; {{ date('Y') }} <strong>{{ $footerName }}</strong>. All rights reserved.
        </div>
    </div>
</footer>
