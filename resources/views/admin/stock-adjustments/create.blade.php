@extends('layouts.admin')

@section('title', 'Adjust Stock')

@section('content')
    <div class="admin-page-head">
        <h1>Adjust Stock</h1>
        <p>Enter the quantity you actually counted. The system works out the difference and records who made the change.</p>
    </div>

    <div class="admin-card" style="max-width: 720px;">
        <form action="{{ route('admin.stock-adjustments.store') }}" method="POST" novalidate id="adjustForm">
            @csrf

            <div class="form-group">
                <label for="product_id">Product <span class="req">*</span></label>
                <select id="product_id" name="product_id" required
                        class="{{ $errors->has('product_id') ? 'is-invalid' : '' }}">
                    <option value="">Select a product...</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}"
                                data-stock="{{ $product->stock_quantity }}"
                                {{ (string) old('product_id', $preselected) === (string) $product->id ? 'selected' : '' }}>
                            {{ $product->name }}{{ $product->unit ? ' / ' . $product->unit : '' }} — {{ $product->stock_quantity }} in stock
                        </option>
                    @endforeach
                </select>
                @error('product_id')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label for="new_quantity">Counted Quantity <span class="req">*</span></label>
                <input type="number" id="new_quantity" name="new_quantity" required min="0" step="1" max="10000000"
                       value="{{ old('new_quantity') }}"
                       class="{{ $errors->has('new_quantity') ? 'is-invalid' : '' }}">
                @error('new_quantity')<span class="field-error">{{ $message }}</span>@enderror
                <small class="field-help">The physical quantity you counted. Must be 0 or more.</small>
            </div>

            <div class="form-group">
                <label for="reason">Reason <span class="req">*</span></label>
                <textarea id="reason" name="reason" rows="3" maxlength="500" required
                          placeholder="e.g. Physical stock count, damaged bags removed, water damage in store..."
                          class="{{ $errors->has('reason') ? 'is-invalid' : '' }}">{{ old('reason') }}</textarea>
                @error('reason')<span class="field-error">{{ $message }}</span>@enderror
                <small class="field-help">A reason is mandatory — every adjustment keeps one.</small>
            </div>

            <div class="admin-card" style="background:#f8fafc;">
                <div class="summary-rows">
                    <li><span>Current stock</span> <strong id="previewBefore">—</strong></li>
                    <li><span>New stock</span> <strong id="previewAfter">—</strong></li>
                    <li class="summary-total"><span>Difference</span> <strong id="previewDiff">—</strong></li>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Adjustment</button>
                <a href="{{ route('admin.stock-adjustments.index') }}" class="btn btn-clear">Cancel</a>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('adjustForm');
        if (!form) return;
        var product = document.getElementById('product_id');
        var input = document.getElementById('new_quantity');
        var before = document.getElementById('previewBefore');
        var after = document.getElementById('previewAfter');
        var diff = document.getElementById('previewDiff');

        function render() {
            var opt = product.options[product.selectedIndex];
            var stock = opt ? opt.getAttribute('data-stock') : null;
            before.textContent = stock === null ? '—' : stock;

            if (stock === null || input.value === '' || isNaN(parseInt(input.value, 10))) {
                after.textContent = '—';
                diff.textContent = '—';
                diff.style.color = '';
                return;
            }

            var next = parseInt(input.value, 10);
            var delta = next - parseInt(stock, 10);
            after.textContent = next;
            diff.textContent = (delta > 0 ? '+' : '') + delta;
            diff.style.color = delta === 0 ? '#b45309' : (delta < 0 ? '#dc2626' : 'var(--success)');
        }

        product.addEventListener('change', render);
        input.addEventListener('input', render);

        form.addEventListener('submit', function (e) {
            var opt = product.options[product.selectedIndex];
            if (!opt || !opt.value) {
                e.preventDefault();
                alert('Choose a product first.');
                return;
            }
            if (parseInt(input.value, 10) === parseInt(opt.getAttribute('data-stock'), 10)) {
                e.preventDefault();
                alert('That is already the current stock quantity — nothing to adjust.');
            }
        });

        render();
    });
</script>
@endpush
