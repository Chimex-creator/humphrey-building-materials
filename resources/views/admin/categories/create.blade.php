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
            </div>

            {{-- PHASE 11 — explicit, editable slug (suggested from the name). --}}
            <div class="form-group">
                <label for="slug">URL Slug <span class="req">*</span></label>
                <input type="text" id="slug" name="slug" value="{{ old('slug') }}" required maxlength="120"
                       data-slug-touched="{{ old('slug') ? '1' : '0' }}"
                       class="{{ $errors->has('slug') ? 'is-invalid' : '' }}"
                       placeholder="e.g. roofing-materials">
                @error('slug')<span class="field-error">{{ $message }}</span>@enderror
                <small class="field-help">
                    Letters, numbers, dashes and underscores only. Suggested from the name —
                    edit it if you prefer a different URL (e.g. <code>products/roofing-materials</code>).
                </small>
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

@push('scripts')
<script>
    (function () {
        var name = document.getElementById('name');
        var slug = document.getElementById('slug');
        if (!name || !slug) return;

        var touched = slug.value !== '' || slug.dataset.slugTouched === '1';
        slug.addEventListener('input', function () { touched = true; });
        name.addEventListener('input', function () {
            if (touched) return;
            slug.value = name.value.toLowerCase()
                .replace(/[^a-z0-9\s-]/g, '')
                .trim()
                .replace(/[\s-]+/g, '-');
        });
    })();
</script>
@endpush
