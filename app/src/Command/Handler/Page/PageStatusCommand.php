<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\Page;

use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Handler\Support\Lookup;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;
use Campanella\Lite\Model\PageStatus;

/**
 * "page publish" and "page unpublish": one handler per status.
 */
final class PageStatusCommand implements CommandHandler
{
    public function __construct(private readonly PageStatus $status)
    {
    }

    public static function publish(): self
    {
        return new self(PageStatus::Published);
    }

    public static function unpublish(): self
    {
        return new self(PageStatus::Draft);
    }

    public function signature(): Signature
    {
        $name = $this->status === PageStatus::Published ? 'page publish' : 'page unpublish';

        return new Signature(name: $name, arguments: ['page'], examples: [$name . ' 2', $name . ' /about']);
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $page = Lookup::page($workspace, $invocation->requiredArgument(0));
        $workspace->putPage($page->with(status: $this->status));

        return CommandResult::ok('ok.page_status', ['page' => $page->id, 'status' => $this->status->value]);
    }
}
