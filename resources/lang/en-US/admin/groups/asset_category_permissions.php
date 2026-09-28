<?php

return [
    'title' => 'Asset Category Permissions',
    'help' => 'Choose which final asset categories members of this group may view, create, edit and delete. Members get the combined permissions of all their groups, and these permissions never exceed the group\'s general Assets permissions above. Categories not ticked here are denied. Super Users always have full access. Navigation groups are not stored: their checkboxes tick or clear every final category beneath them.',
    'category' => 'Category',
    'navigation_group' => 'Navigation group',
    'no_categories' => 'There are no final asset categories yet.',
    'operation' => [
        'view' => 'View',
        'create' => 'Create',
        'update' => 'Edit',
        'delete' => 'Delete',
    ],
    'cell_label' => ':operation assets in :category',
    'bulk_label' => ':operation assets in every category under :category',
    'invalid' => 'The asset category permissions could not be saved because they contained an invalid category or value. Nothing was changed; reload the page and try again.',
];
