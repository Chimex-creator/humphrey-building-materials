@extends('layouts.admin')

@section('title', 'Edit Product')

@section('content')
    <div class="admin-page-head">
        <h1>Edit Product</h1>
        <p>Update details for <strong>{{ $product->name }}</strong>.</p>
    </div>

    <div class="admin-card" style="max-width: 760px;">
        <form action="{{ route('admin.products.update', $product) }}" method="POST" enctype="multipart/form-data" novalidate>
            @csrf
            @method('PUT')

            <div class="form-group">
                <label for="name">Product Name <span class="req">*</span></label>
                <input type="text" id="name" name="name" value="{{ old('name', $product->name) }}" required maxlength="150"
                       class="{{ $errors->has('name') ? 'is-invalid' : '' }}">
                @error('name')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label for="category_id">Category <span class="req">*</span></label>
                <select id="category_id" name="category_id" required
                        class="{{ $errors->has('category_id') ? 'is-invalid' : '' }}">
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}" {{ (string) old('category_id', $product->category_id) === (string) $cat->id ? 'selected' : '' }}>
                            {{ $cat->name }}
                        </option>
                    @endforeach
                </select>
                @error('category_id')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="4" maxlength="2000"
                          class="{{ $errors->has('description') ? 'is-invalid' : '' }}">{{ old('description', $product->description) }}</textarea>
                @error('description')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label for="brand">Brand <span class="optional">(optional)</span></label>
                    <input type="text" id="brand" name="brand" value="{{ old('brand', $product->brand) }}" maxlength="100"
                           placeholder="e.g. Dangote, Lafarge..."
                           class="{{ $errors->has('brand') ? 'is-invalid' : '' }}">
                    @error('brand')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="unit">Unit <span class="optional">(optional)</span></label>
                    <input type="text" id="unit" name="unit" value="{{ old('unit', $product->unit) }}" maxlength="30"
                           class="{{ $errors->has('unit') ? 'is-invalid' : '' }}">
                    @error('unit')<span class="field-error">{{ $message }}</span>@enderror
                </div>
            </div>

            <div class="form-group">
                <label for="specifications">Specifications <span class="optional">(optional)</span></label>
                <textarea id="specifications" name="specifications" rows="4" maxlength="3000"
                          class="{{ $errors->has('specifications') ? 'is-invalid' : '' }}"
                          placeholder="Size, grade, weight, thickness, standards... (one per line)">{{ old('specifications', $product->specifications) }}</textarea>
                @error('specifications')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label for="price">Price (₦) <span class="req">*</span></label>
                    <input type="number" id="price" name="price" value="{{ old('price', $product->price) }}" required
                           min="0" step="0.01" max="99999999"
                           class="{{ $errors->has('price') ? 'is-invalid' : '' }}">
                    @error('price')<span class="field-error">{{ $message }}</span>@enderror
                    <small class="field-help">Changes are recorded in the price history below.</small>
                </div>

                <div class="form-group">
                    <label for="stock_quantity">Stock Quantity <span class="req">*</span></label>
                    <input type="number" id="stock_quantity" name="stock_quantity"
                           value="{{ old('stock_quantity', $product->stock_quantity) }}" required min="0" step="1"
                           class="{{ $errors->has('stock_quantity') ? 'is-invalid' : '' }}">
                    @error('stock_quantity')<span class="field-error">{{ $message }}</span>@enderror
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label for="min_order_quantity">Minimum Order Quantity</label>
                    <input type="number" id="min_order_quantity" name="min_order_quantity"
                           value="{{ old('min_order_quantity', $product->min_order_quantity) }}" min="1" step="1" max="1000000"
                           class="{{ $errors->has('min_order_quantity') ? 'is-invalid' : '' }}">
                    @error('min_order_quantity')<span class="field-error">{{ $message }}</span>@enderror
                    <small class="field-help">Smallest quantity a customer may buy (default 1).</small>
                </div>

                <div class="form-group">
                    <label for="quantity_step">Quantity Step</label>
                    <input type="number" id="quantity_step" name="quantity_step"
                           value="{{ old('quantity_step', $product->quantity_step) }}" min="1" step="1" max="1000000"
                           class="{{ $errors->has('quantity_step') ? 'is-invalid' : '' }}">
                    @error('quantity_step')<span class="field-error">{{ $message }}</span>@enderror
                    <small class="field-help">Order in multiples of this number (1 = any quantity).</small>
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label for="status">Status <span class="req">*</span></label>
                    <select id="status" name="status" required
                            class="{{ $errors->has('status') ? 'is-invalid' : '' }}">
                        <option value="active" {{ old('status', $product->status) === 'active' ? 'selected' : '' }}>Active (visible)</option>
                        <option value="inactive" {{ old('status', $product->status) === 'inactive' ? 'selected' : '' }}>Inactive (hidden)</option>
                    </select>
                    @error('status')<span class="field-error">{{ $message }}</span>@enderror
                </div>
            </div>

            <div class="form-group">
                <label class="form-check">
                    <input type="checkbox" name="is_featured" value="1"
                           {{ old('is_featured', $product->is_featured) ? 'checked' : '' }}>
                    <span>Featured product (show on homepage)</span>
                </label>
            </div>

            <div class="form-group">
                <label>Current Slug</label>
                <input type="text" value="{{ $product->slug }}" readonly class="readonly">
            </div>

            <hr class="form-divider">

            <div class="form-group">
                <label>Product Image <span class="optional">(optional)</span></label>
                <div class="logo-manager">
                    <div class="logo-preview-box">
                        @if ($product->image)
                            <img src="{{ asset('storage/' . $product->image) }}" alt="Current image"
                                 id="logoPreview" class="logo-preview-img">
                        @else
                            <div class="logo-preview-placeholder" id="logoPreviewPlaceholder">
                                <span class="logo-mark large">IMG</span>
                                <small>No image — using placeholder</small>
                            </div>
                            <img src="" alt="Image preview" id="logoPreview" class="logo-preview-img" style="display:none;">
                        @endif
                    </div>
                    <div class="logo-controls">
                        <input type="file" name="image" id="logoInput"
                               accept="image/png,image/jpeg,image/webp,image/svg+xml"
                               class="{{ $errors->has('image') ? 'is-invalid' : '' }}">
                        @error('image')<span class="field-error">{{ $message }}</span>@enderror
                        <small class="field-help">Leave empty to keep the current image. Max 2MB.</small>

                        @if ($product->image)
                            <label class="form-check" style="margin-top: 10px;">
                                <input type="checkbox" name="remove_image" value="1">
                                <span>Remove current image</span>
                            </label>
                        @endif
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="{{ route('admin.products.index') }}" class="btn btn-clear">Cancel</a>
            </div>
        </form>
    </div>

    {{-- Price history (§37) — every recorded change, newest first --}}
    <div class="admin-card" style="max-width: 760px; margin-top: 20px;">
        <h2>Price History</h2>

        @if ($priceHistory->isEmpty())
            <div class="empty-state">
                <p>No price changes recorded yet.</p>
            </div>
        @else
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Previous</th>
                            <th>New</th>
                            <th>Changed by</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($priceHistory as $row)
                            <tr>
                                <td><small>{{ $row->created_at->format('d M Y, h:i A') }}</small></td>
                                <td>
                                    @if ($row->old_price === null)
                                        <em>New product</em>
                                    @else
                                        ₦{{ number_format((float) $row->old_price, 0) }}
                                    @endif
                                </td>
                                <td><strong>₦{{ number_format((float) $row->new_price, 0) }}</strong></td>
                                <td>{{ $row->changedBy?->name ?? 'System' }}</td>
                                <td><small>{{ $row->notes }}</small></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
<script>
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
