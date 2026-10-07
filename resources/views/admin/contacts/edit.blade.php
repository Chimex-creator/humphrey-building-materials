@extends('layouts.admin')

@section('title', 'Edit Contact')

@section('content')
    <div class="admin-page-head">
        <h1>Edit Contact</h1>
        <p>Update the label, number, order or status of <strong>{{ $contact->label }}</strong>.</p>
    </div>

    <div class="admin-card" style="max-width: 640px;">
        <form action="{{ route('admin.contacts.update', $contact) }}" method="POST" novalidate>
            @csrf
            @method('PUT')

            <div class="form-group">
                <label for="label">Label <span class="req">*</span></label>
                <input type="text" id="label" name="label" required maxlength="60"
                       value="{{ old('label', $contact->label) }}"
                       class="{{ $errors->has('label') ? 'is-invalid' : '' }}">
                @error('label')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label for="phone">Phone Number <span class="req">*</span></label>
                <input type="text" id="phone" name="phone" required maxlength="30"
                       value="{{ old('phone', $contact->phone) }}"
                       class="{{ $errors->has('phone') ? 'is-invalid' : '' }}">
                @error('phone')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label for="contact_order">Display Order <span class="req">*</span></label>
                    <input type="number" id="contact_order" name="contact_order" required min="1" max="999"
                           value="{{ old('contact_order', $contact->contact_order) }}"
                           class="{{ $errors->has('contact_order') ? 'is-invalid' : '' }}">
                    @error('contact_order')<span class="field-error">{{ $message }}</span>@enderror
                    <small class="field-help">Lower numbers show first.</small>
                </div>

                <div class="form-group">
                    <label for="status">Status <span class="req">*</span></label>
                    <select id="status" name="status" class="{{ $errors->has('status') ? 'is-invalid' : '' }}">
                        <option value="active" {{ old('status', $contact->status) === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ old('status', $contact->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                    @error('status')<span class="field-error">{{ $message }}</span>@enderror
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="{{ route('admin.contacts.index') }}" class="btn btn-clear">Cancel</a>
            </div>
        </form>
    </div>
@endsection
