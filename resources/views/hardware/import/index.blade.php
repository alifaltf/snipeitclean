@extends('layouts/default')

{{-- ERS Phase 6A: secure asset CSV import - upload step. --}}
@section('title')
    {{ trans('admin/hardware/import.title') }}
    @parent
@stop

@section('content')
    <x-container class="col-md-10 col-md-offset-1">
        @include('hardware/import/_steps', ['current' => 'upload'])

        <x-box :header="trans('admin/hardware/import.steps.upload')">
            <p>{{ trans('admin/hardware/import.intro') }}</p>

            <form id="asset-import-upload" method="post" action="{{ route('hardware.import.store') }}" enctype="multipart/form-data" class="form-horizontal">
                @csrf
                @if ($categoryId !== null)
                    {{-- Re-validated on upload and again on the target step. --}}
                    <input type="hidden" name="category" value="{{ $categoryId }}">
                    <div class="form-group">
                        <span class="col-md-3 control-label"><strong>{{ trans('admin/hardware/import.target.category') }}</strong></span>
                        <div class="col-md-7">
                            <p class="form-control-static" id="asset-import-preselected-category">{{ $categoryLabel }}</p>
                        </div>
                    </div>
                @endif
                <div class="form-group {{ $errors->has('csv_file') ? 'has-error' : '' }}">
                    <label for="csv_file" class="col-md-3 control-label">{{ trans('admin/hardware/import.upload.label') }}</label>
                    <div class="col-md-7">
                        <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required aria-describedby="csv_file_help">
                        <p class="help-block" id="csv_file_help">
                            {{ trans('admin/hardware/import.upload.help', ['size' => (int) round(config('asset_import.max_file_size_kb') / 1024), 'rows' => number_format((int) config('asset_import.max_data_rows'))]) }}
                        </p>
                        @error('csv_file')
                            <span class="alert-msg" aria-live="polite"><x-icon type="x" /> {{ $message }}</span>
                        @enderror
                    </div>
                </div>
                <div class="text-right">
                    <button type="submit" class="btn btn-primary">
                        <x-icon type="import" /> {{ trans('admin/hardware/import.upload.button') }}
                    </button>
                </div>
            </form>
        </x-box>

        <x-box :header="trans('admin/hardware/import.sessions.title')">
            @if ($sessions->isEmpty())
                <p>{{ trans('admin/hardware/import.sessions.none') }}</p>
            @else
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ trans('admin/hardware/import.sessions.file') }}</th>
                            <th>{{ trans('admin/hardware/import.sessions.rows') }}</th>
                            <th>{{ trans('admin/hardware/import.sessions.state') }}</th>
                            <th>{{ trans('admin/hardware/import.sessions.expires') }}</th>
                            <th><span class="sr-only">{{ trans('admin/hardware/import.sessions.continue') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sessions as $importSession)
                            <tr>
                                <td>{{ $importSession->original_filename }}</td>
                                <td>{{ number_format($importSession->row_count) }}</td>
                                <td>{{ trans('admin/hardware/import.states.'.$importSession->state) }}</td>
                                <td>{{ \App\Helpers\Helper::getFormattedDateObject($importSession->expires_at, 'datetime', false) }}</td>
                                <td class="text-right">
                                    <a class="btn btn-sm btn-default" href="{{ route($importSession->state === \App\Models\AssetImportSession::STATE_MAPPED ? 'hardware.import.review' : ($importSession->state === \App\Models\AssetImportSession::STATE_TARGET_SELECTED ? 'hardware.import.mapping' : 'hardware.import.target'), $importSession->public_id) }}">
                                        {{ trans('admin/hardware/import.sessions.continue') }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-box>
    </x-container>
@stop
