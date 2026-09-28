<?php

return [

    'deleted' => 'Deleted asset model',
    'does_not_exist' => 'Model does not exist.',
    'no_association' => 'WARNING! The asset model for this item is invalid or missing!',
    'no_association_fix' => 'This will break things in weird and horrible ways. Edit this asset now to assign it a model.',
    'assoc_users' => 'This model is currently associated with one or more assets and cannot be deleted. Please delete the assets, and then try deleting again. ',
    'invalid_category_type' => 'This category must be an asset category.',

    // ERS: an asset model may only use a live, final/assignable asset category.
    'category_rule' => [
        'invalid' => 'The selected category is invalid.',
        'missing' => 'The selected category does not exist.',
        'deleted' => 'The selected category has been deleted.',
        'not_asset' => 'This category must be an asset category.',
        'navigation' => 'The selected category is a navigation group. Asset models can only use final/assignable asset categories.',
    ],
    'import_navigation_category' => 'The category ":name" is a navigation group and cannot be assigned to asset models. Use a final/assignable category instead.',

    'create' => [
        'error' => 'Model was not created, please try again.',
        'success' => 'Model created successfully.',
        'duplicate_set' => 'An asset model with that name, manufacturer and model number already exists.',
    ],

    'update' => [
        'error' => 'Model was not updated, please try again',
        'success' => 'Model updated successfully.',
    ],

    'delete' => [
        'confirm' => 'Are you sure you wish to delete this asset model?',
        'error' => 'There was an issue deleting the model. Please try again.',
        'success' => 'The model was deleted successfully.',
    ],

    'restore' => [
        'error' => 'Model was not restored, please try again',
        'success' => 'Model restored successfully.',
    ],

    'bulkedit' => [
        'error' => 'No fields were changed, so nothing was updated.',
        'invalid_category' => 'No models were updated: :message',
        'success' => 'Model successfully updated. |:model_count models successfully updated.',
        'warn' => 'You are about to update the properties of the following model:|You are about to edit the properties of the following :model_count models:',

    ],

    'bulkdelete' => [
        'error' => 'No models were selected, so nothing was deleted.',
        'nothing_deletable' => 'None of the selected models can be deleted because they still have assets associated with them.',
        'success' => 'Model deleted!|:success_count models deleted!',
        'success_partial' => ':success_count model(s) were deleted, however :fail_count were unable to be deleted because they still have assets associated with them.',
    ],

];
