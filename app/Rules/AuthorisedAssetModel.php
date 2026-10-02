<?php

namespace App\Rules;

use App\Services\AssetCategoryAccess;
use App\Services\AssetCategoryWriteAuthorizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ERS Phase 5B2: model_id must be an asset model the current user may
 * create an asset with (CREATE) or move an asset to (UPDATE).
 *
 * Fails for a missing, soft-deleted or forged model, a model whose
 * category is missing, deleted, non-asset or navigation-only, and a model
 * in a category the user has no grant for, ALWAYS with the standard
 * "selected ... is invalid" message, so the answer never reveals whether a
 * hidden model exists. Decisions come from AssetCategoryWriteAuthorizer.
 *
 * For UPDATE, pass the asset's current model id: keeping the current model
 * is not a move, so it is not re-checked here (Edit on the current category
 * is enforced by AssetPolicy).
 */
final class AuthorisedAssetModel implements ValidationRule
{
    public function __construct(
        private readonly string $operation,
        private readonly ?int $currentModelId = null,
    ) {
        AssetCategoryAccess::assertOperation($operation);
    }

    public static function forCreate(): self
    {
        return new self(AssetCategoryAccess::CREATE);
    }

    public static function forUpdate(?int $currentModelId = null): self
    {
        return new self(AssetCategoryAccess::UPDATE, $currentModelId);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->currentModelId !== null && is_numeric($value) && (int) $value === $this->currentModelId) {
            return;
        }

        if (! app(AssetCategoryWriteAuthorizer::class)->allowsModel($this->operation, $value)) {
            $fail('validation.exists')->translate();
        }
    }
}
