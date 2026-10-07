@extends('layouts.admin')

@section('title', 'Record Return')

@section('content')
    <div class="admin-page-head">
        <h1>Record Return</h1>
        <p>Log goods that have physically come back from a customer. They are marked <strong>Return Received</strong> — inspect them from the Returns list to split resellable and damaged units before any stock moves.</p>
    </div>

    <div class="admin-card" style="max-width: 720px;">
        <form action="{{ route('admin.returns.store') }}" method="POST" novalidate>
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
                <label for="quantity">Quantity Returned <span class="req">*</span></label>
                <input type="number" id="quantity" name="quantity" required min="1" step="1" max="10000000"
                       value="{{ old('quantity') }}"
                       class="{{ $errors->has('quantity') ? 'is-invalid' : '' }}">
                @error('quantity')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label for="reason">Reason <span class="req">*</span></label>
                <textarea id="reason" name="reason" rows="3" maxlength="500" required
                          placeholder="e.g. Customer bought the wrong grade, damaged on delivery..."
                          class="{{ $errors->has('reason') ? 'is-invalid' : '' }}">{{ old('reason') }}</textarea>
                @error('reason')<span class="field-error">{{ $message }}</span>@enderror
                <small class="field-help">A reason is mandatory for every return.</small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Record Return</button>
                <a href="{{ route('admin.returns.index') }}" class="btn btn-clear">Cancel</a>
            </div>
        </form>
    </div>
@endsection
