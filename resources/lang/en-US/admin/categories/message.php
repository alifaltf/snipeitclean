<?php

return [

    'does_not_exist' => 'Category does not exist.',
    'assoc_models' => 'This category is currently associated with at least one model and cannot be deleted. Please update your models to no longer reference this category and try again. ',
    'assoc_items' => 'This category is currently associated with at least one :asset_type and cannot be deleted. Please update your :asset_type  to no longer reference this category and try again. ',

    'create' => [
        'error' => 'Category was not created, please try again.',
        'success' => 'Category created successfully.',
    ],

    'update' => [
        'error' => 'Category was not updated, please try again',
        'success' => 'Category updated successfully.',
        'cannot_change_category_type' => 'You cannot change the category type once it has been created',
        'cannot_change_category_type_hierarchy' => 'The category type cannot be changed while this category is part of the asset hierarchy (it has a parent, has child categories or is a navigation group).',
        'cannot_change_category_type_in_use' => 'The category type cannot be changed because models or items still reference this category.',
    ],

    'hierarchy' => [
        'asset_only' => 'Hierarchy settings are only available for asset categories.',
        'invalid_parent' => 'The selected parent navigation group is invalid.',
        'parent_not_found' => 'The selected parent navigation group does not exist or has been deleted.',
        'parent_not_asset' => 'The parent must be an asset category.',
        'parent_not_navigation' => 'The parent must be a navigation group. Final categories cannot contain other categories.',
        'parent_is_self' => 'A category cannot be its own parent.',
        'parent_is_descendant' => 'A category cannot be moved under one of its own descendants.',
        'max_depth' => 'This change would make the hierarchy deeper than the maximum of :max levels.',
        'invalid_is_assignable' => 'The node role is invalid.',
        'invalid_sort_order' => 'The sort order must be a whole number between 0 and :max.',
        'has_models' => 'This category cannot become a navigation group because asset models (including deleted models) still use it. Move those models to another category first.',
        'has_children' => 'This navigation group cannot become a final category while it contains child categories. Move or delete the child categories first.',
    ],

    'delete' => [
        'confirm' => 'Are you sure you wish to delete this category?',
        'error' => 'There was an issue deleting the category. Please try again.',
        'has_child_categories' => 'This category contains child categories and cannot be deleted. Move or delete the child categories first.',
        'has_child_categories_named' => 'The category ":item_name" contains child categories and cannot be deleted. Move or delete the child categories first.',
        'success' => 'Category was deleted successfully.',
        'bulk_success' => 'Category deleted successfully.|:count categories were deleted successfully.',
        'partial_success' => 'Category deleted successfully. See additional information below. | :count categories were deleted successfully. See additional information below.',
    ],

];
