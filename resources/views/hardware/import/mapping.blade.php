@extends('layouts/default')

{{-- ERS Phase 6A: secure asset CSV import - column mapping. --}}
@section('title')
    {{ trans('admin/hardware/import.steps.mapping') }}
    @parent
@stop

@php
    $standard = array_filter($destinations, fn ($d) => $d->kind === \App\Services\AssetImport\AssetImportDestination::KIND_STANDARD);
    $custom = array_filter($destinations, fn ($d) => $d->kind === \App\Services\AssetImport\AssetImportDestination::KIND_CUSTOM_FIELD);
    $optionLabel = function ($destination) {
        $label = $destination->label;
        if ($destination->required) {
            $label .= ' *';
        }
        if ($destination->sensitive) {
            $label .= ' ('.trans('admin/hardware/import.mapping.sensitive').')';
        }

        return $label;
    };
@endphp

@section('content')
    <x-container class="col-md-12">
        @include('hardware/import/_steps', ['current' => 'mapping'])

        <form id="asset-import-mapping" method="post" action="{{ route('hardware.import.mapping.update', $importSession->public_id) }}">
            @csrf
            <div class="row">
                <div class="col-md-8">
                    <x-box :header="$importSession->original_filename">
                        <p>{{ trans('admin/hardware/import.mapping.intro') }}</p>
                        @if ($suggested && $selected !== [])
                            <div class="callout callout-info" role="status">{{ trans('admin/hardware/import.mapping.suggested') }}</div>
                        @endif
                        @error('mapping')
                            <div class="callout callout-danger" role="alert">
                                @foreach ($errors->get('mapping') as $message)
                                    <p>{{ $message }}</p>
                                @endforeach
                            </div>
                        @enderror

                        <table class="table table-striped" id="asset-import-columns">
                            <thead>
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">{{ trans('admin/hardware/import.mapping.column') }}</th>
                                    <th scope="col">{{ trans('admin/hardware/import.mapping.samples') }}</th>
                                    <th scope="col">{{ trans('admin/hardware/import.mapping.destination') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($importSession->headerList() as $index => $header)
                                    @php($current = (string) old('mapping.'.$index, $selected[$index] ?? ''))
                                    <tr class="asset-import-dropzone {{ $errors->has('mapping.'.$index) ? 'danger' : '' }}" data-column="{{ $index }}">
                                        <td>{{ $index + 1 }}</td>
                                        <td>
                                            <label for="mapping_{{ $index }}" style="font-weight: normal;">{{ $header }}</label>
                                            @if ($suggested && isset($selected[$index]))
                                                <span class="label label-info">{{ trans('admin/hardware/import.mapping.suggestion') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            {{-- Escaped plain text, bounded by AssetImportCsvSampler. --}}
                                            <ul class="list-unstyled text-muted asset-import-samples" data-column="{{ $index }}" style="margin: 0;">
                                                @foreach ($samples as $sampleRow)
                                                    <li>{{ $sampleRow[$index] !== '' ? $sampleRow[$index] : '—' }}</li>
                                                @endforeach
                                            </ul>
                                        </td>
                                        <td>
                                            <select id="mapping_{{ $index }}" name="mapping[{{ $index }}]" class="form-control asset-import-destination">
                                                <option value="">{{ trans('admin/hardware/import.mapping.do_not_import') }}</option>
                                                <optgroup label="{{ trans('admin/hardware/import.mapping.standard_fields') }}">
                                                    @foreach ($standard as $key => $destination)
                                                        <option value="{{ $key }}" @selected($current === $key)>{{ $optionLabel($destination) }}</option>
                                                    @endforeach
                                                </optgroup>
                                                @if ($custom !== [])
                                                    <optgroup label="{{ trans('admin/hardware/import.mapping.custom_fields') }}">
                                                        @foreach ($custom as $key => $destination)
                                                            <option value="{{ $key }}" @selected($current === $key)>{{ $optionLabel($destination) }}</option>
                                                        @endforeach
                                                    </optgroup>
                                                @endif
                                            </select>
                                            @foreach ($errors->get('mapping.'.$index) as $message)
                                                <span class="alert-msg" aria-live="polite"><x-icon type="x" /> {{ $message }}</span>
                                            @endforeach
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        <x-slot:customfooter>
                            <div class="box-footer text-right">
                                <a class="btn btn-link pull-left" href="{{ route('hardware.import.target', $importSession->public_id) }}">{{ trans('admin/hardware/import.mapping.back') }}</a>
                                <button type="submit" class="btn btn-primary">{{ trans('admin/hardware/import.mapping.save') }}</button>
                            </div>
                        </x-slot:customfooter>
                    </x-box>
                </div>

                <div class="col-md-4">
                    <x-box :header="trans('admin/hardware/import.mapping.fields')">
                        <p class="help-block">{{ trans('admin/hardware/import.mapping.drag_help') }}</p>
                        <p class="help-block">* {{ trans('admin/hardware/import.mapping.required') }}</p>
                        <ul class="list-unstyled" id="asset-import-palette">
                            @foreach ($destinations as $key => $destination)
                                <li draggable="true" data-destination="{{ $key }}" class="asset-import-chip" style="cursor: move; padding: 4px 8px; margin-bottom: 4px; border: 1px solid #ccc; border-radius: 3px;">
                                    {{ $destination->label }}
                                    @if ($destination->required)
                                        <span class="text-danger" title="{{ trans('admin/hardware/import.mapping.required') }}">*</span>
                                    @endif
                                    @if ($destination->sensitive)
                                        <span class="label label-warning">{{ trans('admin/hardware/import.mapping.sensitive') }}</span>
                                    @endif
                                    @if ($destination->requiredByFieldset)
                                        <span class="label label-default" title="{{ trans('admin/hardware/import.mapping.required_by_fieldset') }}">{{ trans('admin/hardware/import.mapping.required') }}†</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if (collect($destinations)->contains(fn ($d) => $d->requiredByFieldset))
                            <p class="help-block">† {{ trans('admin/hardware/import.mapping.required_by_fieldset') }}</p>
                        @endif
                    </x-box>
                </div>
            </div>
        </form>
    </x-container>
@stop

@push('js')
    <script nonce="{{ csrf_token() }}">
        // Drag-and-drop on top of the dropdowns (which stay the accessible,
        // authoritative control). Dropping a field onto a column selects it
        // there and clears it from any other column; the server still
        // validates the whole mapping.
        (function () {
            var selects = document.querySelectorAll('.asset-import-destination');

            function assign(select, key) {
                selects.forEach(function (other) {
                    if (other !== select && other.value === key) {
                        other.value = '';
                    }
                });
                select.value = key;
            }

            document.querySelectorAll('.asset-import-chip').forEach(function (chip) {
                chip.addEventListener('dragstart', function (event) {
                    event.dataTransfer.setData('text/plain', chip.getAttribute('data-destination'));
                    event.dataTransfer.effectAllowed = 'copy';
                });
            });

            document.querySelectorAll('.asset-import-dropzone').forEach(function (row) {
                row.addEventListener('dragover', function (event) {
                    event.preventDefault();
                    row.classList.add('info');
                });
                row.addEventListener('dragleave', function () {
                    row.classList.remove('info');
                });
                row.addEventListener('drop', function (event) {
                    event.preventDefault();
                    row.classList.remove('info');
                    var key = event.dataTransfer.getData('text/plain');
                    var select = row.querySelector('.asset-import-destination');
                    if (select && select.querySelector('option[value="' + CSS.escape(key) + '"]')) {
                        assign(select, key);
                    }
                });
            });
        })();
    </script>
@endpush
