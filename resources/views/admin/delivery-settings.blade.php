@extends('layouts.admin')

@section('title', 'Delivery Zones & Fees')

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <h1>Delivery Zones &amp; Fees</h1>
            <p>
                Where we deliver and what it costs. Checkout prices every delivery order from this
                page automatically: an <strong>area mapped to a zone</strong> pays the zone fee,
                otherwise the <strong>state fee</strong> applies. Switching anything off means we
                do not deliver there — checkout says so instead of guessing.
            </p>
        </div>
    </div>

    {{-- ============================================================
         ZONES
         ============================================================ --}}
    <div class="admin-card">
        <h2>Zones (named fee bands)</h2>

        <form action="{{ route('admin.delivery-settings.zones.store') }}" method="POST" class="filter-row">
            @csrf
            <input type="text" name="name" class="filter-input" maxlength="60" required
                   placeholder="Zone name (e.g. Zone 11)" value="{{ old('zone.name') }}">
            <input type="number" name="fee" class="filter-input" style="max-width:130px;" min="0" step="1" required
                   placeholder="Fee ₦" value="{{ old('zone.fee') }}">
            <select name="status" class="filter-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <button type="submit" class="btn btn-navy">+ Add Zone</button>
        </form>
        @error('name')<span class="field-error">{{ $message }}</span>@enderror
        @error('fee')<span class="field-error">{{ $message }}</span>@enderror

        <div class="table-wrap" style="margin-top:10px;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Zone</th>
                        <th>Fee</th>
                        <th>Status</th>
                        <th>Mapped areas</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($zones as $zone)
                        <tr>
                            <td colspan="5">
                                <form action="{{ route('admin.delivery-settings.zones.update', $zone) }}" method="POST" class="filter-row">
                                    @csrf
                                    @method('PATCH')
                                    <input type="text" name="name" class="filter-input" maxlength="60" required
                                           value="{{ old('zones.'.$zone->id.'.name', $zone->name) }}" aria-label="Zone name">
                                    <input type="number" name="fee" class="filter-input" style="max-width:130px;"
                                           min="0" step="1" required
                                           value="{{ old('zones.'.$zone->id.'.fee', $zone->fee) }}" aria-label="Zone fee">
                                    <select name="status" class="filter-select" aria-label="Zone status">
                                        @foreach (\App\Models\DeliveryZone::STATUSES as $key => $label)
                                            <option value="{{ $key }}" {{ $zone->status === $key ? 'selected' : '' }}>
                                                {{ $label }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-navy">Save</button>
                                    <span class="empty-inline">
                                        {{ $zone->areas_count }} area(s)
                                    </span>
                                </form>
                                <form action="{{ route('admin.delivery-settings.zones.destroy', $zone) }}" method="POST" style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger"
                                            onclick="return confirm('Delete zone {{ $zone->name }}? This cannot be undone.');">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty-inline">No zones yet — add the first one above.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ============================================================
         STATES
         ============================================================ --}}
    <div class="admin-card">
        <h2>States &amp; Territories</h2>

        <form action="{{ route('admin.delivery-settings.states.store') }}" method="POST" class="filter-row">
            @csrf
            <input type="text" name="name" class="filter-input" maxlength="60" required
                   placeholder="State name (e.g. Edo)" value="{{ old('state.name') }}">
            <input type="number" name="default_fee" class="filter-input" style="max-width:150px;" min="0" step="1"
                   placeholder="Default fee ₦ (blank = no delivery)" value="{{ old('state.default_fee') }}">
            <select name="status" class="filter-select">
                <option value="active">Active (we deliver here)</option>
                <option value="inactive">Inactive (not serviceable)</option>
            </select>
            <button type="submit" class="btn btn-navy">+ Add State</button>
        </form>

        <form action="{{ route('admin.delivery-settings.index') }}" method="GET" class="filter-row">
            <input type="text" name="state_search" class="filter-input"
                   placeholder="Search states..." value="{{ $data['state_search'] ?? '' }}">
            <button type="submit" class="btn btn-navy">Filter</button>
            @if (!empty($data['state_search']))
                <a href="{{ route('admin.delivery-settings.index') }}" class="btn btn-clear">Clear</a>
            @endif
        </form>

        <div class="table-wrap" style="margin-top:10px;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>State</th>
                        <th>Default fee</th>
                        <th>Status</th>
                        <th>Areas</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($states as $state)
                        <tr>
                            <td colspan="5">
                                <form action="{{ route('admin.delivery-settings.states.update', $state) }}" method="POST" class="filter-row">
                                    @csrf
                                    @method('PATCH')
                                    <input type="text" name="name" class="filter-input" maxlength="60" required
                                           value="{{ old('states.'.$state->id.'.name', $state->name) }}" aria-label="State name">
                                    <input type="number" name="default_fee" class="filter-input" style="max-width:150px;"
                                           min="0" step="1"
                                           placeholder="Blank = not serviceable"
                                           value="{{ old('states.'.$state->id.'.default_fee', $state->default_fee) }}" aria-label="Default fee">
                                    <select name="status" class="filter-select" aria-label="State status">
                                        @foreach (\App\Models\DeliveryState::STATUSES as $key => $label)
                                            <option value="{{ $key }}" {{ $state->status === $key ? 'selected' : '' }}>
                                                {{ $label }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-navy">Save</button>
                                    <span class="empty-inline">{{ $state->areas_count }} area(s)</span>
                                </form>
                                <form action="{{ route('admin.delivery-settings.states.destroy', $state) }}" method="POST" style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger"
                                            onclick="return confirm('Delete state {{ $state->name }}?');">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty-inline">No states match this search.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pagination">
            @if ($states->onFirstPage())
                <span class="page-btn disabled">&larr; Prev</span>
            @else
                <a class="page-btn" href="{{ $states->previousPageUrl() }}">&larr; Prev</a>
            @endif
            <span class="page-info">Page {{ $states->currentPage() }} of {{ $states->lastPage() }}</span>
            @if ($states->hasMorePages())
                <a class="page-btn" href="{{ $states->nextPageUrl() }}">Next &rarr;</a>
            @else
                <span class="page-btn disabled">Next &rarr;</span>
            @endif
        </div>
    </div>

    {{-- ============================================================
         AREAS
         ============================================================ --}}
    <div class="admin-card">
        <h2>Areas (towns &amp; neighbourhoods)</h2>
        <p class="empty-inline">
            Map an area to a zone to price it with that zone's fee. Areas with
            <strong>no zone</strong> use their state's default fee.
        </p>

        <form action="{{ route('admin.delivery-settings.areas.store') }}" method="POST" class="filter-row">
            @csrf
            <select name="delivery_state_id" class="filter-select" required>
                <option value="">State...</option>
                @foreach ($allStates as $state)
                    <option value="{{ $state->id }}" {{ (string) old('area.delivery_state_id') === (string) $state->id ? 'selected' : '' }}>
                        {{ $state->name }}
                    </option>
                @endforeach
            </select>
            <input type="text" name="name" class="filter-input" maxlength="60" required
                   placeholder="Area name (e.g. Mararaba)" value="{{ old('area.name') }}">
            <select name="delivery_zone_id" class="filter-select">
                <option value="">No zone (state fee)</option>
                @foreach ($zones as $zone)
                    <option value="{{ $zone->id }}" {{ (string) old('area.delivery_zone_id') === (string) $zone->id ? 'selected' : '' }}>
                        {{ $zone->name }} — ₦{{ number_format((float) $zone->fee, 0) }}
                    </option>
                @endforeach
            </select>
            <select name="status" class="filter-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <button type="submit" class="btn btn-navy">+ Add Area</button>
        </form>

        <form action="{{ route('admin.delivery-settings.index') }}" method="GET" class="filter-row">
            <select name="state_id" class="filter-select">
                <option value="">All states</option>
                @foreach ($allStates as $state)
                    <option value="{{ $state->id }}" {{ ($data['state_id'] ?? '') == $state->id ? 'selected' : '' }}>
                        {{ $state->name }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-navy">Filter</button>
            @if (!empty($data['state_id']))
                <a href="{{ route('admin.delivery-settings.index') }}" class="btn btn-clear">Clear</a>
            @endif
        </form>

        <div class="table-wrap" style="margin-top:10px;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Area</th>
                        <th>State</th>
                        <th>Zone (fee)</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($areas as $area)
                        <tr>
                            <td colspan="5">
                                <form action="{{ route('admin.delivery-settings.areas.update', $area) }}" method="POST" class="filter-row">
                                    @csrf
                                    @method('PATCH')
                                    <input type="text" name="name" class="filter-input" maxlength="60" required
                                           value="{{ old('areas.'.$area->id.'.name', $area->name) }}" aria-label="Area name">
                                    <select name="delivery_state_id" class="filter-select" aria-label="State">
                                        @foreach ($allStates as $state)
                                            <option value="{{ $state->id }}" {{ $area->delivery_state_id === $state->id ? 'selected' : '' }}>
                                                {{ $state->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <select name="delivery_zone_id" class="filter-select" aria-label="Zone">
                                        <option value="">No zone (state fee)</option>
                                        @foreach ($zones as $zone)
                                            <option value="{{ $zone->id }}" {{ $area->delivery_zone_id === $zone->id ? 'selected' : '' }}>
                                                {{ $zone->name }} — ₦{{ number_format((float) $zone->fee, 0) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <select name="status" class="filter-select" aria-label="Area status">
                                        @foreach (\App\Models\DeliveryArea::STATUSES as $key => $label)
                                            <option value="{{ $key }}" {{ $area->status === $key ? 'selected' : '' }}>
                                                {{ $label }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-navy">Save</button>
                                </form>
                                <form action="{{ route('admin.delivery-settings.areas.destroy', $area) }}" method="POST" style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger"
                                            onclick="return confirm('Delete area {{ $area->name }}?');">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty-inline">No areas match this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pagination">
            @if ($areas->onFirstPage())
                <span class="page-btn disabled">&larr; Prev</span>
            @else
                <a class="page-btn" href="{{ $areas->previousPageUrl() }}">&larr; Prev</a>
            @endif
            <span class="page-info">Page {{ $areas->currentPage() }} of {{ $areas->lastPage() }}</span>
            @if ($areas->hasMorePages())
                <a class="page-btn" href="{{ $areas->nextPageUrl() }}">Next &rarr;</a>
            @else
                <span class="page-btn disabled">Next &rarr;</span>
            @endif
        </div>
    </div>
@endsection
