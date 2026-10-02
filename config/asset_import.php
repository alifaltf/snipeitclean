<?php

/*
|--------------------------------------------------------------------------
| ERS secure asset CSV import (Phase 6)
|--------------------------------------------------------------------------
|
| Limits and retention for the /hardware/import workflow. These are the
| approved ERS values; change them here rather than in code.
|
*/

return [

    // Hours an import session stays usable after upload. Sessions that are
    // not completed within this window become unavailable.
    'session_lifetime_hours' => 24,

    // Largest accepted upload, in kilobytes (10 MB).
    'max_file_size_kb' => 10240,

    // Most data rows (excluding the header row) accepted in one file.
    'max_data_rows' => 5000,

    // Most columns accepted in the header row.
    'max_columns' => 200,

    // Longest accepted header cell, in characters.
    'max_header_length' => 255,

    // Sample values shown per column on the mapping page: how many data
    // rows, and the most characters shown per value.
    'sample_rows' => 3,
    'sample_value_length' => 60,

    // Filesystem disk for stored uploads. Null means the application's
    // default (private) disk, the same one used for other private uploads.
    'disk' => null,

    // Directory on that disk. Files get random server-side names.
    'directory' => 'private_uploads/asset-imports',

];
