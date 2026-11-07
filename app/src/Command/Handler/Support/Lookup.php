<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler\Support;

use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Workspace;
use Campanella\Lite\Model\Column;
use Campanella\Lite\Model\Content;
use Campanella\Lite\Model\Layout;
use Campanella\Lite\Model\Menu;
use Campanella\Lite\Model\Page;
use Campanella\Lite\Model\Region;
use Campanella\Lite\Validation\ProjectValidator;

/**
 * Finds entities and checks command input, failing with a translatable message.
 */
final class Lookup
{
    public static function page(Workspace $workspace, string $reference): Page
    {
        return $workspace->project()->findPage($reference)
            ?? throw CommandFailed::because('page.not_found', ['page' => $reference]);
    }

    public static function layout(Workspace $workspace, string $name): Layout
    {
        return $workspace->project()->layout($name)
            ?? throw CommandFailed::because('layout.not_found', ['layout' => $name]);
    }

    public static function content(Workspace $workspace, string $name): Content
    {
        return $workspace->project()->content($name)
            ?? throw CommandFailed::because('content.not_found', ['content' => $name]);
    }

    public static function menu(Workspace $workspace, string $name): Menu
    {
        return $workspace->project()->menu($name)
            ?? throw CommandFailed::because('menu.not_found', ['menu' => $name]);
    }

    public static function region(Layout $layout, string $name): Region
    {
        return $layout->region($name)
            ?? throw CommandFailed::because('layout.region_not_found', ['layout' => $layout->name, 'region' => $name]);
    }

    /**
     * A column by its "region.column" path.
     */
    public static function column(Layout $layout, string $path): Column
    {
        return $layout->column($path)
            ?? throw CommandFailed::because('layout.column_not_found', ['layout' => $layout->name, 'column' => $path]);
    }

    /**
     * Checks a name for a new layout, region, column, content item or menu.
     */
    public static function newName(string $name): string
    {
        if (!ProjectValidator::isValidName($name)) {
            throw CommandFailed::because('value.invalid_name', ['name' => $name, 'max' => ProjectValidator::NAME_MAX_LENGTH]);
        }

        return $name;
    }

    /**
     * The language of a --lang option, or the default language without one. It must be enabled.
     */
    public static function language(Workspace $workspace, ?string $language): string
    {
        $site = $workspace->project()->site;
        $language ??= $site->defaultLanguage;

        if (!in_array($language, $site->languages, true)) {
            throw CommandFailed::because('language.not_enabled', ['language' => $language, 'languages' => implode(', ', $site->languages)]);
        }

        return $language;
    }

    public static function requiredOption(Invocation $invocation, string $option): string
    {
        $value = $invocation->option($option);

        if ($value === null || $value === '') {
            throw CommandFailed::because('command.missing_option', ['option' => '--' . $option, 'usage' => $invocation->signature->usage()]);
        }

        return $value;
    }

    /**
     * Converts a 1-based --at position into a 0-based insert index; without one, the end of the list.
     */
    public static function position(?string $at, int $count): int
    {
        if ($at === null) {
            return $count;
        }

        if (preg_match('/^[1-9][0-9]*$/', $at) !== 1 || (int) $at > $count + 1) {
            throw CommandFailed::because('value.invalid_position', ['position' => $at, 'max' => $count + 1]);
        }

        return (int) $at - 1;
    }

    public static function integer(string $value): int
    {
        if (preg_match('/^-?[0-9]{1,9}$/', $value) !== 1) {
            throw CommandFailed::because('value.not_a_number', ['value' => $value]);
        }

        return (int) $value;
    }

    /**
     * @param list<string> $choices
     */
    public static function choice(string $value, array $choices): string
    {
        if (!in_array($value, $choices, true)) {
            throw CommandFailed::because('value.invalid_choice', ['value' => $value, 'choices' => implode(', ', $choices)]);
        }

        return $value;
    }

    /**
     * Fails if both of two mutually exclusive options are given.
     */
    public static function exclusive(Invocation $invocation, string $first, string $second): void
    {
        if ($invocation->flag($first) && $invocation->flag($second)) {
            throw CommandFailed::because('command.conflicting_options', ['first' => '--' . $first, 'second' => '--' . $second]);
        }
    }

    /**
     * Fails if an entity is still referenced and the user did not say --force.
     *
     * @param list<string> $references
     */
    public static function unlessReferenced(Invocation $invocation, string $key, string $name, array $references): void
    {
        if ($references === [] || $invocation->flag('force')) {
            return;
        }

        $shown = array_slice($references, 0, 5);
        $more = count($references) - count($shown);

        throw CommandFailed::because($key, [
            'name' => $name,
            'references' => implode(', ', $shown) . ($more > 0 ? sprintf(' (+%d)', $more) : ''),
        ]);
    }

    /**
     * Inserts items into a list at a 0-based index.
     *
     * @template T
     *
     * @param list<T> $list
     * @param list<T> $items
     *
     * @return list<T>
     */
    public static function insert(array $list, int $index, array $items): array
    {
        array_splice($list, $index, 0, $items);

        return $list;
    }
}
