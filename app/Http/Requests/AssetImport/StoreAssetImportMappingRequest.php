<?php

namespace App\Http\Requests\AssetImport;

use App\Services\AssetImport\AssetImportAuthorizer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ERS Phase 6A: the column mapping form. Destinations are checked by
 * AssetImportMappingValidator against the server-side field catalog.
 */
class StoreAssetImportMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(AssetImportAuthorizer::class)->mayUse($this->user());
    }

    public function rules(): array
    {
        return [];
    }
}
