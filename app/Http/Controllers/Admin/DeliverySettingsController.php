<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryArea;
use App\Models\DeliveryState;
use App\Models\DeliveryZone;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phases 14–15 — the admin's delivery geography settings.
 *
 * Zones (named fee bands), states (default fee / on-off switch) and areas
 * (places inside a state, each mapped to at most one zone). Everything is
 * editable here without touching code; deletions that would orphan
 * dependent records are refused with a plain explanation.
 */
class DeliverySettingsController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'state_search' => ['nullable', 'string', 'max:60'],
            'state_id' => ['nullable', 'integer'],
        ]);

        $zones = DeliveryZone::withCount('areas')->orderBy('id')->get()
            ->sortBy(fn (DeliveryZone $zone) => sprintf('%010d', (int) preg_replace('/\D+/', '', $zone->name)))
            ->values();

        $statesQuery = DeliveryState::withCount('areas')->with('areas')->orderBy('name');
        $search = trim((string) ($data['state_search'] ?? ''));
        if ($search !== '') {
            $statesQuery->where('name', 'like', "%{$search}%");
        }
        $states = $statesQuery->paginate(15)->withQueryString();

        $areasQuery = DeliveryArea::with(['state', 'zone'])->orderBy('name');
        if (! empty($data['state_id'])) {
            $areasQuery->where('delivery_state_id', (int) $data['state_id']);
            $selectedState = DeliveryState::find((int) $data['state_id']);
        } else {
            $selectedState = null;
        }
        $areas = $areasQuery->paginate(15)->withQueryString();

        return view('admin.delivery-settings', [
            'zones' => $zones,
            'states' => $states,
            'areas' => $areas,
            'allStates' => DeliveryState::orderBy('name')->get(),
            'selectedState' => $selectedState,
            'data' => $data,
        ]);
    }

    /* ------------------------------------------------------------
     | Zones
     * ------------------------------------------------------------ */

    public function storeZone(Request $request, ActivityLogger $logger)
    {
        $data = $this->validateZone($request);

        DeliveryZone::create($data);

        $logger->log('delivery.settings_updated', null,
            'Delivery zone "'.$data['name'].'" created at ₦'.number_format($data['fee'], 0).'.', $data);

        return back()->with('status', 'Zone "'.$data['name'].'" created.');
    }

    public function updateZone(Request $request, DeliveryZone $zone, ActivityLogger $logger)
    {
        $data = $this->validateZone($request, $zone);

        if ($zone->name === $data['name']
            && (float) $zone->fee === (float) $data['fee']
            && $zone->status === $data['status']) {
            return back()->with('error', 'Nothing changed on '.$zone->name.'.');
        }

        $zone->update($data);

        $logger->log('delivery.settings_updated', $zone,
            'Delivery zone "'.$zone->name.'" updated.', $data);

        return back()->with('status', 'Zone "'.$zone->name.'" saved.');
    }

    public function destroyZone(DeliveryZone $zone, ActivityLogger $logger)
    {
        $mapped = $zone->areas()->count();

        if ($mapped > 0) {
            return back()->with('error',
                '"'.$zone->name.'" still has '.$mapped.' area(s) mapped to it. '
                .'Move those areas to another zone (or clear their zone) first.');
        }

        $name = $zone->name;
        $zone->delete();

        $logger->log('delivery.settings_updated', null, 'Delivery zone "'.$name.'" deleted.');

        return back()->with('status', 'Zone "'.$name.'" deleted.');
    }

    /* ------------------------------------------------------------
     | States
     * ------------------------------------------------------------ */

    public function storeState(Request $request, ActivityLogger $logger)
    {
        $data = $this->validateState($request);

        DeliveryState::create($data);

        $logger->log('delivery.settings_updated', null,
            'Delivery state "'.$data['name'].'" created.', $data);

        return back()->with('status', 'State "'.$data['name'].'" added.');
    }

    public function updateState(Request $request, DeliveryState $state, ActivityLogger $logger)
    {
        $data = $this->validateState($request, $state);

        if ($state->name === $data['name']
            && $state->status === $data['status']
            && $state->default_fee == $data['default_fee']) {
            return back()->with('error', 'Nothing changed on '.$state->name.'.');
        }

        $state->update($data);

        $logger->log('delivery.settings_updated', $state,
            'Delivery state "'.$state->name.'" updated.', $data);

        return back()->with('status', 'State "'.$state->name.'" saved.');
    }

    public function destroyState(DeliveryState $state, ActivityLogger $logger)
    {
        if ($state->areas()->exists()) {
            return back()->with('error',
                '"'.$state->name.'" still has areas inside it. Delete or move those areas first.');
        }

        $name = $state->name;
        $state->delete();

        $logger->log('delivery.settings_updated', null, 'Delivery state "'.$name.'" deleted.');

        return back()->with('status', 'State "'.$name.'" deleted.');
    }

    /* ------------------------------------------------------------
     | Areas
     * ------------------------------------------------------------ */

    public function storeArea(Request $request, ActivityLogger $logger)
    {
        $data = $this->validateArea($request);

        DeliveryArea::create($data);

        $state = DeliveryState::find($data['delivery_state_id']);
        $logger->log('delivery.settings_updated', null,
            'Delivery area "'.$data['name'].'" added to '.$state?->name.'.', $data);

        return back()->with('status', 'Area "'.$data['name'].'" added.');
    }

    public function updateArea(Request $request, DeliveryArea $area, ActivityLogger $logger)
    {
        $data = $this->validateArea($request, $area);

        $zone = $data['delivery_zone_id'] ? DeliveryZone::find($data['delivery_zone_id']) : null;
        $before = $area->delivery_zone_id ? ($area->zone?->name ?? 'a deleted zone') : 'no zone';

        if ($area->delivery_state_id == $data['delivery_state_id']
            && $area->name === $data['name']
            && $area->status === $data['status']
            && $area->delivery_zone_id == $data['delivery_zone_id']) {
            return back()->with('error', 'Nothing changed on '.$area->name.'.');
        }

        $area->update($data);

        $after = $zone?->name ?? 'no zone';
        $logger->log('delivery.settings_updated', $area,
            'Delivery area "'.$area->name.'" updated (zone: '.$before.' → '.$after.').', $data);

        return back()->with('status', 'Area "'.$area->name.'" saved.');
    }

    public function destroyArea(DeliveryArea $area, ActivityLogger $logger)
    {
        $name = $area->name;
        $stateName = $area->state?->name;
        $area->delete();

        $logger->log('delivery.settings_updated', null,
            'Delivery area "'.$name.'" ('.$stateName.') deleted.');

        return back()->with('status', 'Area "'.$name.'" deleted.');
    }

    /* ------------------------------------------------------------
     | Validation helpers
     * ------------------------------------------------------------ */

    private function validateZone(Request $request, ?DeliveryZone $zone = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('delivery_zones', 'name')
                ->ignore($zone?->id)],
            'fee' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'status' => ['required', Rule::in(array_keys(DeliveryZone::STATUSES))],
        ]);
    }

    private function validateState(Request $request, ?DeliveryState $state = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('delivery_states', 'name')
                ->ignore($state?->id)],
            // Empty = no fee configured = not serviceable.
            'default_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'status' => ['required', Rule::in(array_keys(DeliveryState::STATUSES))],
        ]);

        $data['default_fee'] = $data['default_fee'] !== null && $data['default_fee'] !== ''
            ? (float) $data['default_fee']
            : null;

        return $data;
    }

    private function validateArea(Request $request, ?DeliveryArea $area = null): array
    {
        return $request->validate([
            'delivery_state_id' => ['required', 'integer', Rule::exists('delivery_states', 'id')],
            'name' => ['required', 'string', 'max:60',
                Rule::unique('delivery_areas', 'name')->where('delivery_state_id', $request->input('delivery_state_id'))
                    ->ignore($area?->id)],
            'status' => ['required', Rule::in(array_keys(DeliveryArea::STATUSES))],
            'delivery_zone_id' => ['nullable', 'integer', Rule::exists('delivery_zones', 'id')],
        ], [
            'name.unique' => 'That area already exists in this state.',
        ]);
    }
}
