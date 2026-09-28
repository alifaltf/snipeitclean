{{-- ERS Phase 4: one level of the asset category sidebar (recursive). --}}
@foreach ($items as $item)
    <li @class([
            'ers-asset-category',
            'ers-asset-category-group' => $item['group'],
            'ers-asset-category-selected' => $item['active'],
            'active' => $item['active'] || $item['ancestor'],
        ])
        data-asset-category="{{ $item['id'] }}"
        data-depth="{{ $item['depth'] }}"
        @if ($item['active']) aria-current="page" @endif
    >
        <a href="{{ route('hardware.index', ['asset_category' => $item['id']]) }}" title="{{ $item['name'] }}">
            <x-icon :type="$item['group'] ? 'circle-solid' : 'circle'" class="text-grey fa-fw" />
            {{ $item['name'] }}
        </a>

        @if ($item['group'] && count($item['children']) > 0)
            <button type="button"
                    class="ers-asset-category-toggle"
                    aria-controls="ers-asset-category-{{ $item['id'] }}"
                    aria-expanded="{{ $item['open'] ? 'true' : 'false' }}"
                    aria-label="{{ trans('general.asset_category_toggle', ['name' => $item['name']]) }}">
                <x-icon type="caret-right" />
            </button>
            <ul class="treeview-menu" id="ers-asset-category-{{ $item['id'] }}">
                @include('partials.asset-category-nav-items', ['items' => $item['children']])
            </ul>
        @endif
    </li>
@endforeach
