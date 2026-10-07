@extends('layouts.admin')

@section('title', 'Edit Category')

@section('content')
    <div class="admin-page-head">
        <h1>Edit Category</h1>
        <p>Update <strong>{{ $category->name }}</strong> ({{ $category->products_count ?? $category->products()->count() }} product(s)).</p>
    </div>

    <div class="admin-card" style="max-width: 640px;">
        <form action="{{ route('admin.categories.update', $category) }}" method="POST" novalidate>
            @csrf
            @method('PUT')

            <div class="form-group">
                <label for="name">Category Name <span class="req">*</span></label>
                <input type="text" id="name" name="name" value="{{ old('name', $category->name) }}" required maxlength="100"
                       class="{{ $errors->has('name') ? 'is-invalid' : '' }}">
                @error('name')<span class="field-error">{{ $message }}</span>@enderror
                @if ($category->name !== old('name', $category->name))
                    <small class="field-help">Changing the name will also update the URL slug.</small>
                @endif
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="3" maxlength="500"
                          class="{{ $errors->has('description') ? 'is-invalid' : '' }}">{{ old('description', $category->description) }}</textarea>
                @error('description')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label>Current Slug</label>
                <input type="text" value="{{ $category->slug }}" readonly class="readonly">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="{{ route('admin.categories.index') }}" class="btn btn-clear">Cancel</a>
            </div>
        </form>
    </div>
@endsection
