<?php

/*
 * ERS: database-enforced asset uniqueness (asset tag, serial when unique
 * serials are on, unique custom fields). Messages never contain values.
 */
return [
    'taken' => 'This :attribute is already used by another asset.',
    'custom_field' => 'custom field',
    'conflicts' => 'This uniqueness rule cannot be enforced while existing assets share values: :details. Resolve these duplicates first; nothing was changed automatically.',
    'conflict_count' => '{1} :field: :count value is used by more than one live asset|[2,*] :field: :count values are used by more than one live asset',
    'locked' => 'Another uniqueness change is in progress. Try again in a moment.',
    'schema_change_failed' => 'The database uniqueness rule could not be changed. Nothing was saved. Try again, or ask an administrator to run "php artisan ers:asset-uniqueness --repair".',
    'inside_transaction' => 'Database uniqueness rules cannot be changed inside a database transaction.',
    'foreign_object' => 'The database already contains an object for the :field rule that was not created by ERS. Nothing was changed; an administrator must review it.',
    'disable_incomplete' => 'The setting was saved, but the database still enforces the old uniqueness rule. Ask an administrator to run "php artisan ers:asset-uniqueness --repair".',
    'field_stale' => 'This custom field was changed by someone else after you opened it. Nothing was saved; reload it and try again.',
    'encrypted_unique' => 'A custom field cannot be both encrypted and unique: encrypted values cannot be compared, so uniqueness could not be enforced.',
    'encryption_refused_unique' => 'This custom field is unique; encrypted values cannot be checked for uniqueness. Turn "unique" off first.',
    'restore_refused' => 'The asset could not be restored.',
    'restore_failed' => 'Asset :tag could not be restored: :error',
    'restore_partial' => '{1} :count asset could not be restored.|[2,*] :count assets could not be restored.',
    'busy' => 'Another change was being saved at the same time, so the database stopped this request. Check what was saved, then try again.',
    'import_conflict' => 'This :attribute is already used by another asset.',
    'regenerate' => [
        'duplicates' => 'The new tags would not be unique (:count duplicate values). No tag was changed.',
        'failed' => 'Regenerating asset tags failed and every change was rolled back. No tag was changed.',
        'done' => 'Regenerated :count asset tags.',
    ],
];
