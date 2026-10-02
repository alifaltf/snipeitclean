<?php

namespace App\Services\AssetImport;

use RuntimeException;

/** ERS Phase 6A: an uploaded file was refused; the message is user-facing. */
final class AssetImportFileException extends RuntimeException {}
