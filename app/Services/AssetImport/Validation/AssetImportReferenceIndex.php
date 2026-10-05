<?php

namespace App\Services\AssetImport\Validation;

use Illuminate\Database\Eloquent\Model;

/**
 * ERS Phase 6B: exact, case-insensitive lookup of existing records by name.
 *
 * Built once per validation from an already-scoped query (so records the
 * user may not use are simply absent), then used for every row without
 * further queries. Names are compared after trimming, collapsing inner
 * whitespace and lower-casing; there is no fuzzy or partial matching. A
 * name shared by two records is ambiguous and never resolves.
 */
final class AssetImportReferenceIndex
{
    public const MISSING = 'missing';

    public const AMBIGUOUS = 'ambiguous';

    /** @var array<string, list<Model>> */
    private array $byKey = [];

    /**
     * @param  iterable<Model>  $records
     * @param  callable(Model): string  $key
     */
    public function __construct(iterable $records, callable $key)
    {
        foreach ($records as $record) {
            $this->byKey[$key($record)][] = $record;
        }
    }

    /** Name normalisation shared by every lookup and duplicate check. */
    public static function normalise(?string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '', 'UTF-8');
    }

    /**
     * The single record for $key, or MISSING / AMBIGUOUS.
     */
    public function find(string $key): Model|string
    {
        $matches = $this->byKey[$key] ?? [];

        return match (count($matches)) {
            0 => self::MISSING,
            1 => $matches[0],
            default => self::AMBIGUOUS,
        };
    }
}
