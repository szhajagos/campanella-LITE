<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler;

use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Output;
use Campanella\Lite\Command\ProjectPaths;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;

final class GetCommand implements CommandHandler
{
    public function __construct(private readonly ProjectPaths $paths = new ProjectPaths())
    {
    }

    public function signature(): Signature
    {
        return new Signature(
            name: 'get',
            arguments: ['path'],
            examples: ['get site.name', 'get page.2', 'get page./about.title', 'get layout.default.body.main.width'],
            readOnly: true,
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $path = $invocation->requiredArgument(0);
        $value = $this->paths->get($workspace->project(), $path);

        if (is_array($value)) {
            return CommandResult::ok('ok.get_structure', ['path' => $path], Output::value($value));
        }

        if ($value === null) {
            return CommandResult::ok('ok.get_empty', ['path' => $path], Output::value(null));
        }

        return CommandResult::ok('ok.get_value', ['path' => $path, 'value' => self::display($value)], Output::value($value));
    }

    private static function display(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
