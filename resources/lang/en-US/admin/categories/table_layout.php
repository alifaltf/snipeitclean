<?php

/*
 * ERS Phase 7A: asset-table column layouts of final asset categories.
 */
return [
    'title' => 'Asset Table Columns',
    'page_title' => 'Asset Table Columns: :category',
    'manage' => 'Configure asset table columns',
    'intro' => 'Choose which columns the asset table of this category shows, and in which order.',
    'help_hidden' => 'Hiding a column only removes it from this table. No asset data or custom-field values are changed or deleted.',
    'help_order' => 'Columns are shown in ascending order number. Equal numbers keep the order of this list.',
    'help_eligible' => 'Custom fields are offered when at least one asset model in this category uses them through its fieldset.',
    'help_encrypted' => 'Encrypted fields are marked. Their values will only be shown to users allowed to view encrypted custom fields.',
    'using_default' => 'This category uses the default layout. Saving stores an explicit layout for this category only.',
    'using_explicit' => 'This category has its own layout.',
    'current_layout' => 'Current layout',
    'column' => 'Column',
    'show' => 'Show',
    'show_column' => 'Show :column',
    'order' => 'Order',
    'order_column' => 'Order of :column',
    'type' => 'Type',
    'type_standard' => 'Standard field',
    'type_custom_field' => 'Custom field',
    'encrypted' => 'Encrypted',
    'save' => 'Save layout',
    'reset' => 'Restore default layout',
    'reset_help' => 'Removes this category\'s own layout. The category then uses the default layout again.',

    // ERS Phase 7B: category asset table and export.
    'export_csv' => 'Export CSV',
    'export_filename' => 'assets',

    'message' => [
        'saved' => 'The asset table layout was saved.',
        'reset' => 'The default asset table layout was restored.',
        'invalid' => 'The submitted layout is not valid. Reload the page and try again.',
        'empty' => 'Select at least one column.',
        'too_many' => 'A layout can have at most :max columns.',
        'unknown_standard' => 'The layout contains a field that cannot be shown as a column.',
        'duplicate' => 'A column was selected more than once.',
        'not_eligible' => 'A selected custom field is not used by any asset model in this category. Reload the page and try again.',
        'not_configurable' => 'Only final asset categories have an asset table layout.',
        'position_invalid' => 'Enter a whole number from 1 to :max.',
    ],
];
