@props([
    'item' => null,
    'route' => null,
    'wide' => false,
    // ERS Phase 5B2: the asset page passes its record ability (editRecord,
    // deleteRecord, restoreRecord); everything else keeps the default.
    'ability' => 'update',
])

@can($ability, $item)
<!-- start update button component -->
@if ($item->deleted_at=='')
    <a href="{{ ($item->deleted_at == '') ? $route: '#' }}" class="btn btn-sm btn-warning hidden-print{{ $wide == 'true' ? ' btn-block btn-social' : '' }}{{ ($item->deleted_at!='') ? ' disabled' : '' }}" data-tooltip="true" data-placement="top" data-title="{{ trans('general.update') }}">
    <x-icon type="edit" class="fa-fw" />

    @if ($wide=='true')
        {{ trans('general.update') }}
    @endif

</a>
@endif
<!-- end update button component -->
@endcan