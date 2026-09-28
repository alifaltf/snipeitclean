{{--
    ERS Phase 5A: asset category permission matrix on the permission-group form.

    $assetCategoryMatrix (from GroupsController::assetCategoryMatrix) is only
    set for Super Users; the @can check is a second guard. Rows are the live
    asset category tree; nothing here names a category.

    Only final categories have named checkboxes
    (asset_category_permissions[<id>][<operation>] = 1). Navigation-group
    checkboxes have no name, are never submitted, and only tick/clear the
    final categories beneath them. The hidden marker makes an all-unchecked
    matrix mean "remove every grant". The server re-validates everything.
--}}
@php
    $acpOperations = \App\Services\AssetCategoryAccess::OPERATIONS;
    $acpInput = \App\Actions\Groups\SaveGroupAssetCategoryPermissionsAction::INPUT;
@endphp

@can(\App\Actions\Groups\SaveGroupAssetCategoryPermissionsAction::ABILITY)
    @if (! empty($assetCategoryMatrix))
        <fieldset class="col-md-12" id="asset-category-permissions" aria-describedby="asset-category-permissions-help">
            <x-form.legend>
                {{ trans('admin/groups/asset_category_permissions.title') }}
            </x-form.legend>

            <p class="help-block" id="asset-category-permissions-help">
                {{ trans('admin/groups/asset_category_permissions.help') }}
            </p>

            <input type="hidden" name="{{ \App\Actions\Groups\SaveGroupAssetCategoryPermissionsAction::MARKER }}" value="1">

            @if ($errors->has($acpInput))
                <div class="alert alert-danger" role="alert">
                    {{ $errors->first($acpInput) }}
                </div>
            @endif

            @if (count($assetCategoryMatrix['rows']) === 0)
                <p>{{ trans('admin/groups/asset_category_permissions.no_categories') }}</p>
            @else
                <div class="table-responsive">
                    <table class="table table-condensed table-hover ers-acp-table">
                        <caption class="sr-only">{{ trans('admin/groups/asset_category_permissions.title') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ trans('admin/groups/asset_category_permissions.category') }}</th>
                                @foreach ($acpOperations as $operation)
                                    <th scope="col" class="text-center">{{ trans('admin/groups/asset_category_permissions.operation.'.$operation) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($assetCategoryMatrix['rows'] as $row)
                                <tr @class(['ers-acp-group' => $row['group'], 'ers-acp-final' => ! $row['group']])
                                    data-acp-node="{{ $row['id'] }}"
                                    data-acp-ancestors="{{ implode(' ', $row['ancestors']) }}">
                                    <th scope="row" style="padding-left: {{ 8 + ($row['depth'] - 1) * 20 }}px; font-weight: {{ $row['group'] ? 'bold' : 'normal' }};">
                                        {{ $row['name'] }}
                                        @if ($row['group'])
                                            <span class="text-muted small">({{ trans('admin/groups/asset_category_permissions.navigation_group') }})</span>
                                        @endif
                                    </th>
                                    @foreach ($acpOperations as $operation)
                                        @php $operationLabel = trans('admin/groups/asset_category_permissions.operation.'.$operation); @endphp
                                        <td class="text-center">
                                            @if ($row['group'])
                                                <input type="checkbox"
                                                       class="ers-acp-bulk"
                                                       data-acp-operation="{{ $operation }}"
                                                       data-acp-node="{{ $row['id'] }}"
                                                       aria-label="{{ trans('admin/groups/asset_category_permissions.bulk_label', ['operation' => $operationLabel, 'category' => $row['name']]) }}">
                                            @else
                                                <input type="checkbox"
                                                       class="ers-acp-cell"
                                                       name="{{ $acpInput }}[{{ $row['id'] }}][{{ $operation }}]"
                                                       value="1"
                                                       data-acp-operation="{{ $operation }}"
                                                       data-acp-ancestors="{{ implode(' ', $row['ancestors']) }}"
                                                       aria-label="{{ trans('admin/groups/asset_category_permissions.cell_label', ['operation' => $operationLabel, 'category' => $row['name']]) }}"
                                                       @checked($assetCategoryMatrix['selected'][$row['id']][$operation] ?? false)>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </fieldset>

        <script nonce="{{ csrf_token() }}">
            (function () {
                var table = document.querySelector('#asset-category-permissions .ers-acp-table');
                if (!table) {
                    return;
                }

                function cellsUnder(nodeId, operation) {
                    return table.querySelectorAll('input.ers-acp-cell[data-acp-operation="' + operation + '"][data-acp-ancestors~="' + nodeId + '"]');
                }

                // Reflect descendants in each bulk box: checked when all are,
                // indeterminate when some are.
                function refreshBulk() {
                    table.querySelectorAll('input.ers-acp-bulk').forEach(function (bulk) {
                        var cells = cellsUnder(bulk.getAttribute('data-acp-node'), bulk.getAttribute('data-acp-operation'));
                        var checked = 0;
                        cells.forEach(function (cell) { if (cell.checked) { checked++; } });
                        bulk.checked = cells.length > 0 && checked === cells.length;
                        bulk.indeterminate = checked > 0 && checked < cells.length;
                    });
                }

                table.addEventListener('change', function (event) {
                    var target = event.target;
                    if (target.classList.contains('ers-acp-bulk')) {
                        cellsUnder(target.getAttribute('data-acp-node'), target.getAttribute('data-acp-operation'))
                            .forEach(function (cell) { cell.checked = target.checked; });
                    }
                    refreshBulk();
                });

                refreshBulk();
            })();
        </script>
    @endif
@endcan
