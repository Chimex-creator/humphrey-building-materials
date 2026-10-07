@extends('layouts.admin')

@section('title', 'Returns')

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <h1>Returns</h1>
            <p>
                {{ $summary['total'] }} recorded in total. Customer requests start as
                <strong>Return Requested</strong> — review, approve or reject them here. Goods are then
                marked <strong>Received</strong>, inspected into resellable and damaged units, and only the
                resellable quantity goes back to sellable stock on completion.
            </p>
        </div>
        <a href="{{ route('admin.returns.create') }}" class="btn btn-primary">+ Record Return</a>
    </div>

    <div class="stats-grid">
        <div class="stat-card warn">
            <span class="stat-icon">⏳</span>
            <div>
                <strong>{{ $summary['needs_review'] }}</strong>
                <span>Awaiting Review</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">✅</span>
            <div>
                <strong>{{ $summary['approved'] }}</strong>
                <span>Approved (awaiting goods)</span>
            </div>
        </div>
        <div class="stat-card warn">
            <span class="stat-icon">🔍</span>
            <div>
                <strong>{{ $summary['awaiting_inspection'] }}</strong>
                <span>Awaiting Inspection</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">📦</span>
            <div>
                <strong>{{ $summary['restocked'] }}</strong>
                <span>Completed &amp; Restocked</span>
            </div>
        </div>
    </div>

    <div class="admin-card">
        <form action="{{ route('admin.returns.index') }}" method="GET" class="filter-row">
            <input type="text" name="search" value="{{ $search }}" placeholder="Search by product name..."
                   class="filter-input">
            <select name="status" class="filter-select">
                <option value="">All statuses</option>
                @foreach (\App\Models\ProductReturn::STATUSES as $key => $label)
                    <option value="{{ $key }}" {{ $statusFilter === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            <select name="stock" class="filter-select">
                <option value="">Any stock state</option>
                <option value="pending" {{ $stockFilter === 'pending' ? 'selected' : '' }}>Stock not restored yet</option>
                <option value="restocked" {{ $stockFilter === 'restocked' ? 'selected' : '' }}>Stock restored</option>
            </select>
            <button type="submit" class="btn btn-navy">Filter</button>
            @if ($search || $statusFilter || $stockFilter)
                <a href="{{ route('admin.returns.index') }}" class="btn btn-clear">Clear</a>
            @endif
        </form>

        @if ($returns->isEmpty())
            <div class="empty-state">
                <h3>No returns found</h3>
                <p>Nothing matches these filters. When a customer brings goods back, record it here.</p>
                <a href="{{ route('admin.returns.create') }}" class="btn btn-navy">Record Return</a>
            </div>
        @else
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Date / Time</th>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Inspection</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($returns as $return)
                            <tr>
                                <td>{{ $return->created_at->format('d M Y, H:i') }}</td>
                                <td>
                                    <strong>{{ $return->product->name ?? '— (product removed)' }}</strong><br>
                                    <small class="empty-inline">
                                        {{ $return->sourceLabel() }}
                                        @if ($return->order)
                                            · Order <a href="{{ route('admin.orders.show', $return->order) }}">{{ $return->order->order_number }}</a>
                                        @endif
                                        @if ($return->returnedBy)
                                            · by {{ $return->returnedBy->name }}
                                        @endif
                                    </small>
                                </td>
                                <td>{{ $return->quantity }}</td>
                                <td>{{ $return->reason }}</td>
                                <td>
                                    <span class="badge {{ $return->statusBadgeClass() }}">{{ $return->statusLabel() }}</span>
                                    @if ($return->reviewed_at && $return->reviewedBy)
                                        <br><small class="empty-inline">
                                            {{ $return->reviewed_at->format('d M Y, H:i') }} · {{ $return->reviewedBy->name }}
                                        </small>
                                    @endif
                                </td>
                                <td>
                                    @if ($return->inspected_at)
                                        <span class="badge badge-green">{{ $return->resellable_quantity }} resellable</span>
                                        @if ($return->damaged_quantity > 0)
                                            <span class="badge badge-red">{{ $return->damaged_quantity }} damaged</span>
                                        @endif
                                        @if ($return->inspection_note)
                                            <br><small class="empty-inline">{{ \Illuminate\Support\Str::limit($return->inspection_note, 40) }}</small>
                                        @endif
                                    @elseif ($return->isRejected())
                                        <span class="empty-inline">—</span>
                                    @else
                                        <span class="empty-inline">Not inspected</span>
                                    @endif
                                </td>
                                <td class="actions-cell">
                                    @if ($return->isPending())
                                        <form action="{{ route('admin.returns.review', $return) }}" method="POST" style="display:inline;">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-dark">Start Review</button>
                                        </form>
                                    @endif

                                    @if ($return->needsReview())
                                        <form action="{{ route('admin.returns.approve', $return) }}" method="POST" style="display:inline;">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-navy"
                                                    onclick="return confirm('Approve the return of {{ $return->quantity }} × {{ $return->product->name ?? 'item' }}?');">
                                                Approve
                                            </button>
                                        </form>
                                        <form action="{{ route('admin.returns.decline', $return) }}" method="POST" style="display:inline;">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-danger"
                                                    onclick="return confirm('Reject this return request? No stock will be added back.');">
                                                Reject
                                            </button>
                                        </form>
                                    @endif

                                    @if ($return->isApproved())
                                        <form action="{{ route('admin.returns.receive', $return) }}" method="POST" style="display:inline;">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-navy"
                                                    onclick="return confirm('Mark the goods as received at the shop?');">
                                                Mark Received
                                            </button>
                                        </form>
                                    @endif

                                    @if ($return->isInspected())
                                        <form action="{{ route('admin.returns.complete', $return) }}" method="POST" style="display:inline;">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-navy"
                                                    onclick="return confirm('Complete this return and restore {{ $return->resellable_quantity }} resellable unit(s) to sellable stock?');">
                                                Complete Return
                                            </button>
                                        </form>
                                    @endif

                                    @if ($return->isCompleted() || $return->isRejected())
                                        <span class="empty-inline">—</span>
                                    @endif
                                </td>
                            </tr>

                            {{-- Inspection entry — only when the goods are physically back --}}
                            @if ($return->isReceived())
                                <tr>
                                    <td colspan="7">
                                        <form action="{{ route('admin.returns.inspect', $return) }}" method="POST" class="filter-row">
                                            @csrf
                                            <strong>Inspection</strong>
                                            <input type="number" name="resellable_quantity" min="0" max="{{ $return->quantity }}"
                                                   value="{{ old('resellable_quantity.' . $return->id, $return->quantity) }}"
                                                   class="filter-input" style="max-width:130px;"
                                                   aria-label="Resellable quantity"
                                                   {{ $errors->has('resellable_quantity') ? 'is-invalid' : '' }}>
                                            <label class="empty-inline">resellable</label>
                                            <input type="number" name="damaged_quantity" min="0" max="{{ $return->quantity }}"
                                                   value="{{ old('damaged_quantity.' . $return->id, 0) }}"
                                                   class="filter-input" style="max-width:130px;"
                                                   aria-label="Damaged quantity">
                                            <label class="empty-inline">damaged</label>
                                            <input type="text" name="inspection_note" maxlength="500"
                                                   placeholder="Note (optional) — e.g. packaging torn"
                                                   value="{{ old('inspection_note') }}" class="filter-input" style="max-width:280px;">
                                            <button type="submit" class="btn btn-sm btn-navy">Record Inspection</button>
                                            <small class="empty-inline">
                                                Must add up to {{ $return->quantity }}.
                                                @if ($return->order_id && $return->unitPrice() !== null)
                                                    · paid ₦{{ number_format($return->order->paidAmount(), 0) }}
                                                @endif
                                            </small>
                                            @error('resellable_quantity')<span class="field-error">{{ $message }}</span>@enderror
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                @if ($returns->onFirstPage())
                    <span class="page-btn disabled">&larr; Prev</span>
                @else
                    <a class="page-btn" href="{{ $returns->previousPageUrl() }}">&larr; Prev</a>
                @endif
                <span class="page-info">Page {{ $returns->currentPage() }} of {{ $returns->lastPage() }}</span>
                @if ($returns->hasMorePages())
                    <a class="page-btn" href="{{ $returns->nextPageUrl() }}">Next &rarr;</a>
                @else
                    <span class="page-btn disabled">Next &rarr;</span>
                @endif
            </div>
        @endif
    </div>
@endsection
