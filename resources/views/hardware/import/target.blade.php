@extends('layouts/default')

{{-- ERS Phase 6A: secure asset CSV import - target configuration. --}}
@section('title')
    {{ trans('admin/hardware/import.steps.target') }}
    @parent
@stop

@use('App\Models\AssetImportSession')

@php
    $sourceValue = fn (string $column, string $default) => old($column, $importSession->{$column} ?? $default);
    $idValue = fn (string $column) => (string) old($column, $importSession->{$column} ?? '');
@endphp

@section('content')
    <x-container class="col-md-10 col-md-offset-1">
        @include('hardware/import/_steps', ['current' => 'target'])

        <x-box :header="$importSession->original_filename">
            <form id="asset-import-category" method="get" action="{{ route('hardware.import.target', $importSession->public_id) }}" class="form-horizontal">
                <div class="form-group {{ $errors->has('category_id') ? 'has-error' : '' }}">
                    <label for="category" class="col-md-3 control-label">{{ trans('admin/hardware/import.target.category') }}</label>
                    <div class="col-md-6">
                        <select id="category" name="category" class="form-control" aria-describedby="category_help" required>
                            <option value="">{{ trans('admin/hardware/import.target.select') }}</option>
                            @foreach ($categories as $id => $label)
                                <option value="{{ $id }}" @selected($categoryId === $id)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="help-block" id="category_help">
                            {{ trans('admin/hardware/import.target.category_help') }}
                            @if ($importSession->mapping !== null)
                                {{ trans('admin/hardware/import.target.change_warning') }}
                            @endif
                        </p>
                        @error('category_id')
                            <span class="alert-msg" aria-live="polite"><x-icon type="x" /> {{ $message }}</span>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-default" id="choose-category">{{ trans('admin/hardware/import.target.choose_category') }}</button>
                    </div>
                </div>
            </form>

            @if ($categoryId !== null)
                <form id="asset-import-target" method="post" action="{{ route('hardware.import.target.update', $importSession->public_id) }}" class="form-horizontal">
                    @csrf
                    <input type="hidden" name="category_id" value="{{ $categoryId }}">

                    {{-- Model --}}
                    <fieldset class="form-group {{ $errors->has('model_source') || $errors->has('model_id') ? 'has-error' : '' }}">
                        <legend class="col-md-3 control-label" style="border: 0; font-size: inherit; font-weight: bold;">{{ trans('admin/hardware/import.target.model_source') }}</legend>
                        <div class="col-md-8">
                            <label class="form-control">
                                <input type="radio" name="model_source" value="{{ AssetImportSession::SOURCE_FIXED }}" @checked($sourceValue('model_source', AssetImportSession::SOURCE_FIXED) === AssetImportSession::SOURCE_FIXED)>
                                {{ trans('admin/hardware/import.target.model_fixed') }}
                            </label>
                            @if ($models->isEmpty())
                                <p class="help-block">{{ trans('admin/hardware/import.target.no_models') }}</p>
                            @else
                                <select name="model_id" class="form-control" aria-label="{{ trans('admin/hardware/import.target.model_fixed') }}">
                                    <option value="">{{ trans('admin/hardware/import.target.select') }}</option>
                                    @foreach ($models as $model)
                                        <option value="{{ $model->id }}" @selected($idValue('model_id') === (string) $model->id)>{{ $model->name }}@if ($model->model_number) ({{ $model->model_number }})@endif</option>
                                    @endforeach
                                </select>
                            @endif
                            <label class="form-control" style="margin-top: 10px;">
                                <input type="radio" name="model_source" value="{{ AssetImportSession::SOURCE_COLUMN }}" @checked($sourceValue('model_source', AssetImportSession::SOURCE_FIXED) === AssetImportSession::SOURCE_COLUMN)>
                                {{ trans('admin/hardware/import.target.model_column') }}
                            </label>
                            <p class="help-block">{{ trans('admin/hardware/import.target.model_column_help') }}</p>
                            @foreach (['model_source', 'model_id'] as $field)
                                @error($field)
                                    <span class="alert-msg" aria-live="polite"><x-icon type="x" /> {{ $message }}</span>
                                @enderror
                            @endforeach
                        </div>
                    </fieldset>

                    @foreach ([
                        ['status', $statuses, false],
                        ['company', $companies, true],
                        ['location', $locations, true],
                    ] as [$name, $options, $optional])
                        @php
                            $source = $name.'_source';
                            $current = $sourceValue($source, $optional ? AssetImportSession::SOURCE_NONE : AssetImportSession::SOURCE_FIXED);
                        @endphp
                        <fieldset class="form-group {{ $errors->has($source) || $errors->has($name.'_id') ? 'has-error' : '' }}">
                            <legend class="col-md-3 control-label" style="border: 0; font-size: inherit; font-weight: bold;">{{ trans('admin/hardware/import.target.'.$source) }}</legend>
                            <div class="col-md-8">
                                @if ($optional)
                                    <label class="form-control">
                                        <input type="radio" name="{{ $source }}" value="{{ AssetImportSession::SOURCE_NONE }}" @checked($current === AssetImportSession::SOURCE_NONE)>
                                        {{ trans('admin/hardware/import.target.company_location_none') }}
                                    </label>
                                @endif
                                <label class="form-control" @if ($optional) style="margin-top: 10px;" @endif>
                                    <input type="radio" name="{{ $source }}" value="{{ AssetImportSession::SOURCE_FIXED }}" @checked($current === AssetImportSession::SOURCE_FIXED)>
                                    {{ trans('admin/hardware/import.target.'.$name.'_fixed') }}
                                </label>
                                <select name="{{ $name }}_id" class="form-control" aria-label="{{ trans('admin/hardware/import.target.'.$name.'_fixed') }}">
                                    <option value="">{{ trans('admin/hardware/import.target.select') }}</option>
                                    @foreach ($options as $option)
                                        <option value="{{ $option->id }}" @selected($idValue($name.'_id') === (string) $option->id)>{{ $option->name }}</option>
                                    @endforeach
                                </select>
                                <label class="form-control" style="margin-top: 10px;">
                                    <input type="radio" name="{{ $source }}" value="{{ AssetImportSession::SOURCE_COLUMN }}" @checked($current === AssetImportSession::SOURCE_COLUMN)>
                                    {{ trans('admin/hardware/import.target.'.$name.'_column') }}
                                </label>
                                @foreach ([$source, $name.'_id'] as $field)
                                    @error($field)
                                        <span class="alert-msg" aria-live="polite"><x-icon type="x" /> {{ $message }}</span>
                                    @enderror
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach

                    <div class="box-footer text-right">
                        <a class="btn btn-link pull-left" href="{{ route('hardware.import.index') }}">{{ trans('button.cancel') }}</a>
                        <button type="submit" class="btn btn-primary">{{ trans('admin/hardware/import.target.save') }}</button>
                    </div>
                </form>
            @endif
        </x-box>
    </x-container>
@stop

@push('js')
    <script nonce="{{ csrf_token() }}">
        // Reload the model list when another category is chosen. Without
        // JavaScript the "Show models" button does the same.
        document.getElementById('category').addEventListener('change', function () {
            if (this.value !== '') {
                document.getElementById('asset-import-category').submit();
            }
        });
    </script>
@endpush
