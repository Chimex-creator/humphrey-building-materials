<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    /** Save a new category. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:categories,name'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        Category::create([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['name']),
            'description' => $data['description'] ?? null,
        ]);

        return redirect()->route('admin.categories.index')->with('status', 'Category created successfully.');
    }

    /** Show edit form for a category. */
    public function edit(Category $category)
    {
        return view('admin.categories.edit', compact('category'));
    }

    /** Update a category. */
    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:categories,name,'.$category->id],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        // Keep slug in sync when the name changes (URLs stay clean).
        if ($category->name !== $data['name']) {
            $category->slug = $this->uniqueSlug($data['name'], $category->id);
        }

        $category->name = $data['name'];
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

    /** Build a slug that does not clash with existing rows. */
    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $i = 2;

        while (
            Category::where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
