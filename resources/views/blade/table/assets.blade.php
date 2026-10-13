@props([
    'route' => route('api.assets.index'),
    'name' => 'default',
    'fixed_right_number' => 2,
    'fixed_number' => 3,
    'table_header' => trans('general.assets'),
    'status_type' => null,
    // ERS Phase 7B: a final category's table layout (AssetTableLayoutQuery), or null.
    'table_layout' => null,
])

@aware(['name'])


<!-- start assets tab pane -->
@can('view', \App\Models\Asset::class)

    <x-slot:table_header>
        {{ $table_header }}
    </x-slot:table_header>

    <x-slot:bulkactions>
        <x-table.bulk-assets />
    </x-slot:bulkactions>

    @if ($table_layout)
        {{-- ERS Phase 7B: the category's own columns, own saved table
             state (per category and layout), and the server-side CSV export
             (formula-safe, encrypted values only with permission). --}}
        <x-table
            :presenter="\App\Presenters\AssetPresenter::categoryDataTableLayout($table_layout)"
            :fixed_right_number="2"
            :fixed_number="1 + min(2, count($table_layout->columns))"
            show_column_search="true"
            show_advanced_search="true"
            buttons="assetButtons"
            api_url="{{ $route }}"
            :cookie_id="'assetsCategory'.$table_layout->category->id.'_'.substr(hash('sha256', implode(',', $table_layout->fields())), 0, 12).'ListingTable'"
            :show_export="false"
            export_filename="export-{{ str_slug($name) }}-assets-{{ date('Y-m-d') }}"
        />
    @else
    <x-table
        :presenter="\App\Presenters\AssetPresenter::dataTableLayout($status_type !== 'Deleted' ? ['deleted_at'] : [])"
        :$fixed_right_number
        :$fixed_number
        show_column_search="true"
        show_advanced_search="true"
        buttons="assetButtons"
        api_url="{{ $route }}"
        export_filename="export-{{ str_slug($name) }}-assets-{{ date('Y-m-d') }}"
    />
    @endif

@endcan
<!-- end assets tab pane -->