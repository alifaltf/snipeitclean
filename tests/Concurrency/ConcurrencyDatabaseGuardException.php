<?php

namespace Tests\Concurrency;

use RuntimeException;

/** ERS Phase 6C: the concurrency tests refused to touch a database. */
final class ConcurrencyDatabaseGuardException extends RuntimeException {}
