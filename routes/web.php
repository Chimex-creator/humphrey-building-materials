<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\BusinessContactController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeliveryController;
use App\Http\Controllers\Admin\DeliverySettingsController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReturnController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\StockAdjustmentController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CustomerCareController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaystackController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReturnRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Customer-facing pages. Admin routes are grouped under /admin below.
|
*/

// Homepage + category browser (logic lives in HomeController).
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/categories', [HomeController::class, 'categories'])->name('categories.index');

// Product catalogue (search, filter, sort) and product details.
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/{slug}', [ProductController::class, 'show'])->name('products.show');

/*
|--------------------------------------------------------------------------
| PHASE 6 — Shopping cart & checkout
|--------------------------------------------------------------------------
| Cart is session-based (works for guests). Checkout creates an Order row
| and decrements stock. Admin order management ships in Phase 7.
*/
Route::post('/cart', [CartController::class, 'store'])->name('cart.store');
Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
Route::patch('/cart/{productId}', [CartController::class, 'update'])->name('cart.update');
Route::delete('/cart/{productId}', [CartController::class, 'destroy'])->name('cart.destroy');
Route::delete('/cart', [CartController::class, 'clear'])->name('cart.clear');

/*
|--------------------------------------------------------------------------
| PHASE 6 — Checkout
|--------------------------------------------------------------------------
| Server-side rule (Master Scope §12): a guest can fill a cart, but only a
| VERIFIED customer may reach checkout. `auth` sends guests to login,
| `verified` sends unverified accounts to /email/verify.
*/
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
    Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
    Route::get('/checkout/success/{orderId}', [CheckoutController::class, 'success'])->name('checkout.success');
});

/*
|--------------------------------------------------------------------------
| PHASE 2 — Authentication routes
|--------------------------------------------------------------------------
| guest  → only for logged-out people (login/register forms)
| auth   → only for logged-in people
| verified → email must be verified first
*/

use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\GoogleLoginController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\ProfileController;

// ----- Guest only (logged-out users) -----
Route::middleware('guest')->group(function () {
    // Registration (Phase 2): creates a PENDING registration, never a User
    // row — the account only appears after the signed email link is clicked.
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);
    Route::get('/register/pending', [RegisterController::class, 'pending'])
        ->name('registration.pending');
    Route::post('/register/resend', [RegisterController::class, 'resend'])
        ->middleware('throttle:6,1')->name('registration.resend');

    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    // "Continue with Google" — customers only; the controller refuses any
    // admin/staff account and never changes a role.
    Route::get('/auth/google', [GoogleLoginController::class, 'redirect'])
        ->name('google.redirect');
    Route::get('/auth/google/callback', [GoogleLoginController::class, 'callback'])
        ->name('google.callback');

    Route::get('/forgot-password', [ForgotPasswordController::class, 'show'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'send']);

    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'show'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'update'])->name('password.update');
});

// Completing a pending registration. The controller validates the HMAC
// signature itself (accepting both relative-signed links — host/scheme
// agnostic — and older absolute ones), plus id/hash, expiry and one-time
// use; a `signed` middleware here would 403 whenever the link is opened
// through a different protocol/host than the one that generated it.
Route::get('/register/verify/{id}/{hash}', [RegisterController::class, 'verify'])
    ->middleware('throttle:10,1')->name('registration.verify');

// ----- Logged-in users only (email NOT yet required) -----
// Logout and the verification screens must stay reachable for an
// unverified account, otherwise the customer could never complete it.
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // Email verification
    Route::get('/email/verify', [VerificationController::class, 'show'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [VerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/resend', [VerificationController::class, 'resend'])
        ->middleware('throttle:6,1')->name('verification.resend');
});

// ----- Verified customers only -----
// Everything that belongs to the customer account (Master Scope §12).
Route::middleware(['auth', 'verified'])->group(function () {
    // Profile & password
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile/avatar', [ProfileController::class, 'removeAvatar'])->name('profile.avatar.remove');
    Route::get('/change-password', [ChangePasswordController::class, 'show'])->name('password.change');
    Route::post('/change-password', [ChangePasswordController::class, 'update'])->name('password.change.update');

    // PHASE 7 — customer "My Orders"
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');

    // PHASE 8 — customer payments (Paystack only — Final Spec §18)
    Route::get('/orders/{order}/payment', [PaymentController::class, 'show'])->name('orders.payment');
    Route::post('/orders/{order}/payment/paystack', [PaymentController::class, 'payWithPaystack'])->name('orders.payment.paystack');

    // PHASE 8 — in-app notifications (🔔 bell)
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/read-selected', [NotificationController::class, 'readSelected'])->name('notifications.read-selected');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');

    // PHASE 10 — customer return requests (staff review them under /admin)
    Route::get('/orders/{order}/return', [ReturnRequestController::class, 'create'])->name('orders.return.create');
    Route::post('/orders/{order}/return', [ReturnRequestController::class, 'store'])->name('orders.return.store');

    // PHASE 6 — customer care: contact info, complaint form, own records.
    Route::get('/customer-care', [CustomerCareController::class, 'index'])->name('support.index');
    Route::post('/customer-care', [CustomerCareController::class, 'store'])->name('support.store');
    Route::get('/customer-care/{ticket}', [CustomerCareController::class, 'show'])->name('support.show');
});

/*
|--------------------------------------------------------------------------
| PHASE 8 — Paystack return/webhook
|--------------------------------------------------------------------------
| callback → customer's browser (no auth: the session may have expired;
|            it is still safe — we verify with Paystack's API anyway)
| webhook  → Paystack's servers (CSRF-exempt, signature-validated)
*/
Route::get('/paystack/callback', [PaystackController::class, 'callback'])->name('paystack.callback');
Route::post('/paystack/webhook', [PaystackController::class, 'webhook'])->name('paystack.webhook');

// Legacy inventory landing page — the real screen lives at /admin/inventory.
Route::get('/staff/inventory', function () {
    return redirect()->route('admin.inventory.index');
})->middleware('role:admin,inventory')->name('inventory.home');

// Legacy sales landing page — the real screens live under /admin
// (orders, payments, deliveries, reports).
Route::get('/staff/sales', function () {
    return redirect()->route('admin.orders.index');
})->middleware('role:admin,sales')->name('sales.home');

/*
|--------------------------------------------------------------------------
| PHASE 3 — Admin / Management routes
|--------------------------------------------------------------------------
| All under /admin, protected by auth + verified + role middleware.
*/
Route::prefix('admin')->name('admin.')->middleware(['auth', 'verified'])->group(function () {

    // Dashboard — any staff member (admin, inventory, sales)
    Route::get('/', [DashboardController::class, 'index'])
        ->middleware('role:admin,inventory,sales')
        ->name('dashboard');

    // Business settings — admin only
    Route::get('/settings', [SettingController::class, 'edit'])
        ->middleware('role:admin')
        ->name('settings');
    Route::put('/settings', [SettingController::class, 'update'])
        ->middleware('role:admin')
        ->name('settings.update');

    // Final Spec §24 — customer-care contacts (DB-driven, never hard-coded)
    Route::middleware('role:admin')->group(function () {
        Route::get('/contacts', [BusinessContactController::class, 'index'])->name('contacts.index');
        Route::post('/contacts/bulk', [BusinessContactController::class, 'bulk'])->name('contacts.bulk');
        Route::get('/contacts/create', [BusinessContactController::class, 'create'])->name('contacts.create');
        Route::post('/contacts', [BusinessContactController::class, 'store'])->name('contacts.store');
        Route::get('/contacts/{contact}/edit', [BusinessContactController::class, 'edit'])->name('contacts.edit');
        Route::put('/contacts/{contact}', [BusinessContactController::class, 'update'])->name('contacts.update');
        Route::post('/contacts/{contact}/toggle', [BusinessContactController::class, 'toggle'])->name('contacts.toggle');
        Route::delete('/contacts/{contact}', [BusinessContactController::class, 'destroy'])->name('contacts.destroy');
    });

    /*
    |----------------------------------------------------------------------
    | PHASES 14–15 — Delivery geography settings (admin only)
    |
    | Zones, states and areas decide what delivery costs and where the
    | shop delivers at all. Everything here is editable without code.
    |----------------------------------------------------------------------
    */
    Route::middleware('role:admin')->group(function () {
        Route::get('/delivery-settings', [DeliverySettingsController::class, 'index'])
            ->name('delivery-settings.index');
        Route::post('/delivery-settings/zones', [DeliverySettingsController::class, 'storeZone'])
            ->name('delivery-settings.zones.store');
        Route::patch('/delivery-settings/zones/{zone}', [DeliverySettingsController::class, 'updateZone'])
            ->name('delivery-settings.zones.update');
        Route::delete('/delivery-settings/zones/{zone}', [DeliverySettingsController::class, 'destroyZone'])
            ->name('delivery-settings.zones.destroy');
        Route::post('/delivery-settings/states', [DeliverySettingsController::class, 'storeState'])
            ->name('delivery-settings.states.store');
        Route::patch('/delivery-settings/states/{state}', [DeliverySettingsController::class, 'updateState'])
            ->name('delivery-settings.states.update');
        Route::delete('/delivery-settings/states/{state}', [DeliverySettingsController::class, 'destroyState'])
            ->name('delivery-settings.states.destroy');
        Route::post('/delivery-settings/areas', [DeliverySettingsController::class, 'storeArea'])
            ->name('delivery-settings.areas.store');
        Route::patch('/delivery-settings/areas/{area}', [DeliverySettingsController::class, 'updateArea'])
            ->name('delivery-settings.areas.update');
        Route::delete('/delivery-settings/areas/{area}', [DeliverySettingsController::class, 'destroyArea'])
            ->name('delivery-settings.areas.destroy');
    });

    // PHASE 12 - audit & activity log - admin only (Master Prompt 47)
    Route::get('/activity-logs', [ActivityLogController::class, 'index'])
        ->middleware('role:admin')
        ->name('activity-logs.index');

    // Users / staff — admin only
    Route::get('/users', [UserController::class, 'index'])
        ->middleware('role:admin')
        ->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])
        ->middleware('role:admin')
        ->name('users.create');
    Route::post('/users', [UserController::class, 'store'])
        ->middleware('role:admin')
        ->name('users.store');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])
        ->middleware('role:admin')
        ->name('users.edit');
    Route::put('/users/{user}', [UserController::class, 'update'])
        ->middleware('role:admin')
        ->name('users.update');
    // Final Spec §29 — admins recover customer access only via reset link.
    Route::post('/users/{user}/send-reset', [UserController::class, 'sendResetLink'])
        ->middleware('role:admin')
        ->name('users.send-reset');

    // Final Spec §39 — bulk activate / deactivate accounts (admins only).
    Route::post('/users/bulk', [UserController::class, 'bulk'])
        ->middleware('role:admin')
        ->name('users.bulk');

    // Categories — admin + inventory
    Route::middleware('role:admin,inventory')->group(function () {
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::get('/categories/create', [CategoryController::class, 'create'])->name('categories.create');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::get('/categories/{category}/edit', [CategoryController::class, 'edit'])->name('categories.edit');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
    });

    // Products — admin + inventory
    Route::middleware('role:admin,inventory')->group(function () {
        Route::get('/products', [AdminProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [AdminProductController::class, 'create'])->name('products.create');
        Route::post('/products', [AdminProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [AdminProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [AdminProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{product}', [AdminProductController::class, 'destroy'])->name('products.destroy');
    });

    /*
    |----------------------------------------------------------------------
    | PHASE 9/10 — Inventory, stock and returns (§35–§40, §56)
    | Stock overview, manual adjustments with an audit trail, and
    | inventory-related returns. Stock enters through adjustments or the
    | product form. No purchasing and no supplier module.
    |----------------------------------------------------------------------
    */
    Route::middleware('role:admin,inventory')->group(function () {
        Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');

        Route::get('/stock-adjustments', [StockAdjustmentController::class, 'index'])->name('stock-adjustments.index');
        Route::get('/stock-adjustments/create', [StockAdjustmentController::class, 'create'])->name('stock-adjustments.create');
        Route::post('/stock-adjustments', [StockAdjustmentController::class, 'store'])->name('stock-adjustments.store');
    });

    /*
    |----------------------------------------------------------------------
    | PHASE 10 — Returns & inspection (§28, §40)
    | One screen for the whole returns workflow: requested → in review →
    | approved/rejected → received → inspection → completed. Only resellable
    | quantities return to sellable stock. No monetary refunds here — money
    | is handled outside the website (§27).
    |----------------------------------------------------------------------
    */
    Route::middleware('role:admin,inventory,sales')->group(function () {
        Route::get('/returns', [ReturnController::class, 'index'])->name('returns.index');
        Route::get('/returns/create', [ReturnController::class, 'create'])->name('returns.create');
        Route::post('/returns', [ReturnController::class, 'store'])->name('returns.store');
        Route::post('/returns/{return}/review', [ReturnController::class, 'review'])->name('returns.review');
        Route::post('/returns/{return}/approve', [ReturnController::class, 'approve'])->name('returns.approve');
        Route::post('/returns/{return}/decline', [ReturnController::class, 'decline'])->name('returns.decline');
        Route::post('/returns/{return}/receive', [ReturnController::class, 'receive'])->name('returns.receive');
        Route::post('/returns/{return}/inspect', [ReturnController::class, 'inspect'])->name('returns.inspect');
        Route::post('/returns/{return}/complete', [ReturnController::class, 'complete'])->name('returns.complete');
    });

    // PHASE 7 — Orders — admin + sales
    Route::middleware('role:admin,sales')->group(function () {
        Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [AdminOrderController::class, 'show'])->name('orders.show');
        Route::patch('/orders/{order}/status', [AdminOrderController::class, 'updateStatus'])->name('orders.status');
        Route::post('/orders/{order}/delivery-fee', [AdminOrderController::class, 'confirmDeliveryFee'])->name('orders.delivery-fee');
    });

    /*
    |----------------------------------------------------------------------
    | PHASE 7 (completion) - Deliveries (§22, §45)
    |
    | The delivery board sits next to orders: one record per delivery order,
    | holding the promised date, the person taking it and notes. Pickup
    | orders never appear here. There are NO delivery trips (§22).
    |----------------------------------------------------------------------
    */
    Route::middleware('role:admin,sales')->group(function () {
        Route::get('/deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
        Route::get('/deliveries/{delivery}', [DeliveryController::class, 'show'])->name('deliveries.show');
        Route::patch('/deliveries/{delivery}', [DeliveryController::class, 'update'])->name('deliveries.update');
    });

    // PHASE 8 — Payments — admin + sales
    Route::middleware('role:admin,sales')->group(function () {
        Route::get('/payments', [AdminPaymentController::class, 'index'])->name('payments.index');
        Route::patch('/payments/{payment}/status', [AdminPaymentController::class, 'updateStatus'])->name('payments.status');
        Route::patch('/payments/{payment}/verify', [AdminPaymentController::class, 'verifyWithPaystack'])->name('payments.verify');
    });

    /*
    |----------------------------------------------------------------------
    | PHASE 11 — Sales reports & charts — admin + sales
    |
    | Read-only. Money figures come from paid payments, order counts from
    | orders placed; both are scoped to the one resolved period so the
    | chart, the cards and the CSV can never disagree.
    |----------------------------------------------------------------------
    */
    Route::middleware('role:admin,sales')->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
    });

    /*
    |----------------------------------------------------------------------
    | PHASE 12 — Customers — admin + sales
    |
    | The directory behind the sidebar link that used to say "Customers -
    | Later". Read-mostly: anyone selling may browse a customer's history,
    | but only an admin can switch an account off (staff accounts stay on
    | /admin/users). Route model binding is scoped in the controller, so a
    | staff id here is a 404 rather than a mystery profile.
    |----------------------------------------------------------------------
    */
    Route::middleware('role:admin,sales')->group(function () {
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/{user}', [CustomerController::class, 'show'])->name('customers.show');
    });

    Route::patch('/customers/{user}/status', [CustomerController::class, 'updateStatus'])
        ->middleware('role:admin')
        ->name('customers.status');

    // Final Spec §39 — bulk activate/deactivate (admins only, audited).
    Route::post('/customers/bulk', [CustomerController::class, 'bulk'])
        ->middleware('role:admin')
        ->name('customers.bulk');

    // Final Spec §39 — bulk activate/deactivate products (admin + inventory).
    Route::post('/products/bulk', [AdminProductController::class, 'bulk'])
        ->middleware('role:admin,inventory')
        ->name('products.bulk');

    /*
    |----------------------------------------------------------------------
    | PHASE 6 — Customer Care (admin / sales)
    |
    | Staff respond to complaints and move them through
    | open → in progress → resolved (Final Spec §42 — three statuses).
    | Resolving is always a deliberate action; notifications never
    | resolve a ticket by themselves.
    |----------------------------------------------------------------------
    */
    Route::middleware('role:admin,sales')->group(function () {
        Route::get('/support', [SupportController::class, 'index'])->name('support.index');
        Route::get('/support/{ticket}', [SupportController::class, 'show'])->name('support.show');
        Route::patch('/support/{ticket}', [SupportController::class, 'update'])->name('support.update');
    });
});
