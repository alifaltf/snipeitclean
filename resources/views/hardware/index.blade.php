@extends('layouts/default')

@section('title0')

  @php
      $requestStatusType = request()->input('status_type');
      $requestOrderNumber = request()->input('order_number');
      $requestCompanyId = request()->input('company_id');
      $requestStatusTypeId = request()->input('status_id');
  @endphp

  {{--
      $ers_page_title is already resolved + validated (category_type =
      asset, or a known config('ers_assets') virtual group) by
      AssetsController@index — never re-read/re-validated here. When set,
      it replaces the whole default title (company/status/"All Assets")
      with the exact configured title for the selected Hardware/Software
      category or group, e.g. "Laptop Assets" / "Hardware Assets".
  --}}
  @if (isset($ers_page_title) && is_string($ers_page_title) && $ers_page_title !== '')
    {{ $ers_page_title }}
  @else
    @if (is_scalar($requestCompanyId) && ($company instanceof \App\Models\Company))
      {{ $company->name }}
    @endif

    @if ($requestStatusType)
        @if ($requestStatusType=='Pending')
      {{ trans('general.pending') }}
        @elseif ($requestStatusType=='RTD')
      {{ trans('general.ready_to_deploy') }}
        @elseif ($requestStatusType=='Deployed')
      {{ trans('general.deployed') }}
        @elseif ($requestStatusType=='Undeployable')
      {{ trans('general.undeployable') }}
        @elseif ($requestStatusType=='Deployable')
      {{ trans('general.deployed') }}
        @elseif ($requestStatusType=='Requestable')
      {{ trans('admin/hardware/general.requestable') }}
        @elseif ($requestStatusType=='Archived')
      {{ trans('general.archived') }}
        @elseif ($requestStatusType=='Deleted')
      {{ ucfirst(trans('general.deleted')) }}
        @elseif ($requestStatusType=='byod')
      {{ strtoupper(trans('general.byod')) }}
    @endif
  @else
  {{ trans('general.all') }}
  @endif
  {{ trans('general.assets') }}
  @endif

  @if (Request::has('order_number') && is_scalar($requestOrderNumber))
    : Order #{{ strval($requestOrderNumber) }}
  @endif
@stop

{{-- Page title --}}
@section('title')
@yield('title0')  @parent
@stop


{{-- Page content --}}
@section('content')
    <x-container>
        <x-box name="assets">
            {{--
                [All Assets] [Hardware] [Software] nav buttons.
                $ers_nav_groups and $ers_active_group are resolved +
                validated entirely server-side by
                AssetsController@index/ersNavGroups() — never re-derived
                from raw request input here. A group only appears in
                $ers_nav_groups (and therefore only gets a button) once
                it has at least one category_type=asset category
                currently assigned to it; "All Assets" itself is never
                hidden. Group keys/labels/URLs all come from
                config('ers_assets') + the database — nothing here is a
                hard-coded category name or ID.
            --}}
            <div class="btn-group ers-assets-nav" role="group" aria-label="{{ trans('general.assets') }}" style="margin-bottom: 15px;">
                <a href="{{ route('hardware.index') }}"
                   class="btn btn-default{{ is_null($ers_active_group ?? null) ? ' active btn-theme' : '' }}">
                    {{ trans('general.all') }} {{ trans('general.assets') }}
                </a>
                @foreach (($ers_nav_groups ?? []) as $ersNavGroupKey => $ersNavGroup)
                    <a href="{{ $ersNavGroup['url'] }}"
                       class="btn btn-default{{ (($ers_active_group ?? null) === $ersNavGroupKey) ? ' active btn-theme' : '' }}">
                        {{ $ersNavGroup['label'] }}
                    </a>
                @endforeach
            </div>

            {{--
                Second row: [All Hardware] [Desktop] [Laptop] [Monitor]
                [Phone] (or the equivalent Software row), shown only when
                a Hardware/Software group is actually active AND that
                group currently has at least one category_type=asset
                category assigned to it. $ers_category_buttons is built
                entirely server-side by
                AssetsController@index/ersCategoryButtons() from
                config('ers_assets') + a fresh database query scoped to
                the active group — never re-derived from raw request
                input here, so a category from a different group (or an
                ungrouped category) can never appear. Hidden on the bare
                "All Assets" page and whenever the active group has no
                categories, per spec.
            --}}
            @if (! empty($ers_category_buttons['categories'] ?? []))
                <div class="ers-assets-category-nav" style="margin-bottom: 15px;">
                    <strong>{{ $ers_category_buttons['group_label'] }} {{ trans('general.category') }}:</strong><br>
                    <div class="btn-group" role="group" aria-label="{{ $ers_category_buttons['group_label'] }} {{ trans('general.category') }}" style="display: flex; flex-wrap: wrap; gap: 5px; margin-top: 5px;">
                        <a href="{{ $ers_category_buttons['all']['url'] }}"
                           class="btn btn-default{{ is_null($ers_active_category_id ?? null) ? ' active btn-theme' : '' }}">
                            {{ $ers_category_buttons['all']['label'] }}
                        </a>
                        @foreach ($ers_category_buttons['categories'] as $ersCategoryButton)
                            <a href="{{ $ersCategoryButton['url'] }}"
                               class="btn btn-default{{ (($ers_active_category_id ?? null) === $ersCategoryButton['id']) ? ' active btn-theme' : '' }}">
                                {{ $ersCategoryButton['label'] }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            {{--
                category_id below is already resolved + validated
                server-side by AssetsController@index — a single
                category_id, a comma-joined list of category_ids for an
                ERS asset_group aggregate (Asset::scopeInCategory already
                supports that), or null for "All Assets".
            --}}
            <x-table.assets
                :route="route('api.assets.index', array(
                    'status_type' => is_scalar($requestStatusType) ? $requestStatusType : null,
                    'order_number' => is_scalar($requestOrderNumber) ? strval($requestOrderNumber) : null,
                    'company_id' => is_scalar($requestCompanyId) ? $requestCompanyId : null,
                    'status_id' => is_scalar($requestStatusTypeId) ? $requestStatusTypeId : null,
                    'category_id' => isset($ers_category_ids) ? $ers_category_ids : null,
                ))"
                :status_type="is_scalar($requestStatusType) ? $requestStatusType : null"
            />
        </x-box>
    </x-container>
@stop

@section('moar_scripts')
@include('partials.bootstrap-table')

@stop
