@extends('layouts.admin')

@section('title', 'Stock Adjustments')

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <h1>Stock Adjustments</h1>
            <p>Every manual stock correction, with who changed it, when, and why. These records are never edited or deleted.</p>
        </div>
        <a href="{{ route('admin.stock-adjustments.create') }}" class="btn btn-primary">+ Adjust Stock</a>
    </div>

    <div class="admin-card">
        <form action="{{ route('admin.stock-adjustments.index') }}" method="GET" class="filter-row">
            <input type="text" name="search" value="{{ $search }}" placeholder="Search by product name..."
                   class="filter-input">
            <button type="submit" class="btn btn-navy">Filter</button>
            @if ($search)
                <a href="{{ route('admin.stock-adjustments.index') }}" class="btn btn-clear">Clear</a>
            @endif
        </form>

        @if ($adjustments->isEmpty())
            <div class="empty-state">
                <h3>No adjustments yet</h3>
                <p>Use this when a physical stock count does not match what the system shows.</p>
                <a href="{{ route('admin.stock-adjustments.create') }}" class="btn btn-navy">Adjust Stock</a>
            </div>
        @else
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Date / Time</th>
                            <th>Product</th>
                            <th>Before</th>
                            <th>After</th>
                            <th>Difference</th>
                            <th>Reason</th>
                            <th>Adjusted By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($adjustments as $adjustment)
                            <tr>
                                <td>{{ $adjustment->created_at->format('d M Y, H:i') }}</td>
                                <td><strong>{{ $adjustment->product->name ?? '— (product removed)' }}</strong></td>
                                <td>{{ $adjustment->previous_quantity }}</td>
                                <td>{{ $adjustment->new_quantity }}</td>
                                <td>
                                    @if ($adjustment->difference > 0)
                                        <span class="badge badge-green">+{{ $adjustment->difference }}</span>
                                    @else
                                        <span class="badge badge-red">{{ $adjustment->difference }}</span>
                                    @endif
                                </td>
                                <td>{{ $adjustment->reason }}</td>
                                <td>{{ $adjustment->adjustedBy->name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                @if ($adjustments->onFirstPage())
                    <span class="page-btn disabled">&larr; Prev</span>
                @else
                    <a class="page-btn" href="{{ $adjustments->previousPageUrl() }}">&larr; Prev</a>
                @endif
                <span class="page-info">Page {{ $adjustments->currentPage() }} of {{ $adjustments->lastPage() }}</span>
                @if ($adjustments->hasMorePages())
                    <a class="page-btn" href="{{ $adjustments->nextPageUrl() }}">Next &rarr;</a>
                @else
                    <span class="page-btn disabled">Next &rarr;</span>
                @endif
            </div>
        @endif
    </div>
@endsection
