<?php

namespace App\Services\AssetImport;

use App\Models\AssetImportSession;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * ERS Phase 6A: resolves and re-checks an import target configuration.
 *
 * Used when the target form is saved and again on every later step, so a
 * revoked grant, a deleted or moved model, or a company/location the user
 * can no longer use is noticed before anything else happens. Every id that
 * is not a valid, authorised choice gets the same "not available" message.
 */
final class AssetImportTargetCheck
{
    private const SOURCES = [
        'model_source' => [AssetImportSession::SOURCE_FIXED, AssetImportSession::SOURCE_COLUMN],
        'status_source' => [AssetImportSession::SOURCE_FIXED, AssetImportSession::SOURCE_COLUMN],
        'company_source' => [AssetImportSession::SOURCE_NONE, AssetImportSession::SOURCE_FIXED, AssetImportSession::SOURCE_COLUMN],
        'location_source' => [AssetImportSession::SOURCE_NONE, AssetImportSession::SOURCE_FIXED, AssetImportSession::SOURCE_COLUMN],
    ];

    public function __construct(private readonly AssetImportAuthorizer $authorizer) {}

    /** @return list<string> */
    public static function sourcesFor(string $column): array
    {
        return self::SOURCES[$column] ?? [];
    }

    /**
     * Turn submitted values into session columns.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<string, string>} [columns, errors]
     */
    public function resolve(User $user, array $input): array
    {
        $unavailable = trans('admin/hardware/import.target.unavailable');

        $categoryId = AssetImportAuthorizer::positiveInt($input['category_id'] ?? null);
        if (! $this->authorizer->allowsCategory($user, $categoryId)) {
            return [[], ['category_id' => $unavailable]];
        }

        $target = ['category_id' => $categoryId];
        $errors = [];
        foreach (self::SOURCES as $column => $allowed) {
            $value = $input[$column] ?? null;
            if (! is_string($value) || ! in_array($value, $allowed, true)) {
                $errors[$column] = trans('admin/hardware/import.target.source_required');
                $value = null;
            }
            $target[$column] = $value;
        }

        $fixed = [
            'model_id' => ['model_source', fn ($id) => $this->authorizer->model($user, $categoryId, $id)],
            'status_id' => ['status_source', fn ($id) => $this->authorizer->status($id)],
            'company_id' => ['company_source', fn ($id) => $this->authorizer->company($id)],
            'location_id' => ['location_source', fn ($id) => $this->authorizer->location($id)],
        ];
        foreach ($fixed as $column => [$source, $find]) {
            $target[$column] = null;
            if ($target[$source] !== AssetImportSession::SOURCE_FIXED) {
                continue;
            }
            $record = $find($input[$column] ?? null);
            if ($record === null) {
                $errors[$column] = $unavailable;

                continue;
            }
            $target[$column] = (int) $record->getKey();
        }

        // Snipe-IT's own rule for company-scoped locations decides whether a
        // fixed location fits a fixed company.
        if ($target['company_id'] !== null && $target['location_id'] !== null
            && Validator::make($target, ['location_id' => 'fmcs_location'])->fails()) {
            $errors['location_id'] = trans('admin/hardware/import.target.location_company_mismatch');
        }

        return [$target, $errors];
    }

    /** Is the session's saved target still complete and allowed? */
    public function isValid(AssetImportSession $session, User $user): bool
    {
        if ($session->category_id === null) {
            return false;
        }

        $input = [];
        foreach (['category_id', 'model_source', 'model_id', 'status_source', 'status_id', 'company_source', 'company_id', 'location_source', 'location_id'] as $column) {
            $value = $session->{$column};
            $input[$column] = is_int($value) ? (string) $value : $value;
        }

        [$target, $errors] = $this->resolve($user, $input);

        return $errors === [] && $target['category_id'] === $session->category_id;
    }
}
