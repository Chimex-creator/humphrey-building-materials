@extends('layouts.admin')

@section('title', 'Sales Reports')

@section('content')
    @php
        $exportQuery = $preset
            ? ['preset' => $preset]
            : ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];

        $statusClasses = [
            'pending' => 'amber',
            'confirmed' => 'amber',
            'ready_for_pickup' => 'amber',
            'picked_up' => 'green',
            'out_for_delivery' => 'amber',
            'delivered' => 'green',
            'closed' => 'green',
            'cancelled' => 'red',
        ];
        $methodClasses = ['', 'amber', 'green'];
    @endphp

    <div class="admin-page-head row-between">
        <div>
            <h1>Sales Reports</h1>
            <p>
                {{ $from->format('d M Y') }} &ndash; {{ $to->format('d M Y') }}
                @if ($preset)
                    <span class="badge badge-amber">{{ $presets[$preset] }}</span>
                @else
                    <span class="badge badge-green">Custom range</span>
                @endif
                &middot; revenue counts money actually received; orders count when they were placed.
            </p>
        </div>
        <a href="{{ route('admin.reports.export', $exportQuery) }}" class="btn btn-navy">&#8681; Export CSV</a>
    </div>

    {{-- Period picker --}}
    <div class="admin-card">
        <div class="preset-row">
            @foreach ($presets as $key => $label)
                <a class="preset-btn {{ $preset === $key ? 'active' : '' }}"
                   href="{{ route('admin.reports.index', ['preset' => $key]) }}">{{ $label }}</a>
            @endforeach
        </div>

        <form action="{{ route('admin.reports.index') }}" method="GET" class="report-range-form">
            <label class="filter-field">
                <span class="filter-field-label">From</span>
                <input type="date" name="from" value="{{ $from->format('Y-m-d') }}"
                       max="{{ $to->format('Y-m-d') }}" class="filter-input">
            </label>
            <label class="filter-field">
                <span class="filter-field-label">To</span>
                <input type="date" name="to" value="{{ $to->format('Y-m-d') }}"
                       min="{{ $from->format('Y-m-d') }}" class="filter-input">
            </label>
            <button type="submit" class="btn btn-navy">Apply dates</button>
            <a href="{{ route('admin.reports.index') }}" class="btn btn-clear">Reset</a>
            @error('from')
                <span class="field-error">{{ $message }}</span>
            @enderror
            @error('to')
                <span class="field-error">{{ $message }}</span>
            @enderror
        </form>
    </div>

    {{-- Headline numbers --}}
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-icon">💰</span>
            <div>
                <strong>₦{{ number_format($kpis['revenue'], 0) }}</strong>
                <span>Revenue Received</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">🧾</span>
            <div>
                <strong>{{ number_format($kpis['orders']) }}</strong>
                <span>Orders Placed</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">📊</span>
            <div>
                <strong>₦{{ number_format($kpis['avg_order'], 0) }}</strong>
                <span>Average Order Value</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">📦</span>
            <div>
                <strong>{{ number_format($kpis['units']) }}</strong>
                <span>Units Sold</span>
            </div>
        </div>
        <div class="stat-card warn">
            <span class="stat-icon">↩️</span>
            <div>
                <strong>{{ number_format($kpis['returns_completed']) }}</strong>
                <span>Returns Completed</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">🆕</span>
            <div>
                <strong>{{ number_format($kpis['new_customers']) }}</strong>
                <span>New Customers</span>
            </div>
        </div>
        <div class="stat-card warn">
            <span class="stat-icon">🧮</span>
            <div>
                <strong>{{ number_format($kpis['unpaid_orders']) }}</strong>
                <span>Orders Awaiting Payment</span>
            </div>
        </div>
    </div>

    {{-- Revenue over time --}}
    <div class="admin-card">
        @include('partials.charts.line', [
            'series' => $series,
            'chartTitle' => 'Revenue received',
            'emptyMessage' => 'No money was received between ' . $from->format('d M Y') . ' and ' . $to->format('d M Y') . '.',
        ])
    </div>

    <div class="admin-grid-2">
        <div class="admin-card">
            <h2>Orders by Status</h2>
            @include('partials.charts.bars', [
                'rows' => collect($statusBreakdown)->map(fn (array $row) => $row + [
                    'class' => $statusClasses[$row['key']] ?? '',
                ])->values()->all(),
                'emptyMessage' => 'No orders were placed in this period.',
            ])
        </div>

        <div class="admin-card">
            <h2>Where The Money Came From</h2>
            @include('partials.charts.bars', [
                'rows' => collect($methodMix)->map(fn (array $row, int $index) => $row + [
                    'display' => '₦' . number_format($row['value'], 0),
                    'class' => $methodClasses[$index % count($methodClasses)],
                ])->values()->all(),
                'emptyMessage' => 'No payments were received in this period.',
            ])
        </div>
    </div>

    {{-- Best sellers --}}
    <div class="admin-card">
        <div class="admin-card-head" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
            <h2 style="margin:0;">Top Products</h2>
            <small class="empty-inline">Ranked by revenue in this period</small>
        </div>

        @if (count($topProducts) === 0)
            <div class="empty-state">
                <h3>Nothing sold yet</h3>
                <p>No order lines were placed between {{ $from->format('d M Y') }} and {{ $to->format('d M Y') }}.</p>
            </div>
        @else
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Product</th>
                            <th>Units</th>
                            <th>Revenue</th>
                            <th>Share of period</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($topProducts as $index => $product)
                            <tr>
                                <td><strong>{{ $index + 1 }}</strong></td>
                                <td>{{ $product['name'] }}</td>
                                <td>{{ number_format($product['qty']) }}</td>
                                <td><strong>₦{{ number_format($product['revenue'], 0) }}</strong></td>
                                <td class="share-cell">
                                    <span class="bar-track">
                                        <span class="bar-fill" style="width: {{ round($product['share'], 1) }}%"></span>
                                    </span>
                                    <small>{{ number_format($product['share'], 1) }}%</small>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <p class="chart-footnote">
        All times are UTC. Revenue is summed from successful payments with a receipt date inside the
        selected window; <strong>Orders Awaiting Payment</strong> counts open orders in this window whose
        payment has not settled yet. Download the CSV for the order-by-order detail behind these numbers.
    </p>
@endsection
