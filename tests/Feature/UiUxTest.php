<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * UI/UX polish checks: every page renders, guests are sent to the login
 * screen, error pages are branded, and the views stay free of placeholders
 * or unsafe output.
 */
class UiUxTest extends TestCase
{
    use RefreshDatabase;

    /** Parameterless GET routes whose names live under the admin prefix. */
    private function adminPages(): array
    {
        $pages = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (str_contains($route->uri(), '{')) {
                continue;
            }
            $name = $route->getName();
            if ($name && str_starts_with($name, 'admin.')) {
                $pages[$name] = $route->uri();
            }
        }

        return $pages;
    }

    /** Every parameterless GET route except the framework's signed file routes. */
    private function publicPages(): array
    {
        $pages = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (str_contains($route->uri(), '{') || str_starts_with($route->uri(), 'storage/')) {
                continue;
            }
            $pages[$route->getName() ?? '(unnamed)'] = $route->uri();
        }

        return $pages;
    }

    private function viewFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('resources/views'))
        );
        foreach ($iterator as $file) {
            if (! $file->isDir() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /* ------------------------------------------------------------------
     | Pages actually render
     * ---------------------------------------------------------------- */

    public function test_every_admin_page_renders_for_an_admin(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $pages = $this->adminPages();
        $this->assertNotEmpty($pages);

        foreach ($pages as $name => $uri) {
            $response = $this->actingAs($admin)->get('/'.ltrim($uri, '/'));
            $status = $response->getStatusCode();
            $this->assertSame(200, $status, "GET $uri ($name) should render for the admin.");
        }
    }

    public function test_guests_are_sent_to_login_from_every_admin_page(): void
    {
        foreach ($this->adminPages() as $name => $uri) {
            $response = $this->get('/'.ltrim($uri, '/'));
            $this->assertTrue(
                $response->isRedirect(route('login')),
                "GET $uri ($name) must bounce guests to the login screen (got {$response->status()})."
            );
        }
    }

    public function test_no_public_page_stops_working(): void
    {
        foreach ($this->publicPages() as $name => $uri) {
            $status = $this->get('/'.ltrim($uri, '/'))->status();
            $this->assertLessThan(
                500,
                $status,
                "GET $uri ($name) returned HTTP $status — pages must not blow up."
            );
        }
    }

    /* ------------------------------------------------------------------
     | Branded error pages
     * ---------------------------------------------------------------- */

    public function test_the_error_pages_are_branded(): void
    {
        // 404 and 403 are reached for real; 419 cannot happen in tests
        // (CSRF checking is disabled for the unit-test run) so that view is
        // rendered directly to prove it uses the same branded frame.
        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('empty-state')
            ->assertSee('Page not found');

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'is_active' => true]);
        $this->actingAs($customer)
            ->get(route('admin.orders.index'))
            ->assertForbidden()
            ->assertSee('empty-state')
            ->assertSee('Access denied');

        view()->share('errors', new ViewErrorBag);
        $html = view('errors.419')->render();
        $this->assertStringContainsString('empty-state', $html);
        $this->assertStringContainsString('session expired', $html);
    }

    /* ------------------------------------------------------------------
     | View hygiene
     * ---------------------------------------------------------------- */

    public function test_views_contain_no_placeholders_or_half_finished_copy(): void
    {
        $banned = ['lorem ipsum', 'coming soon', 'to do', 'todo:', 'fixme', 'placeholder text', 'xxxxx'];

        foreach ($this->viewFiles() as $path) {
            $content = strtolower(file_get_contents($path));
            foreach ($banned as $needle) {
                $this->assertFalse(
                    str_contains($content, $needle),
                    basename($path)." still contains the placeholder text \"$needle\"."
                );
            }
        }
    }

    public function test_unescaped_blade_is_only_used_for_known_safe_fragments(): void
    {
        $allowed = [
            'CategoryIcons::for(',
            'nl2br(e(',
            '$feature[\'icon\']',
        ];

        foreach ($this->viewFiles() as $path) {
            if (! preg_match_all('/\{!!\s*(.+?)\s*!!\}/s', file_get_contents($path), $matches)) {
                continue;
            }
            foreach ($matches[1] as $expression) {
                $safe = false;
                foreach ($allowed as $needle) {
                    if (str_contains($expression, $needle)) {
                        $safe = true;
                        break;
                    }
                }
                $this->assertTrue(
                    $safe,
                    basename($path).' prints unescaped output: {!! '.$expression.' !!}'
                );
            }
        }
    }

    public function test_every_route_used_in_a_view_exists(): void
    {
        foreach ($this->viewFiles() as $path) {
            if (! preg_match_all('/route\(\s*[\'"]([a-zA-Z0-9._\-]+)[\'"]/', file_get_contents($path), $matches)) {
                continue;
            }
            foreach ($matches[1] as $name) {
                $this->assertTrue(
                    Route::has($name),
                    basename($path)." links to a route that does not exist: $name"
                );
            }
        }
    }

    public function test_the_admin_sidebar_links_to_the_main_areas(): void
    {
        $html = file_get_contents(base_path('resources/views/layouts/admin.blade.php'));

        foreach (['admin.dashboard', 'admin.orders.index', 'admin.inventory.index', 'admin.activity-logs.index'] as $name) {
            $this->assertStringContainsString(
                "route('$name'",
                $html,
                "The sidebar is missing a link to $name."
            );
        }
    }
}
