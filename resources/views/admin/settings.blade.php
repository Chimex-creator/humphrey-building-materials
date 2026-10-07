@extends('layouts.admin')

@section('title', 'Business Settings')

@section('content')
    <div class="admin-page-head">
        <h1>Business Settings</h1>
        <p>Update official business information. These values appear across the website.</p>
    </div>

    <div class="admin-card" style="max-width: 720px;">
        <form action="{{ route('admin.settings.update') }}" method="POST" enctype="multipart/form-data" novalidate>
            @csrf
            @method('PUT')

            {{-- Logo --}}
            <div class="form-group">
                <label>Business Logo</label>
                <div class="logo-manager">
                    <div class="logo-preview-box">
                        @php $logo = old('logo') ? null : $settings['logo_path']; @endphp
                        @if ($logo)
                            <img src="{{ asset('storage/' . $logo) }}" alt="Current logo" id="logoPreview" class="logo-preview-img">
                        @else
                            <div class="logo-preview-placeholder" id="logoPreviewPlaceholder">
                                <span class="logo-mark large">H</span>
                                <small>No logo uploaded — using default mark</small>
                            </div>
                            <img src="" alt="Logo preview" id="logoPreview" class="logo-preview-img" style="display:none;">
                        @endif
                    </div>

                    <div class="logo-controls">
                        <input type="file" name="logo" id="logoInput" accept="image/png,image/jpeg,image/svg+xml,image/webp"
                               class="{{ $errors->has('logo') ? 'is-invalid' : '' }}">
                        @error('logo')<span class="field-error">{{ $message }}</span>@enderror
                        <small class="field-help">PNG, JPG, SVG or WebP — max 2MB. Works on phone gallery too.</small>

                        @if ($settings['logo_path'])
                            <label class="form-check" style="margin-top: 10px;">
                                <input type="checkbox" name="remove_logo" value="1">
                                <span>Remove current logo</span>
                            </label>
                        @endif
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="business_name">Business Name <span class="req">*</span></label>
                <input type="text" id="business_name" name="business_name"
                       value="{{ old('business_name', $settings['business_name']) }}" required
                       class="{{ $errors->has('business_name') ? 'is-invalid' : '' }}">
                @error('business_name')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label for="business_email">Business Email <span class="req">*</span></label>
                <input type="email" id="business_email" name="business_email"
                       value="{{ old('business_email', $settings['business_email']) }}" required
                       class="{{ $errors->has('business_email') ? 'is-invalid' : '' }}">
                @error('business_email')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label for="business_phone">Business Phone</label>
                <input type="text" id="business_phone" name="business_phone"
                       value="{{ old('business_phone', $settings['business_phone']) }}"
                       class="{{ $errors->has('business_phone') ? 'is-invalid' : '' }}">
                @error('business_phone')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label for="business_address">Business Address</label>
                <textarea id="business_address" name="business_address" rows="3"
                          class="{{ $errors->has('business_address') ? 'is-invalid' : '' }}">{{ old('business_address', $settings['business_address']) }}</textarea>
                @error('business_address')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label for="receipt_note">Receipt / Invoice Note</label>
                <textarea id="receipt_note" name="receipt_note" rows="2"
                          class="{{ $errors->has('receipt_note') ? 'is-invalid' : '' }}">{{ old('receipt_note', $settings['receipt_note']) }}</textarea>
                @error('receipt_note')<span class="field-error">{{ $message }}</span>@enderror
                <small class="field-help">Shown at the bottom of receipts/invoices.</small>
            </div>

            <div class="form-group">
                <label for="low_stock_threshold">Low-Stock Alert Threshold <span class="req">*</span></label>
                <input type="number" id="low_stock_threshold" name="low_stock_threshold"
                       min="1" max="100000" step="1" required
                       value="{{ old('low_stock_threshold', $settings['low_stock_threshold']) }}"
                       class="{{ $errors->has('low_stock_threshold') ? 'is-invalid' : '' }}">
                @error('low_stock_threshold')<span class="field-error">{{ $message }}</span>@enderror
                <small class="field-help">A product is flagged as low stock when its quantity falls to this number or below. Used on the dashboard and the Inventory screen.</small>
            </div>

            <button type="submit" class="btn btn-primary">Save Settings</button>
        </form>
    </div>
@endsection

@push('scripts')
<script>
    // Live image preview before upload
    document.addEventListener('DOMContentLoaded', function () {
        var input = document.getElementById('logoInput');
        var preview = document.getElementById('logoPreview');
        var placeholder = document.getElementById('logoPreviewPlaceholder');
        if (!input || !preview) return;

        input.addEventListener('change', function () {
            if (this.files && this.files[0]) {
                var file = this.files[0];
                if (file.size > 2 * 1024 * 1024) {
                    alert('Image must be 2MB or smaller.');
                    this.value = '';
                    return;
                }
                var reader = new FileReader();
                reader.onload = function (e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                    if (placeholder) placeholder.style.display = 'none';
                };
                reader.readAsDataURL(file);
            }
        });
    });
</script>
@endpush
