<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler;

use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Output;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;
use Campanella\Lite\Model\LocalizedText;
use Campanella\Lite\Model\Project;

final class ListCommand implements CommandHandler
{
    public const TYPES = ['pages', 'layouts', 'content', 'menus', 'languages'];

    public function signature(): Signature
    {
        return new Signature(
            name: 'list',
            arguments: ['type'],
            examples: ['list pages', 'list layouts', 'list content', 'list menus', 'list languages'],
            readOnly: true,
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $type = $invocation->requiredArgument(0);
        $project = $workspace->project();
        $language = $project->site->defaultLanguage;

        $output = match ($type) {
            'pages' => $this->pages($project, $language),
            'layouts' => $this->layouts($project, $language),
            'content' => $this->contents($project, $language),
            'menus' => $this->menus($project),
            'languages' => $this->languages($project),
            default => throw CommandFailed::because('list.unknown_type', ['type' => $type, 'types' => implode(', ', self::TYPES)]),
        };

        /** @var array{rows: list<mixed>} $table */
        $table = $output->value;

        return CommandResult::ok('ok.list', ['type' => $type, 'count' => count($table['rows'])], $output);
    }

    private function pages(Project $project, string $language): Output
    {
        $rows = [];

        foreach ($project->pages as $page) {
            $rows[] = [
                (string) $page->id,
                $page->path($language) . ($project->site->frontPage === $page->id ? ' (/)' : ''),
                $page->status->value,
                $page->layout,
                self::text($page->title, $language),
            ];
        }

        return Output::table(['id', 'path', 'status', 'layout', 'title'], $rows);
    }

    private function layouts(Project $project, string $language): Output
    {
        $rows = [];

        foreach ($project->layouts as $layout) {
            $rows[] = [$layout->name, self::text($layout->title, $language), implode(', ', $layout->columnPaths())];
        }

        return Output::table(['name', 'title', 'columns'], $rows);
    }

    private function contents(Project $project, string $language): Output
    {
        $rows = [];

        foreach ($project->contents as $content) {
            $translation = $content->translation($language) ?? (array_values($content->translations)[0] ?? null);
            $rows[] = [$content->name, implode(', ', $content->languages()), $translation === null ? '' : $translation->title];
        }

        return Output::table(['name', 'languages', 'title'], $rows);
    }

    private function menus(Project $project): Output
    {
        $rows = [];

        foreach ($project->menus as $menu) {
            $rows[] = [$menu->name, (string) count($menu->items)];
        }

        return Output::table(['name', 'items'], $rows);
    }

    private function languages(Project $project): Output
    {
        $rows = [];

        foreach ($project->site->languages as $language) {
            $rows[] = [$language, $language === $project->site->defaultLanguage ? 'yes' : ''];
        }

        return Output::table(['language', 'default'], $rows);
    }

    private static function text(LocalizedText $text, string $language): string
    {
        return $text->get($language) ?? '';
    }
}
