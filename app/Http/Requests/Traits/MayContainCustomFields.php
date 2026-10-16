<?php

namespace App\Http\Requests\Traits;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CustomField;
use App\Services\AssetFields\AssetCustomFieldEligibility;

trait MayContainCustomFields
{
    // this gets called automatically on a form request
    public function withValidator($validator)
    {

        // In case the model is being changed via form
        if (request()->has('model_id') != '') {

            $asset_model = AssetModel::find(request()->input('model_id'));

            // or if we have it available to route-model-binding
        } elseif (request()->route('asset') instanceof Asset && request()->route('asset')->model_id) {

            $asset_model = AssetModel::find(request()->route('asset')->model_id);

        } elseif ($this->method() == 'POST') {
            $asset_model = AssetModel::find($this->model_id);
        } else {
            // Bulk update / audit paths (no single {asset} in the URL) — the
            // model to validate against can't be pinned to one asset, so let
            // per-row saves surface any bad custom-field values at save time.
            $asset_model = null;
        }

        // collect the custom fields in the request
        $validator->after(function ($validator) use ($asset_model) {
            $request_fields = collect(AssetCustomFieldEligibility::submittedKeys($this->all()));

            if ($request_fields->isEmpty() || ! $validator->errors()->isEmpty()) {
                return;
            }

            // Bulk update path: no single target model to check membership
            // against, since different assets in the batch may carry
            // different fieldsets. We still flag truly nonexistent
            // custom-field columns (typo catch); per-model membership is
            // checked by the bulk controller against the selected assets
            // (ERS Phase 7C) before anything is written.
            if ($asset_model === null) {
                // Which submitted keys name a real custom field (one query).
                $known = array_flip(CustomField::query()->whereIn('db_column', $request_fields->all())->pluck('db_column')->all());
                $request_fields->each(function ($request_field_name) use ($validator, $known) {
                    if (! isset($known[$request_field_name])) {
                        $validator->errors()->add($request_field_name, trans('validation.custom.custom_field_not_found'));
                    }
                });

                return;
            }

            // Single-target-model path (ERS Phase 7C: the shared eligibility
            // rule): fields present in the request but not in the current
            // fieldset of the (destination) model are errors, and nothing
            // is written.
            foreach (app(AssetCustomFieldEligibility::class)->ineligibleKeyErrors($asset_model, $request_fields->all()) as $key => $message) {
                $validator->errors()->add($key, $message);
            }
        });
    }
}
