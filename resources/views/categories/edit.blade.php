@extends('layouts/default')

{{-- Page title --}}
@section('title')
    @if ($item->id)
        {{ trans('admin/categories/general.update') }}
    @else
        {{ trans('admin/categories/general.create') }}
    @endif
    @parent
@stop

{{-- Page content --}}
@section('content')

    <x-container class="col-lg-8 col-lg-offset-2 col-md-10 col-md-offset-1 col-sm-12 col-sm-offset-0">

        <x-form :$item route="{{ ($item->id) ? route('categories.update', ['category' => $item->id]) : route('categories.store') }}">

            <x-box top_submit>
                @if ($item->id)
                    <x-slot:header>{{ $item->name }}</x-slot:header>
                @endif

                <x-form.row
                    :label="trans('general.name')"
                    :$item
                    name="name"
                />

                <x-form.row
                    :label="trans('general.type')"
                    name="category_type"
                    input_div_class="col-md-7 required"
                    :help_text="trans('admin/categories/message.update.cannot_change_category_type')"
                >
                    <x-slot:input>
                        <x-input.select
                            name="category_type"
                            id="category_type"
                            :options="$category_types"
                            :selected="old('category_type', $item->category_type)"
                            :disabled="$item->category_type != '' || $item->itemCount() > 0"
                            style="min-width:350px"
                            aria-label="category_type"
                        />
                    </x-slot:input>
                </x-form.row>

                {{--
                    ERS-specific "Asset Group" field. Purely presentational —
                    it just tells App\View\Composers\SidebarComposer /
                    App\Http\Controllers\Assets\AssetsController@index which
                    virtual Assets sidebar group (Hardware or Software) this
                    category should be aggregated under. Only meaningful for
                    Type = Asset; App\Http\Controllers\CategoriesController
                    forces it to null server-side for every other type
                    regardless of what's submitted here, so the JS below is a
                    UI convenience only, never the actual enforcement.
                --}}
                <x-form.row
                    id="ers-asset-group-row"
                    :label="'Asset Group'"
                    name="ers_asset_group"
                    input_div_class="col-md-7"
                    :help_text="'Controls whether this asset category appears under Hardware or Software in the Assets sidebar.'"
                >
                    <x-slot:input>
                        <x-input.select
                            name="ers_asset_group"
                            id="ers_asset_group"
                            :options="$ers_asset_group_options"
                            :selected="old('ers_asset_group', $item->ers_asset_group)"
                            :disabled="old('category_type', $item->category_type) != 'asset'"
                            style="min-width:350px"
                            aria-label="ers_asset_group"
                        />
                    </x-slot:input>
                </x-form.row>

                <livewire:category-edit-form
                    :alert-on-response="(bool) old('alert_on_response', $item->alert_on_response)"
                    :default-eula-text="$snipeSettings->default_eula_text"
                    :eula-text="old('eula_text', $item->eula_text)"
                    :require-acceptance="(bool) old('require_acceptance', $item->require_acceptance)"
                    :send-check-in-email="(bool) old('checkin_email', $item->checkin_email)"
                    :use-default-eula="(bool) old('use_default_eula', $item->use_default_eula)"
                />

                <x-input.image-upload :item="$item" :imagePath="app('categories_upload_path')" />

                <x-form.row
                    :label="trans('general.notes')"
                    :$item
                    name="notes"
                    type="textarea"
                    :rows="5"
                    :placeholder="trans('general.placeholders.notes')"
                />

                <fieldset name="color-preferences">
                    <x-form.legend help_text="{{ trans('general.tag_color_help') }}">
                        {{ trans('general.tag_color') }}
                    </x-form.legend>
                    <x-form.row
                        :label="trans('general.tag_color')"
                        :$item
                        name="tag_color"
                        type="colorpicker"
                    />
                </fieldset>

            </x-box>

        </x-form>

    </x-container>

    @if ($snipeSettings->default_eula_text != '')
        {{-- EULA preview modal --}}
        <div class="modal fade" id="eulaModal" tabindex="-1" role="dialog" aria-labelledby="eulaModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                        <h2 class="modal-title" id="eulaModalLabel">{{ trans('admin/settings/general.default_eula_text') }}</h2>
                    </div>
                    <div class="modal-body">
                        {{ \App\Models\Setting::getDefaultEula() }}
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">{{ trans('button.cancel') }}</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

@stop

{{--
    UI-enhancement only. Show/enable the "Asset Group" field only when
    Type = Asset, and clear its value whenever Type is changed away from
    Asset. The actual enforcement (forcing ers_asset_group to null for
    every non-asset category_type, and requiring it for asset categories)
    happens server-side in
    App\Http\Controllers\CategoriesController::resolveErsAssetGroupInput()
    and in App\Models\Category's validation rules — this script never
    replaces that, it just keeps the form from showing/asking for a field
    that the server would ignore or reject anyway.
--}}
@section('moar_scripts')
    <script nonce="{{ csrf_token() }}">
        function ersToggleAssetGroupField(clearValue) {
            var categoryType = $('#category_type').val();
            var $row = $('#ers-asset-group-row');
            var $select = $('#ers_asset_group');

            if (categoryType === 'asset') {
                $row.show();
                $select.prop('disabled', false);
            } else {
                $row.hide();
                $select.prop('disabled', true);

                if (clearValue) {
                    $select.val('').trigger('change');
                }
            }
        }

        $(document).ready(function () {
            // Set initial state from the current value without clearing
            // it, so Edit pages for existing asset categories keep
            // showing their saved Hardware/Software selection.
            ersToggleAssetGroupField(false);

            $('#category_type').on('change', function () {
                ersToggleAssetGroupField(true);
            });
        });
    </script>
@stop
