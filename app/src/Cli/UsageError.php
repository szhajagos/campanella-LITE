<?php

declare(strict_types=1);

namespace Campanella\Lite\Cli;

use RuntimeException;

/**
 * The command line was used wrongly: an unknown option, a missing file.
 */
final class UsageError extends RuntimeException
{
}
