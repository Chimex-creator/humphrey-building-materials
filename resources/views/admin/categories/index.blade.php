@extends('layouts.admin')

@section('title', 'Categories')

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <h1>Product Categories</h1>
            <p>Organise the catalogue into categories customers can browse.</p>
        </div>
        <a href="{{ route('admin.categories.create') }}" class="btn btn-primary">+ Add Category</a>
    </div>

    <div class="admin-card">
        @if ($categories->isEmpty())
            <div class="empty-state">
                <h3>No categories yet</h3>
                <p>Create your first category to start organising products.</p>
                <a href="{{ route('admin.categories.create') }}" class="btn btn-navy">Add Category</a>
            </div>
        @else
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Slug</th>
                            <th>Description</th>
                            <th>Products</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($categories as $category)
                            <tr>
                                <td><strong>{{ $category->name }}</strong></td>
                                <td><code>{{ $category->slug }}</code></td>
                                <td>
                                    @if ($category->description)
                                        {{ \Illuminate\Support\Str::limit($category->description, 60) }}
                                    @else
                                        <span class="empty-inline">—</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $category->products_count > 0 ? 'badge-green' : 'badge-red' }}">
                                        {{ $category->products_count }}
                                    </span>
                                </td>
                                <td class="actions-cell">
                                    <a href="{{ route('admin.categories.edit', $category) }}" class="btn btn-sm btn-navy">Edit</a>
                                    <form action="{{ route('admin.categories.destroy', $category) }}" method="POST"
                                          class="inline-delete"
                                          onsubmit="return confirm('Delete category &ldquo;{{ $category->name }}&rdquo;?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger"
                                                {{ $category->products_count > 0 ? 'disabled title="Has products"' : '' }}>
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                @if ($categories->onFirstPage())
                    <span class="page-btn disabled">&larr; Prev</span>
                @else
                    <a class="page-btn" href="{{ $categories->previousPageUrl() }}">&larr; Prev</a>
                @endif
                <span class="page-info">Page {{ $categories->currentPage() }} of {{ $categories->lastPage() }}</span>
                @if ($categories->hasMorePages())
                    <a class="page-btn" href="{{ $categories->nextPageUrl() }}">Next &rarr;</a>
                @else
                    <span class="page-btn disabled">Next &rarr;</span>
                @endif
            </div>
        @endif
    </div>
@endsection
