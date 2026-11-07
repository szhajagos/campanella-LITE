<?php

declare(strict_types=1);

namespace Campanella\Lite\Command\Handler;

use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandRegistry;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Output;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\Workspace;
use Campanella\Lite\I18n\Message;

/**
 * Lists the commands, or describes one. The summaries come from the message
 * catalogue ("help.{command}"), so help is translated like everything else.
 */
final class HelpCommand implements CommandHandler
{
    public function __construct(private readonly CommandRegistry $registry)
    {
    }

    public function signature(): Signature
    {
        return new Signature(
            name: 'help',
            arguments: ['command...'],
            examples: ['help', 'help set', 'help list'],
            readOnly: true,
        );
    }

    public function execute(Invocation $invocation, Workspace $workspace): CommandResult
    {
        $words = $invocation->rest(0);

        if ($words === []) {
            $rows = [];

            foreach ($this->registry->all() as $handler) {
                $signature = $handler->signature();
                $rows[] = [$signature->usage(), new Message($signature->helpKey())];
            }

            return CommandResult::ok('ok.help_index', ['count' => count($rows)], Output::table(['usage', 'summary'], $rows));
        }

        $name = implode(' ', $words);
        $handler = $this->registry->find($name)
            ?? throw CommandFailed::because('command.unknown', ['command' => $name]);
        $signature = $handler->signature();

        $lines = [new Message($signature->helpKey()), new Message('help.usage', ['usage' => $signature->usage()])];

        if ($signature->examples !== []) {
            $lines[] = new Message('help.examples');

            foreach ($signature->examples as $example) {
                $lines[] = '  ' . $example;
            }
        }

        return CommandResult::ok('ok.help_command', ['command' => $name], Output::lines($lines));
    }
}
