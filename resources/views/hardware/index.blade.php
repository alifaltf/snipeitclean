@extends('layouts/default')

@section('title0')

  @php
      $requestStatusType = request()->input('status_type');
      $requestOrderNumber = request()->input('order_number');
      $requestCompanyId = request()->input('company_id');
      $requestStatusTypeId = request()->input('status_id');
      // ERS: validated server-side selection (App\Services\AssetCategorySelection), or null.
      $assetCategory = $assetCategory ?? null;
  @endphp

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
@elseif (! $assetCategory)
{{ trans('general.all') }}
@endif
@if ($assetCategory)
{{ implode(' > ', $assetCategory->pathNames()) }}
@else
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
    @if (($canImportIntoCategory ?? false) && $assetCategory)
        {{-- ERS Phase 6A: this final category's own CSV import entry point. --}}
        <div class="row">
            <div class="col-md-12 text-right" style="margin-bottom: 10px;">
                <a href="{{ route('hardware.import.index', ['category' => $assetCategory->id()]) }}" class="btn btn-primary" id="asset-category-import">
                    <x-icon type="import" /> {{ trans('admin/hardware/import.import_csv') }}
                </a>
            </div>
        </div>
    @endif
    <x-container>
        <x-box name="assets">
            <x-table.assets
                :route="route('api.assets.index', array(
                    'status_type' => is_scalar($requestStatusType) ? $requestStatusType : null,
                    'order_number' => is_scalar($requestOrderNumber) ? strval($requestOrderNumber) : null,
                    'company_id' => is_scalar($requestCompanyId) ? $requestCompanyId : null,
                    'status_id' => is_scalar($requestStatusTypeId) ? $requestStatusTypeId : null,
                    'asset_category' => $assetCategory?->id(),
                ))"
                :status_type="is_scalar($requestStatusType) ? $requestStatusType : null"
            />
        </x-box>
    </x-container>
@stop

@section('moar_scripts')
@include('partials.bootstrap-table')

@stop
