<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\Page;

use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Handler\Support\Lookup;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;
use Campanella\Lite\Model\LocalizedText;
use Campanella\Lite\Model\Page;
use Campanella\Lite\Model\PageStatus;

final class PageCreateCommand implements CommandHandler
{
    public function signature(): Signature
    {
        return new Signature(
            name: 'page create',
            options: ['title' => true, 'alias' => true, 'layout' => true, 'status' => true, 'description' => true, 'lang' => true],
            examples: [
                'page create --title="About us" --alias=/about',
                'page create --title="Impressum" --layout=narrow --status=published',
            ],
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $title = Lookup::requiredOption($invocation, 'title');
        $language = Lookup::language($workspace, $invocation->option('lang'));
        $layout = Lookup::layout($workspace, $invocation->option('layout') ?? $workspace->project()->site->defaultLayout);
        $status = PageStatus::from(Lookup::choice($invocation->option('status') ?? PageStatus::Draft->value, ['draft', 'published']));
        $alias = $invocation->option('alias');
        $description = $invocation->option('description');

        [$site, $id] = $workspace->project()->site->allocatePageId();
        $workspace->putSite($site);

        $page = new Page(
            id: $id,
            title: LocalizedText::of($language, $title),
            layout: $layout->name,
            status: $status,
            alias: $alias === null ? null : LocalizedText::of($language, $alias),
            description: $description === null ? null : LocalizedText::of($language, $description),
        );
        $workspace->putPage($page);

        return CommandResult::ok('ok.page_created', ['page' => $id, 'path' => $page->path($language)]);
    }
}
