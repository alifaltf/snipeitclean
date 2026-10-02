@extends('layouts/default')

{{-- ERS Phase 6A: secure asset CSV import - configuration review only.
     No row data, no validation counts and no import action yet. --}}
@section('title')
    {{ trans('admin/hardware/import.review.title') }}
    @parent
@stop

@use('App\Models\AssetImportSession')

@php
    $sourceText = function (?string $source, $record) {
        return match ($source) {
            AssetImportSession::SOURCE_FIXED => trans('admin/hardware/import.review.fixed', ['value' => $record?->name ?? '']),
            AssetImportSession::SOURCE_COLUMN => trans('admin/hardware/import.review.from_column'),
            default => trans('admin/hardware/import.review.none'),
        };
    };
    $entries = collect($importSession->mapping);
    $mapped = $entries->filter(fn ($entry) => $entry['destination'] !== null);
    $ignored = $entries->filter(fn ($entry) => $entry['destination'] === null);
@endphp

@section('content')
    <x-container class="col-md-10 col-md-offset-1">
        @include('hardware/import/_steps', ['current' => 'review'])

        <x-box :header="trans('admin/hardware/import.review.title')">
            <dl class="dl-horizontal" id="asset-import-summary">
                <dt>{{ trans('admin/hardware/import.review.file') }}</dt>
                <dd>{{ $importSession->original_filename }}</dd>
                <dt>{{ trans('admin/hardware/import.review.file_size') }}</dt>
                <dd>{{ \App\Helpers\Helper::formatFilesizeUnits($importSession->file_size) }}</dd>
                <dt>{{ trans('admin/hardware/import.review.rows') }}</dt>
                <dd>{{ number_format($importSession->row_count) }}</dd>
                <dt>{{ trans('admin/hardware/import.review.category') }}</dt>
                <dd>{{ $categoryLabel }}</dd>
                <dt>{{ trans('admin/hardware/import.review.model') }}</dt>
                <dd>
                    {{ $sourceText($importSession->model_source, $model) }}
                    @if ($model?->model_number) ({{ $model->model_number }}) @endif
                </dd>
                <dt>{{ trans('admin/hardware/import.review.status') }}</dt>
                <dd>{{ $sourceText($importSession->status_source, $status) }}</dd>
                <dt>{{ trans('admin/hardware/import.review.company') }}</dt>
                <dd>{{ $sourceText($importSession->company_source, $company) }}</dd>
                <dt>{{ trans('admin/hardware/import.review.location') }}</dt>
                <dd>{{ $sourceText($importSession->location_source, $location) }}</dd>
            </dl>
            <p class="text-muted">{{ trans('admin/hardware/import.review.expires', ['time' => \App\Helpers\Helper::getFormattedDateObject($importSession->expires_at, 'datetime', false)]) }}</p>
        </x-box>

        <x-box :header="trans('admin/hardware/import.review.columns')">
            <table class="table table-striped" id="asset-import-mapped">
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">{{ trans('admin/hardware/import.mapping.column') }}</th>
                        <th scope="col">{{ trans('admin/hardware/import.mapping.destination') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($mapped as $entry)
                        @php($destination = $destinations[$entry['destination']])
                        <tr>
                            <td>{{ $entry['column'] + 1 }}</td>
                            <td>{{ $entry['header'] }}</td>
                            <td>
                                {{ $destination->label }}
                                @if ($destination->required)
                                    <span class="label label-primary">{{ trans('admin/hardware/import.mapping.required') }}</span>
                                @endif
                                @if ($destination->sensitive)
                                    <span class="label label-warning">{{ trans('admin/hardware/import.mapping.sensitive') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <h3 class="box-title">{{ trans('admin/hardware/import.review.ignored') }}</h3>
            @if ($ignored->isEmpty())
                <p>{{ trans('admin/hardware/import.review.no_ignored') }}</p>
            @else
                <ul id="asset-import-ignored">
                    @foreach ($ignored as $entry)
                        <li>{{ $entry['column'] + 1 }}. {{ $entry['header'] }}</li>
                    @endforeach
                </ul>
            @endif

            <h3 class="box-title">{{ trans('admin/hardware/import.review.required') }}</h3>
            <ul class="list-unstyled" id="asset-import-required">
                @foreach ($requiredKeys as $key)
                    <li><x-icon type="checkmark" class="text-success" /> {{ $destinations[$key]->label }}</li>
                @endforeach
            </ul>
            <p>{{ trans('admin/hardware/import.review.complete') }}</p>

            <x-slot:customfooter>
                <div class="box-footer">
                    <a class="btn btn-default" href="{{ route('hardware.import.target', $importSession->public_id) }}">{{ trans('admin/hardware/import.review.edit_target') }}</a>
                    <a class="btn btn-default" href="{{ route('hardware.import.mapping', $importSession->public_id) }}">{{ trans('admin/hardware/import.review.edit_mapping') }}</a>
                </div>
            </x-slot:customfooter>
        </x-box>

        <div class="callout callout-info" role="status" id="asset-import-next-phase">
            {{ trans('admin/hardware/import.review.next_phase') }}
        </div>
    </x-container>
@stop
