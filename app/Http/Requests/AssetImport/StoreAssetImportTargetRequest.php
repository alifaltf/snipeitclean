<?php

namespace App\Http\Requests\AssetImport;

use App\Services\AssetImport\AssetImportAuthorizer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ERS Phase 6A: the target configuration form. Ids and sources are
 * resolved and checked by AssetImportTargetCheck, which gives one generic
 * message for anything that is not an authorised choice.
 */
class StoreAssetImportTargetRequest extends FormRequest
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
