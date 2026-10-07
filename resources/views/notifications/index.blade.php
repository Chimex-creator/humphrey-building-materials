@extends('layouts.app')

@section('title', 'Notifications')

@section('content')

    <section class="page-banner">
        <div class="container">
            <h1>Notifications</h1>
            <p>Payment updates, delivery fee confirmations and order news.</p>
        </div>
    </section>

    <section class="section">
        <div class="container">

            <div class="notif-page">
                <div class="notif-head row-between">
                    <p class="empty-inline">
                        {{ auth()->user()->unreadNotifications()->count() }} unread
                        · {{ auth()->user()->notifications()->count() }} total
                    </p>
                    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        {{-- Final Spec §39 — bulk mark-selected-as-read --}}
                        <form action="{{ route('notifications.read-selected') }}" method="POST" id="bulk-notif-form">
                            @csrf
                            <button type="submit" class="btn btn-clear btn-sm">
                                Mark selected as read (<span id="bulk-count">0</span>)
                            </button>
                        </form>
                        @if (auth()->user()->unreadNotifications()->count() > 0)
                            <form action="{{ route('notifications.read-all') }}" method="POST">
                                @csrf
                                <button type="submit" class="btn btn-clear btn-sm">Mark all as read</button>
                            </form>
                        @endif
                    </div>
                </div>

                @if ($notifications->isEmpty())
                    <div class="empty-state">
                        <h3>No notifications yet</h3>
                        <p>Payment and delivery updates will appear here.</p>
                        <a href="{{ route('orders.index') }}" class="btn btn-navy">My Orders</a>
                    </div>
                @else
                    <ul class="notif-list">
                        @foreach ($notifications as $notification)
                            <li class="notif-item {{ $notification->read_at ? '' : 'notif-unread' }}">
                                <div style="display:flex; gap:10px; align-items:flex-start;">
                                    <input type="checkbox" class="bulk-row" name="notification_ids[]"
                                           value="{{ $notification->id }}" form="bulk-notif-form"
                                           aria-label="Select notification"
                                           style="margin-top:6px;">
                                    <form action="{{ route('notifications.read', $notification) }}" method="POST" style="flex:1;">
                                        @csrf
                                        <button type="submit" class="notif-open" title="Open">
                                            <strong>{{ $notification->data['title'] ?? 'Notification' }}</strong>
                                            <span>{{ $notification->data['message'] ?? '' }}</span>
                                            <small class="empty-inline">
                                                {{ $notification->created_at->diffForHumans() }}
                                                @if ($notification->data['order'] ?? null)
                                                    · {{ $notification->data['order'] }}
                                                @endif
                                            </small>
                                        </button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    <div class="pagination">
                        @if ($notifications->onFirstPage())
                            <span class="page-btn disabled">&larr; Prev</span>
                        @else
                            <a class="page-btn" href="{{ $notifications->previousPageUrl() }}">&larr; Prev</a>
                        @endif
                        <span class="page-info">Page {{ $notifications->currentPage() }} of {{ $notifications->lastPage() }}</span>
                        @if ($notifications->hasMorePages())
                            <a class="page-btn" href="{{ $notifications->nextPageUrl() }}">Next &rarr;</a>
                        @else
                            <span class="page-btn disabled">Next &rarr;</span>
                        @endif
                    </div>
                @endif
            </div>

        </div>
    </section>

@endsection

@push('scripts')
<script>
    (function () {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.bulk-row'));
        if (!rows.length) return;

        var counter = document.getElementById('bulk-count');
        var form = document.getElementById('bulk-notif-form');
        if (!form) return;

        function refresh() {
            var count = rows.filter(function (r) { return r.checked; }).length;
            if (counter) counter.textContent = String(count);
        }
        rows.forEach(function (r) { r.addEventListener('change', refresh); });

        form.addEventListener('submit', function (event) {
            var picked = rows.filter(function (r) { return r.checked; }).length;
            if (picked === 0) {
                event.preventDefault();
                showToast('Select at least one notification first.', 'error');
            }
        });
    })();
</script>
@endpush
