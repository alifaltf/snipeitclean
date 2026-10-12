@extends('layouts/default')

{{-- ERS Phase 6B/6C: validation results and preview of resolved values,
     and the confirmation that imports them. Every value is escaped;
     encrypted custom fields arrive already masked. Rows are paginated. The
     import form appears only when the session is validated and every row
     still passes; it posts the token of this validation, and the import
     validates everything again before creating anything. --}}
@section('title')
    {{ trans('admin/hardware/import.results.title') }}
    @parent
@stop

@use('App\Models\AssetImportSession')
@use('App\Services\AssetImport\AssetImportDestination')

@php
    // Columns: every mapped destination, in CSV column order. Model Number
    // is shown together with the model.
    $columns = [];
    foreach ($importSession->mappedDestinations() as $key) {
        if ($key !== null && $key !== 'standard:model_number' && isset($destinations[$key])) {
            $columns[$key] = $destinations[$key]->label;
        }
    }
    $fixed = [];
    foreach (['model', 'status', 'company', 'location'] as $field) {
        if ($importSession->{$field.'_source'} === AssetImportSession::SOURCE_FIXED) {
            $fixed[AssetImportDestination::standardKey($field)] = trans(\App\Services\AssetImport\AssetImportFieldCatalog::STANDARD_FIELDS[$field]);
        }
    }
    $firstRow = $result->rows[0] ?? null;
@endphp

@section('content')
    <x-container class="col-md-12">
        @include('hardware/import/_steps', ['current' => 'preview'])

        <x-box :header="trans('admin/hardware/import.results.title')">
            <p class="text-muted" id="asset-import-no-assets">{{ trans('admin/hardware/import.results.no_assets_created') }}</p>

            <div class="row" id="asset-import-counts">
                <div class="col-sm-3"><strong>{{ trans('admin/hardware/import.results.total') }}</strong><br><span data-count="total">{{ number_format($result->totalRows()) }}</span></div>
                <div class="col-sm-3"><strong>{{ trans('admin/hardware/import.results.valid') }}</strong><br><span data-count="valid">{{ number_format($result->validRows()) }}</span></div>
                <div class="col-sm-3"><strong>{{ trans('admin/hardware/import.results.invalid') }}</strong><br><span data-count="invalid">{{ number_format($result->invalidRows()) }}</span></div>
                <div class="col-sm-3"><strong>{{ trans('admin/hardware/import.results.errors') }}</strong><br><span data-count="errors">{{ number_format($result->errorCount()) }}</span></div>
            </div>

            <dl class="dl-horizontal" style="margin-top: 15px;">
                <dt>{{ trans('admin/hardware/import.review.file') }}</dt>
                <dd>{{ $importSession->original_filename }}</dd>
                <dt>{{ trans('admin/hardware/import.review.category') }}</dt>
                <dd>{{ $categoryLabel }}</dd>
                @if ($importSession->validated_at)
                    <dt>{{ trans('admin/hardware/import.results.validated_at') }}</dt>
                    <dd>{{ \App\Helpers\Helper::getFormattedDateObject($importSession->validated_at, 'datetime', false) }}</dd>
                @endif
                @foreach ($fixed as $key => $label)
                    <dt>{{ $label }}</dt>
                    <dd>{{ $firstRow?->display[$key] ?? '' }}</dd>
                @endforeach
            </dl>

            @if ($stale)
                <div class="callout callout-warning" role="alert" id="asset-import-stale">{{ trans('admin/hardware/import.results.stale') }}</div>
            @endif
            @if ($executionErrors !== [])
                <div class="callout callout-danger" role="alert" id="asset-import-execution-failed">
                    <p><strong>{{ trans('admin/hardware/import.execution.failed') }}</strong></p>
                    @if ($importSession->execution_failed_at)
                        <p>{{ trans('admin/hardware/import.execution.failed_at') }} {{ \App\Helpers\Helper::getFormattedDateObject($importSession->execution_failed_at, 'datetime', false) }}</p>
                    @endif
                    <ul>
                        @foreach (array_slice($executionErrors, 0, 20) as $error)
                            <li>
                                @if (! empty($error['row']))
                                    {{ trans('admin/hardware/import.results.row') }} {{ $error['row'] }}@if (! empty($error['asset_tag'])) ({{ $error['asset_tag'] }})@endif:
                                @endif
                                @if (! empty($error['field']))
                                    {{ $error['field'] }} &mdash;
                                @endif
                                {{ $error['message'] ?? '' }}
                            </li>
                        @endforeach
                    </ul>
                    @if (count($executionErrors) > 20)
                        <p>{{ trans('admin/hardware/import.execution.more_errors', ['count' => number_format(count($executionErrors) - 20)]) }}</p>
                    @endif
                    <a class="btn btn-default btn-sm" id="asset-import-download-execution-errors" href="{{ route('hardware.import.execution_errors', $importSession->public_id) }}">
                        <x-icon type="download" /> {{ trans('admin/hardware/import.execution.download_errors') }}
                    </a>
                </div>
            @endif
            @if ($eligible)
                <div class="callout callout-success" role="status" id="asset-import-eligible">{{ trans('admin/hardware/import.results.eligible') }}</div>
            @else
                <div class="callout callout-danger" role="alert" id="asset-import-not-eligible">{{ trans('admin/hardware/import.results.not_eligible') }}</div>
            @endif

            <x-slot:customfooter>
                <div class="box-footer">
                    <a class="btn btn-default" href="{{ route('hardware.import.target', $importSession->public_id) }}">{{ trans('admin/hardware/import.review.edit_target') }}</a>
                    <a class="btn btn-default" href="{{ route('hardware.import.mapping', $importSession->public_id) }}">{{ trans('admin/hardware/import.review.edit_mapping') }}</a>
                    @if ($result->errorCount() > 0)
                        <a class="btn btn-default" id="asset-import-download-errors" href="{{ route('hardware.import.errors', $importSession->public_id) }}">
                            <x-icon type="download" /> {{ trans('admin/hardware/import.results.download_errors') }}
                        </a>
                    @endif
                    <form method="post" action="{{ route('hardware.import.validate', $importSession->public_id) }}" class="inline" id="asset-import-revalidate">
                        @csrf
                        <button type="submit" class="btn btn-primary">{{ trans('admin/hardware/import.results.revalidate') }}</button>
                    </form>
                    @if ($eligible)
                        <form method="post" action="{{ route('hardware.import.execute', $importSession->public_id) }}" class="pull-right" id="asset-import-execute">
                            @csrf
                            <input type="hidden" name="confirmation" value="{{ $confirmationToken }}">
                            <label class="form-control" style="display: inline-block; width: auto; margin-right: 10px;">
                                <input type="checkbox" name="confirm" value="1" required id="asset-import-confirm-checkbox">
                                {{ trans_choice('admin/hardware/import.execution.confirm_label', $result->totalRows(), ['count' => number_format($result->totalRows())]) }}
                            </label>
                            <button type="submit" class="btn btn-success" id="asset-import-confirm">
                                {{ trans('admin/hardware/import.execution.confirm_button') }}
                            </button>
                        </form>
                    @endif
                </div>
            </x-slot:customfooter>
        </x-box>

        <x-box :header="trans('admin/hardware/import.steps.preview')">
            <ul class="nav nav-pills" style="margin-bottom: 10px;">
                <li @class(['active' => ! $invalidOnly])><a href="{{ route('hardware.import.results', $importSession->public_id) }}">{{ trans('admin/hardware/import.results.show_all') }}</a></li>
                <li @class(['active' => $invalidOnly])><a href="{{ route('hardware.import.results', [$importSession->public_id, 'filter' => 'invalid']) }}">{{ trans('admin/hardware/import.results.show_invalid') }}</a></li>
            </ul>

            @if ($rows->isEmpty())
                <p>{{ trans('admin/hardware/import.results.no_rows') }}</p>
            @else
                <div class="table-responsive">
                    <table class="table table-striped table-condensed" id="asset-import-preview">
                        <thead>
                            <tr>
                                <th scope="col">{{ trans('admin/hardware/import.results.row') }}</th>
                                <th scope="col">{{ trans('admin/hardware/import.results.status') }}</th>
                                @foreach ($columns as $label)
                                    <th scope="col">{{ $label }}</th>
                                @endforeach
                                <th scope="col">{{ trans('admin/hardware/import.results.row_errors') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr @class(['danger' => ! $row->isValid()]) data-row="{{ $row->row }}">
                                    <td>{{ $row->row }}</td>
                                    <td>
                                        @if ($row->isValid())
                                            <span class="label label-success">{{ trans('admin/hardware/import.results.row_valid') }}</span>
                                        @else
                                            <span class="label label-danger">{{ trans('admin/hardware/import.results.row_invalid') }}</span>
                                        @endif
                                    </td>
                                    @foreach ($columns as $key => $label)
                                        <td @if ($key === 'standard:notes') class="asset-import-notes" style="white-space: pre-line;" @endif>{{ $row->display[$key] ?? trans('admin/hardware/import.results.not_applicable') }}</td>
                                    @endforeach
                                    <td>
                                        <ul class="list-unstyled" style="margin: 0;">
                                            @foreach ($row->errors as $error)
                                                <li><strong>{{ $error->label }}:</strong> {{ $error->message }}</li>
                                            @endforeach
                                        </ul>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $rows->links() }}
            @endif
        </x-box>
    </x-container>
@stop
