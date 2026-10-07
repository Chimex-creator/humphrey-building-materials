<?php

namespace App\Http\Controllers;

use App\Models\BusinessContact;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;

class HomeController extends Controller
{
    /** Public homepage. */
    public function index()
    {
        // Categories from DB (only ones with products look useful on the homepage).
        $categories = Category::withCount(['products as active_products_count' => function ($q) {
            $q->where('status', 'active');
        }])
            ->orderBy('name')
            ->get();

        // Up to 4 active featured products.
        $featuredProducts = Product::with('category')
            ->where('status', 'active')
            ->where('is_featured', true)
            ->latest()
            ->limit(4)
            ->get();

        // Live counters for the hero (honest numbers, not fake marketing stats).
        $stats = [
            'categories' => Category::count(),
            'products' => Product::where('status', 'active')->count(),
            'customers' => User::where('role', 'customer')->count(),
        ];

        // Static "why us" marketing cards (content, not data).
        $features = $this->features();

        // Contact details from Business Settings (Master Scope §1).
        $phone = Setting::get('business_phone', '+2348153667923');
        $email = Setting::get('business_email', 'humphreybuildingmaterials@gmail.com');
        $address = Setting::get('business_address', 'Eda plaza beside abacha road mararaba, nasarawa');

        // Final Spec §24 — labelled customer-care contacts (DB-driven).
        $contacts = BusinessContact::active()->orderBy('contact_order')->orderBy('id')->get();

        return view('home', compact(
            'categories', 'featuredProducts', 'features', 'stats', 'phone', 'email', 'address', 'contacts'
        ));
    }

    /** Browse all product categories. */
    public function categories()
    {
        $categories = Category::withCount(['products as active_products_count' => function ($q) {
            $q->where('status', 'active');
        }])
            ->orderBy('name')
            ->get();

        return view('categories.index', compact('categories'));
    }

    /** Static feature cards for the homepage. */
    private function features(): array
    {
        return [
            [
                'title' => 'Quality Materials',
                'description' => 'We stock only trusted, high-quality materials that meet building standards.',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/><path d="m9 12 2 2 4-4"/></svg>',
            ],
            [
                'title' => 'Fair Prices',
                'description' => 'Competitive pricing for both small and bulk orders, with discounts available.',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.6 13.4 12 22l-9-9V3h10z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>',
            ],
            [
                'title' => 'Fast Delivery',
                'description' => 'Quick and reliable delivery to your home or construction site.',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="1" y="6" width="15" height="10" rx="1"/><path d="M16 10h4l3 3v3h-7z"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="18" r="2"/></svg>',
            ],
            [
                'title' => 'Expert Advice',
                'description' => 'Our experienced team helps you choose the right materials for your project.',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>',
            ],
        ];
    }
}
