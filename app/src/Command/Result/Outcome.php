<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Result;

/**
 * What happened to a batch as a whole.
 */
enum Outcome: string
{
    /** Every command succeeded and the changes were written. */
    case Applied = 'applied';

    /** Every command succeeded; there was nothing to write. */
    case Unchanged = 'unchanged';

    /** Every command succeeded; the changes were only shown (@dry-run). */
    case DryRun = 'dry_run';

    /** A syntax error or a failing command; nothing was written. */
    case Rejected = 'rejected';

    /** The commands succeeded but the result breaks validation rules; nothing was written. */
    case Invalid = 'invalid';

    /** The project files could not be read; nothing was run. */
    case Failed = 'failed';

    public function isSuccess(): bool
    {
        return in_array($this, [self::Applied, self::Unchanged, self::DryRun], true);
    }
}
