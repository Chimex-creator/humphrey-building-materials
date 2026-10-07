@extends('layouts.admin')

@section('title', 'Add Category')

@section('content')
    <div class="admin-page-head">
        <h1>Add Category</h1>
        <p>Create a new product category (e.g. Cement, Steel, Tiles).</p>
    </div>

    <div class="admin-card" style="max-width: 640px;">
        <form action="{{ route('admin.categories.store') }}" method="POST" novalidate>
            @csrf

            <div class="form-group">
                <label for="name">Category Name <span class="req">*</span></label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="100"
                       class="{{ $errors->has('name') ? 'is-invalid' : '' }}"
                       placeholder="e.g. Roofing Materials">
                @error('name')<span class="field-error">{{ $message }}</span>@enderror
                <small class="field-help">The URL slug is generated automatically from the name.</small>
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="3" maxlength="500"
                          class="{{ $errors->has('description') ? 'is-invalid' : '' }}"
                          placeholder="Short blurb shown on the homepage category card...">{{ old('description') }}</textarea>
                @error('description')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create Category</button>
                <a href="{{ route('admin.categories.index') }}" class="btn btn-clear">Cancel</a>
            </div>
        </form>
    </div>
@endsection
