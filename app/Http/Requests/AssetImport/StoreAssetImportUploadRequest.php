<?php

namespace App\Http\Requests\AssetImport;

use App\Services\AssetImport\AssetImportAuthorizer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ERS Phase 6A: upload of a CSV for the secure asset import. Only the file
 * envelope is checked here (present, .csv, text MIME type, size); the
 * content is checked by AssetImportCsvInspector.
 */
class StoreAssetImportUploadRequest extends FormRequest
{
    /** MIME types finfo reports for plain-text CSV files. */
    public const MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'text/x-csv',
        'text/comma-separated-values',
        'application/vnd.ms-excel',
    ];

    /** Validation errors go back to the upload page. */
    protected $redirectRoute = 'hardware.import.index';

    public function authorize(): bool
    {
        return app(AssetImportAuthorizer::class)->mayUse($this->user());
    }

    public function rules(): array
    {
        return [
            'csv_file' => [
                'required',
                'file',
                'extensions:csv',
                'mimetypes:'.implode(',', self::MIME_TYPES),
                'max:'.(int) config('asset_import.max_file_size_kb'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'csv_file.required' => trans('admin/hardware/import.upload.required'),
            'csv_file.file' => trans('admin/hardware/import.upload.required'),
            'csv_file.extensions' => trans('admin/hardware/import.upload.not_csv'),
            'csv_file.mimetypes' => trans('admin/hardware/import.upload.not_csv'),
            'csv_file.max' => trans('admin/hardware/import.upload.too_large', ['max' => (int) round(config('asset_import.max_file_size_kb') / 1024)]),
        ];
    }
}
