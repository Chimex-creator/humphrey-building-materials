<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    /** List all categories with product counts. */
    public function index()
    {
        $categories = Category::withCount('products')->orderBy('name')->paginate(20);

        return view('admin.categories.index', compact('categories'));
    }

    /** Show "create category" form. */
    public function create()
    {
        return view('admin.categories.create');
    }

    /**
     * Save a new category.
     *
     * PHASE 11 — the slug is an explicit, editable field (suggested from
     * the name in the form, overridable, validated for format + uniqueness).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:categories,name'],
            'slug' => ['required', 'string', 'max:120', 'alpha_dash', 'unique:categories,slug'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $slug = $this->normalizeSlug($data['slug']);

        // Normalisation can empty a pathological input — fall back safely.
        if ($slug === '') {
            $slug = Str::slug($data['name']) ?: 'category';
        }

        Category::create([
            'name' => $data['name'],
            'slug' => $slug,
            'description' => $data['description'] ?? null,
        ]);

        return redirect()->route('admin.categories.index')->with('status', 'Category created successfully.');
    }

    /** Show edit form for a category. */
    public function edit(Category $category)
    {
        return view('admin.categories.edit', compact('category'));
    }

    /**
     * Update a category.
     *
     * PHASE 11 — name and slug are edited independently: the slug only
     * changes when the admin actually changes the field (no silent
     * re-slugging of a deliberately chosen URL).
     */
    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->ignore($category->id)],
            'slug' => ['required', 'string', 'max:120', 'alpha_dash', Rule::unique('categories', 'slug')->ignore($category->id)],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $category->name = $data['name'];
        $category->slug = $this->normalizeSlug($data['slug']) ?: $category->slug;
        $category->description = $data['description'] ?? null;
        $category->save();

        return redirect()->route('admin.categories.index')->with('status', 'Category updated successfully.');
    }

    /** Delete a category (only if it has no products). */
    public function destroy(Category $category)
    {
        // Products FK cascade would wipe the catalogue — block if in use.
        if ($category->products()->exists()) {
            return back()->withErrors([
                'category' => "\"{$category->name}\" still has products. Move or delete them first.",
            ]);
        }

        $category->delete();

        return redirect()->route('admin.categories.index')->with('status', 'Category deleted.');
    }

    /**
     * PHASE 11 — bulk delete, respecting the same product guard as the
     * single delete. Categories have no activate/deactivate state (products
     * carry their own status), so bulk only offers deletion where safe.
     */
    public function bulk(Request $request, ActivityLogger $logger)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['delete'])],
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
        ]);

        $categories = Category::withCount('products')
            ->whereIn('id', $data['category_ids'])
            ->get();

        $deletable = $categories->where('products_count', 0);
        $blocked = $categories->where('products_count', '>', 0);

        $deleted = 0;
        if ($deletable->isNotEmpty()) {
            Category::whereIn('id', $deletable->pluck('id'))->delete();
            $deleted = $deletable->count();

            $logger->log('categories.bulk_delete', null, sprintf(
                '%d categor%s deleted in one bulk action.',
                $deleted,
                $deleted === 1 ? 'y' : 'ies'
            ), [
                'count' => $deleted,
                'ids' => $deletable->pluck('id')->all(),
            ]);
        }

        $message = $deleted > 0
            ? $deleted.' categor'.($deleted === 1 ? 'y' : 'ies').' deleted.'
            : 'Nothing to delete.';

        if ($blocked->isNotEmpty()) {
            $message .= ' Skipped '.$blocked->count().' with attached products — '
                .'move or delete their products first.';
        }

        return back()->with($blocked->isEmpty() ? 'status' : 'error', $message);
    }

    /** Lowercase + hyphenate an admin-typed slug. */
    private function normalizeSlug(string $slug): string
    {
        return Str::lower(trim($slug));
    }
}
