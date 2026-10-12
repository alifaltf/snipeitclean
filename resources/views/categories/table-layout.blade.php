@extends('layouts/default')

{{--
    ERS Phase 7A: asset-table column layout of one final asset category.

    Works without JavaScript: each selectable column has a "show" checkbox
    and an order number; the shown columns are saved in ascending order.
    Labels are translations or custom-field names from the database, always
    escaped. No asset or custom-field value is shown or submitted. The
    server re-validates everything (AssetTableLayoutService).
--}}

@section('title')
    {{ trans('admin/categories/table_layout.page_title', ['category' => $category->name]) }}
    @parent
@stop

@section('header_right')
    <a href="{{ route('categories.edit', ['category' => $category->id]) }}" class="btn btn-default pull-right">
        {{ trans('general.back') }}
    </a>
@stop

@section('content')
    @php $layoutInput = \App\Services\AssetTableLayout\AssetTableLayoutForm::INPUT; @endphp

    <div class="row">
        <div class="col-lg-10 col-lg-offset-1 col-md-12">

            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">{{ trans('admin/categories/table_layout.current_layout') }}</h2>
                </div>
                <div class="box-body">
                    <p>
                        {{ $explicit ? trans('admin/categories/table_layout.using_explicit') : trans('admin/categories/table_layout.using_default') }}
                    </p>
                    <ol id="ers-current-layout">
                        @foreach ($effectiveColumns as $column)
                            <li>
                                {{ $column->label }}
                                @if ($column->encrypted)
                                    <span class="text-muted small"><x-icon type="locked" /> {{ trans('admin/categories/table_layout.encrypted') }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>

            <form method="POST" action="{{ route('categories.table-layout.update', ['category' => $category->id]) }}" id="ers-table-layout-form" aria-describedby="ers-table-layout-help">
                @csrf
                @method('PUT')

                <div class="box box-default">
                    <div class="box-header with-border">
                        <h2 class="box-title">{{ trans('admin/categories/table_layout.title') }}</h2>
                        <div class="box-tools pull-right">
                            <button type="submit" class="btn btn-primary btn-sm">{{ trans('admin/categories/table_layout.save') }}</button>
                        </div>
                    </div>
                    <div class="box-body">
                        <div id="ers-table-layout-help">
                            <p>{{ trans('admin/categories/table_layout.intro') }}</p>
                            <p class="help-block"><strong>{{ trans('admin/categories/table_layout.help_hidden') }}</strong></p>
                            <p class="help-block">{{ trans('admin/categories/table_layout.help_order') }}</p>
                            <p class="help-block">{{ trans('admin/categories/table_layout.help_eligible') }}</p>
                            <p class="help-block">{{ trans('admin/categories/table_layout.help_encrypted') }}</p>
                        </div>

                        @if ($errors->has($layoutInput))
                            <div class="alert alert-danger" role="alert" id="ers-table-layout-error">
                                {{ $errors->first($layoutInput) }}
                            </div>
                        @endif

                        <div class="table-responsive">
                            <table class="table table-condensed table-hover" id="ers-table-layout">
                                <caption class="sr-only">{{ trans('admin/categories/table_layout.title') }}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col" class="text-center">{{ trans('admin/categories/table_layout.show') }}</th>
                                        <th scope="col">{{ trans('admin/categories/table_layout.order') }}</th>
                                        <th scope="col">{{ trans('admin/categories/table_layout.column') }}</th>
                                        <th scope="col">{{ trans('admin/categories/table_layout.type') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $index => $row)
                                        @php
                                            $column = $row['column'];
                                            $positionError = $errors->first($layoutInput.'.'.$index.'.position');
                                            $rowId = 'ers-layout-row-'.$index;
                                        @endphp
                                        <tr @class(['has-error' => $positionError !== ''])>
                                            <td class="text-center">
                                                <input type="hidden" name="{{ $layoutInput }}[{{ $index }}][key]" value="{{ $column->key }}">
                                                <input type="checkbox"
                                                       id="{{ $rowId }}-show"
                                                       name="{{ $layoutInput }}[{{ $index }}][show]"
                                                       value="1"
                                                       aria-label="{{ trans('admin/categories/table_layout.show_column', ['column' => $column->label]) }}"
                                                       @checked($row['show'])>
                                            </td>
                                            <td style="max-width: 110px;">
                                                <input type="number"
                                                       class="form-control input-sm"
                                                       id="{{ $rowId }}-position"
                                                       name="{{ $layoutInput }}[{{ $index }}][position]"
                                                       value="{{ $row['position'] }}"
                                                       min="1"
                                                       max="{{ \App\Services\AssetTableLayout\AssetTableLayoutForm::MAX_POSITION }}"
                                                       step="1"
                                                       aria-label="{{ trans('admin/categories/table_layout.order_column', ['column' => $column->label]) }}"
                                                       @if ($positionError !== '') aria-invalid="true" aria-describedby="{{ $rowId }}-error" @endif>
                                                @if ($positionError !== '')
                                                    <span class="help-block" id="{{ $rowId }}-error">{{ $positionError }}</span>
                                                @endif
                                            </td>
                                            <th scope="row" style="font-weight: normal;">
                                                <label for="{{ $rowId }}-show" style="font-weight: normal;">{{ $column->label }}</label>
                                                @if ($column->encrypted)
                                                    <span class="text-muted small"><x-icon type="locked" /> {{ trans('admin/categories/table_layout.encrypted') }}</span>
                                                @endif
                                            </th>
                                            <td>
                                                {{ $column->isCustomField() ? trans('admin/categories/table_layout.type_custom_field') : trans('admin/categories/table_layout.type_standard') }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="box-footer text-right">
                        <button type="submit" class="btn btn-primary">{{ trans('admin/categories/table_layout.save') }}</button>
                    </div>
                </div>
            </form>

            @if ($explicit)
                <form method="POST" action="{{ route('categories.table-layout.reset', ['category' => $category->id]) }}" id="ers-table-layout-reset">
                    @csrf
                    @method('DELETE')
                    <div class="box box-default">
                        <div class="box-body">
                            <p class="help-block">{{ trans('admin/categories/table_layout.reset_help') }}</p>
                            <button type="submit" class="btn btn-default">{{ trans('admin/categories/table_layout.reset') }}</button>
                        </div>
                    </div>
                </form>
            @endif

        </div>
    </div>
@stop
