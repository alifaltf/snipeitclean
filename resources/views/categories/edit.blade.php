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
                            :options="$category_types"
                            :selected="old('category_type', $item->category_type)"
                            :disabled="$item->category_type != '' || $item->itemCount() > 0"
                            style="min-width:350px"
                            aria-label="category_type"
                        />
                    </x-slot:input>
                </x-form.row>

                {{-- ERS asset hierarchy. Super Users only, asset categories only.
                     Hiding these controls is a convenience; the server-side
                     hierarchy action and gate are what enforce the rules. --}}
                @can('categories.manage_hierarchy')
                    @if (! $item->exists || $item->category_type === 'asset')
                        <fieldset id="category-hierarchy-fields" name="category-hierarchy">
                            <x-form.legend :help_text="trans('admin/categories/general.hierarchy_help')">
                                {{ trans('admin/categories/general.hierarchy') }}
                            </x-form.legend>

                            <x-form.radio-row
                                name="is_assignable"
                                :label="trans('admin/categories/general.node_role')"
                                :options="[
                                    '0' => trans('admin/categories/general.node_role_navigation'),
                                    '1' => trans('admin/categories/general.node_role_final'),
                                ]"
                                :selected="$item->exists ? ($item->is_assignable === false ? '0' : '1') : '1'"
                                :required="false"
                                :help_text="trans('admin/categories/general.node_role_help')"
                            />

                            <x-form.row
                                :label="trans('admin/categories/general.parent_group')"
                                name="parent_id"
                                :help_text="trans('admin/categories/general.parent_group_help')"
                            >
                                <x-slot:input>
                                    <x-input.select
                                        name="parent_id"
                                        id="parent_id"
                                        :options="['' => trans('admin/categories/general.parent_group_none')] + ($hierarchy_parent_options ?? [])"
                                        :selected="old('parent_id', $item->parent_id)"
                                        style="min-width:350px"
                                        aria-label="parent_id"
                                    />
                                </x-slot:input>
                            </x-form.row>

                            <x-form.row
                                :label="trans('admin/categories/general.sort_order')"
                                :$item
                                name="sort_order"
                                type="number"
                                min="0"
                                max="{{ \App\Actions\Categories\SaveCategoryHierarchyAction::MAX_SORT_ORDER }}"
                                input_div_class="col-md-3"
                                :help_text="trans('admin/categories/general.sort_order_help')"
                            />
                        </fieldset>
                    @endif
                @endcan

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

@section('moar_scripts')
    @can('categories.manage_hierarchy')
        {{-- Convenience only: show the hierarchy controls for asset categories
             and disable them otherwise so they are not submitted. --}}
        <script nonce="{{ csrf_token() }}">
            $(function () {
                var $fields = $('#category-hierarchy-fields');
                if (!$fields.length) {
                    return;
                }
                var $type = $('select[name="category_type"]');

                function toggleHierarchyFields() {
                    var isAsset = $type.val() === 'asset';
                    $fields.toggle(isAsset);
                    $fields.find('input, select').prop('disabled', !isAsset);
                }

                $type.on('change', toggleHierarchyFields);
                toggleHierarchyFields();
            });
        </script>
    @endcan
@stop
