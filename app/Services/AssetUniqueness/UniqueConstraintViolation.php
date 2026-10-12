<?php

namespace App\Services\AssetUniqueness;

use App\Models\CustomField;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PDOException;
use Throwable;

/**
 * ERS: recognises a duplicate-key error raised by one of the ERS live
 * uniqueness indexes and turns it into the field's validation error.
 *
 * Only SQLSTATE 23000 / MySQL error 1062 on an index named
 * ers_live_asset_tag_unique, ers_live_serial_unique or
 * ers_live_cf_<id>_unique is recognised; every other database error is
 * left alone. The index name is read from the end of the driver message
 * ("... for key 'name'"); the duplicate value in that message is never
 * used, shown or logged.
 */
final class UniqueConstraintViolation
{
    private function __construct(
        public readonly string $index,
        public readonly string $key,
    ) {}

    /** Any MySQL duplicate-key error (ERS index or not). */
    public static function isDuplicateKey(Throwable $exception): bool
    {
        return self::duplicateKeyInfo($exception) !== null;
    }

    public static function detect(Throwable $exception): ?self
    {
        $info = self::duplicateKeyInfo($exception);
        if ($info === null || ! is_string($info[2] ?? null)) {
            return null;
        }
        if (preg_match("/ for key '([^']+)'\\z/", $info[2], $match) !== 1) {
            return null;
        }
        // MySQL 8 reports "table.index", MariaDB just "index".
        $index = str_contains($match[1], '.') ? substr($match[1], strrpos($match[1], '.') + 1) : $match[1];
        $key = LiveUniqueKey::keyForIndex($index);

        return $key === null ? null : new self($index, $key);
    }

    /** The asset attribute the index protects (asset_tag, serial or the custom field's column). */
    public function attribute(): string
    {
        if ($this->key === LiveUniqueKey::TAG || $this->key === LiveUniqueKey::SERIAL) {
            return $this->key;
        }
        $column = CustomField::query()->whereKey($this->customFieldId())->value('db_column');

        return is_string($column) && $column !== '' ? $column : 'custom_field';
    }

    public function label(): string
    {
        return match ($this->key) {
            LiveUniqueKey::TAG => trans('general.asset_tag'),
            LiveUniqueKey::SERIAL => trans('general.serial_number'),
            default => (string) (CustomField::query()->whereKey($this->customFieldId())->value('name') ?? trans('admin/hardware/uniqueness.custom_field')),
        };
    }

    public function message(): string
    {
        return trans('admin/hardware/uniqueness.taken', ['attribute' => $this->label()]);
    }

    public function toValidationException(): ValidationException
    {
        return ValidationException::withMessages([$this->attribute() => [$this->message()]]);
    }

    public function customFieldId(): ?int
    {
        return str_starts_with($this->key, 'custom_field:') ? (int) substr($this->key, strlen('custom_field:')) : null;
    }

    /** @return array<int, mixed>|null the PDO errorInfo of a 1062 error in the exception chain */
    private static function duplicateKeyInfo(Throwable $exception): ?array
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $info = match (true) {
                $current instanceof QueryException => $current->errorInfo,
                $current instanceof PDOException => $current->errorInfo,
                default => null,
            };
            if (is_array($info) && ($info[0] ?? null) === '23000' && (int) ($info[1] ?? 0) === 1062) {
                return $info;
            }
        }

        return null;
    }
}
