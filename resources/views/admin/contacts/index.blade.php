@extends('layouts.admin')

@section('title', 'Customer Contacts')

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <h1>Customer Contacts</h1>
            <p>
                The phone numbers shown in the site's "Call Us" and customer-care sections.
                Only <strong>active</strong> contacts appear publicly — add as many as you need
                (Customer Care, Sales Support, Delivery Support...). Deactivate hides a number
                but keeps it for later; Delete removes it for good. Tick the boxes to handle
                several numbers at once.
            </p>
        </div>
        <a href="{{ route('admin.contacts.create') }}" class="btn btn-primary">+ Add Contact</a>
    </div>

    <div class="admin-card">
        @if ($contacts->isEmpty())
            <div class="empty-state">
                <h3>No contacts yet</h3>
                <p>Add your first customer-care phone number — it will appear in the Call Us sections.</p>
                <a href="{{ route('admin.contacts.create') }}" class="btn btn-navy">Add Contact</a>
            </div>
        @else
            {{-- §39 — bulk activate / deactivate / delete (checkboxes join via the
                 HTML `form` attribute so the per-row forms stay untouched) --}}
            <form action="{{ route('admin.contacts.bulk') }}" method="POST" id="bulk-contacts-form"
                  class="filter-row" style="margin-bottom: 12px;">
                @csrf
                <span class="empty-inline">Bulk actions — <strong id="bulk-count">0</strong> selected:</span>
                <button type="submit" name="action" value="activate" class="btn btn-sm btn-navy">Activate selected</button>
                <button type="submit" name="action" value="deactivate" class="btn btn-sm btn-danger">Deactivate selected</button>
                <button type="submit" name="action" value="delete" class="btn btn-sm btn-danger">Delete selected</button>
            </form>

            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="bulk-select-all" aria-label="Select all contacts"></th>
                            <th>Order</th>
                            <th>Label</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($contacts as $contact)
                            <tr>
                                <td>
                                    <input type="checkbox" class="bulk-row" name="contact_ids[]"
                                           value="{{ $contact->id }}" form="bulk-contacts-form"
                                           aria-label="Select {{ $contact->label }}">
                                </td>
                                <td>{{ $contact->contact_order }}</td>
                                <td><strong>{{ $contact->label }}</strong></td>
                                <td>
                                    <a href="{{ $contact->telHref() }}">{{ $contact->phone }}</a>
                                </td>
                                <td>
                                    <span class="badge {{ $contact->isActive() ? 'badge-green' : 'badge' }}">
                                        {{ $contact->statusLabel() }}
                                    </span>
                                </td>
                                <td class="actions-cell">
                                    <a href="{{ route('admin.contacts.edit', $contact) }}"
                                       class="btn btn-sm btn-navy">Edit</a>
                                    <form action="{{ route('admin.contacts.toggle', $contact) }}" method="POST" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-sm {{ $contact->isActive() ? 'btn-danger' : 'btn-outline-dark' }}">
                                            {{ $contact->isActive() ? 'Deactivate' : 'Reactivate' }}
                                        </button>
                                    </form>
                                    <form action="{{ route('admin.contacts.destroy', $contact) }}" method="POST" style="display:inline;"
                                          onsubmit="return confirm('Delete contact &ldquo;{{ $contact->label }}&rdquo;? It will disappear from the list completely.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                </td>
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
    (function () {
        var selectAll = document.getElementById('bulk-select-all');
        if (!selectAll) return;

        var rows = Array.prototype.slice.call(document.querySelectorAll('.bulk-row'));
        var counter = document.getElementById('bulk-count');
        var form = document.getElementById('bulk-contacts-form');

        function refresh() {
            var count = rows.filter(function (r) { return r.checked; }).length;
            if (counter) counter.textContent = String(count);
        }

        selectAll.addEventListener('change', function () {
            rows.forEach(function (r) { r.checked = selectAll.checked; });
            refresh();
        });
        rows.forEach(function (r) { r.addEventListener('change', refresh); });

        if (form) {
            form.addEventListener('submit', function (event) {
                var picked = rows.filter(function (r) { return r.checked; }).length;
                if (picked === 0) {
                    event.preventDefault();
                    showToast('Select at least one contact first.', 'error');
                    return;
                }
                var action = event.submitter && event.submitter.value;
                var verb = action === 'delete' ? 'delete' : (action === 'deactivate' ? 'deactivate' : 'activate');
                var warning = action === 'delete'
                    ? 'Are you sure you want to permanently delete ' + picked + ' contact(s)?'
                    : 'Are you sure you want to ' + verb + ' ' + picked + ' contact(s)?';
                if (!confirm(warning)) {
                    event.preventDefault();
                }
            });
        }
    })();
</script>
@endpush
