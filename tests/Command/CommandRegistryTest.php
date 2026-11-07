<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Command;

use Campanella\Lite\Command\CommandFailed;
use Campanella\Lite\Command\CommandHandler;
use Campanella\Lite\Command\CommandRegistry;
use Campanella\Lite\Command\CommandResult;
use Campanella\Lite\Command\Invocation;
use Campanella\Lite\Command\Parser\BatchParser;
use Campanella\Lite\Command\Signature;
use Campanella\Lite\Command\TextInput;
use Campanella\Lite\Command\Workspace;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommandRegistryTest extends TestCase
{
    private CommandRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new CommandRegistry([
            self::handler(new Signature('layout region add', ['layout', 'region'], ['after' => true, 'fluid' => false])),
            self::handler(new Signature('layout create', ['name'])),
            self::handler(new Signature('content edit', ['name'], text: TextInput::Required)),
            self::handler(new Signature('help', ['command...'])),
            self::handler(new Signature('get', ['path', 'format?'])),
        ]);
    }

    public function testMatchesTheLongestCommandName(): void
    {
        [, $invocation] = $this->registry->resolve($this->parse('layout region add default hero --after=nav --fluid'));

        self::assertSame('layout region add', $invocation->signature->name);
        self::assertSame(['default', 'hero'], $invocation->arguments);
        self::assertSame('nav', $invocation->option('after'));
        self::assertTrue($invocation->flag('fluid'));
        self::assertNull($invocation->option('fluid'));
    }

    public function testAcceptsOptionalAndRestArguments(): void
    {
        [, $get] = $this->registry->resolve($this->parse('get site.name'));
        [, $help] = $this->registry->resolve($this->parse('help layout region add'));

        self::assertNull($get->argument(1));
        self::assertSame(['layout', 'region', 'add'], $help->rest(0));
    }

    public function testBuildsUsageFromTheSignature(): void
    {
        self::assertSame('layout region add <layout> <region> [--after=<after>] [--fluid]', $this->registry->find('layout region add')?->signature()->usage());
        self::assertSame('content edit <name> <<< text >>>', $this->registry->find('content edit')?->signature()->usage());
        self::assertSame('get <path> [<format>]', $this->registry->find('get')?->signature()->usage());
        self::assertSame('help [<command> ...]', $this->registry->find('help')?->signature()->usage());
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>}>
     */
    public static function invalidInvocations(): iterable
    {
        yield 'unknown command' => ['publish everything', 'command.unknown', ['command' => 'publish everything']];
        yield 'typo' => ['layuot create x', 'command.unknown_with_suggestion', ['suggestion' => 'layout create']];
        yield 'missing argument' => ['layout region add default', 'command.missing_argument', ['argument' => 'region']];
        yield 'too many arguments' => ['layout create a b', 'command.too_many_arguments', ['argument' => 'b']];
        yield 'unknown option' => ['layout create a --colour=red', 'command.unknown_option', ['option' => '--colour']];
        yield 'option without value' => ['layout region add a b --after', 'command.option_needs_value', ['option' => '--after']];
        yield 'flag with value' => ['layout region add a b --fluid=yes', 'command.option_takes_no_value', ['option' => '--fluid']];
        yield 'text not accepted' => ["layout create a <<<\nx\n>>>", 'command.text_not_accepted', []];
        yield 'text required' => ['content edit intro', 'command.text_required', []];
    }

    /**
     * @param array<string, string> $params
     */
    #[DataProvider('invalidInvocations')]
    public function testRejectsInvalidInvocations(string $input, string $key, array $params): void
    {
        try {
            $this->registry->resolve($this->parse($input));
            self::fail('Expected the invocation to be rejected.');
        } catch (CommandFailed $failure) {
            self::assertSame($key, $failure->reason->key);

            foreach ($params as $name => $value) {
                self::assertSame($value, $failure->reason->params[$name] ?? null);
            }
        }
    }

    public function testRejectsDuplicateRegistration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry->register(self::handler(new Signature('get', ['path'])));
    }

    private function parse(string $input): \Campanella\Lite\Command\Parser\ParsedCommand
    {
        $batch = (new BatchParser())->parse($input);
        self::assertTrue($batch->isValid());

        return $batch->commands[0];
    }

    private static function handler(Signature $signature): CommandHandler
    {
        return new class ($signature) implements CommandHandler {
            public function __construct(private readonly Signature $signature)
            {
            }

            public function signature(): Signature
            {
                return $this->signature;
            }

            public function execute(Invocation $invocation, Workspace $workspace): CommandResult
            {
                return CommandResult::ok('ok');
            }
        };
    }
}
