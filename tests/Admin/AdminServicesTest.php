<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Admin;

use Campanella\Lite\Admin\History;
use Campanella\Lite\Admin\LoginThrottle;
use Campanella\Lite\Admin\User;
use Campanella\Lite\Admin\UserRepository;
use Campanella\Lite\Cli\CliApplication;
use Campanella\Lite\Http\Request;
use Campanella\Lite\Storage\FileStore;
use Campanella\Lite\Tests\Support\TemporaryDirectory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AdminServicesTest extends TestCase
{
    use TemporaryDirectory;

    public function testStoresUsersWithHashedPasswords(): void
    {
        $users = new UserRepository(new FileStore($this->temporaryDirectory()));
        self::assertFalse($users->exists());

        $users->save(User::create('admin', 'secret password', 'en'));
        $stored = (string) file_get_contents($this->temporaryDirectory() . '/users.yaml');

        self::assertStringNotContainsString('secret password', $stored);
        $admin = $users->find('admin');
        self::assertNotNull($admin);
        self::assertTrue($admin->verify('secret password'));
        self::assertFalse($admin->verify('wrong'));
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->temporaryDirectory() . '/users.yaml')), -4));

        $users->save($admin->withLocale('hu'));
        self::assertSame('hu', $users->find('admin')?->locale);
        self::assertTrue($users->exists());
    }

    public function testRejectsInvalidUserNames(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new UserRepository(new FileStore($this->temporaryDirectory())))->save(User::create('../root', 'secret password'));
    }

    public function testThrottleCountsFailuresPerAddressWithinTheWindow(): void
    {
        $throttle = new LoginThrottle(new FileStore($this->temporaryDirectory()));
        $now = 1_000_000;

        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            self::assertSame(0, $throttle->retryAfter('a', $now + $i));
            $throttle->recordFailure('a', $now + $i);
        }

        self::assertSame(LoginThrottle::WINDOW - 4, $throttle->retryAfter('a', $now + 4));
        self::assertSame(0, $throttle->retryAfter('b', $now));
        self::assertSame(0, $throttle->retryAfter('a', $now + LoginThrottle::WINDOW + 1));

        $throttle->reset('a', $now + 5);
        self::assertSame(0, $throttle->retryAfter('a', $now + 5));
        self::assertStringNotContainsString('"a"', (string) file_get_contents($this->temporaryDirectory() . '/' . LoginThrottle::FILE));
    }

    public function testHistoryKeepsRecentUniqueBatchesNewestFirst(): void
    {
        $history = new History(new FileStore($this->temporaryDirectory()));

        $history->add('admin', 'get a');
        $history->add('admin', 'get b');
        $history->add('admin', "get a\n");
        $history->add('admin', '   ');

        self::assertSame(['get a', 'get b'], $history->list('admin'));
        self::assertSame([], $history->list('other'));

        for ($i = 0; $i < History::LIMIT + 5; $i++) {
            $history->add('admin', 'get ' . $i);
        }

        self::assertCount(History::LIMIT, $history->list('admin'));
    }

    public function testRequestDecodesJsonObjectsOnly(): void
    {
        self::assertSame(['commands' => 'x'], (new Request('POST', '/', body: '{"commands":"x"}'))->json());
        self::assertSame([], (new Request('POST', '/', body: '["x"]'))->json());
        self::assertSame([], (new Request('POST', '/', body: 'not json'))->json());
        self::assertSame('abc', (new Request('GET', '/', headers: ['x-csrf-token' => 'abc']))->header('X-CSRF-Token'));
    }

    public function testCliCreatesAndUpdatesUsers(): void
    {
        $root = $this->temporaryDirectory();

        $users = new UserRepository(new FileStore($root . '/config'));

        self::assertSame(CliApplication::EXIT_OK, $this->cli($root, ['--add-user=admin'], "first password\n"));
        self::assertTrue($users->find('admin')?->verify('first password'));

        self::assertSame(CliApplication::EXIT_OK, $this->cli($root, ['--add-user=admin'], "second password\n"));
        $reloaded = new UserRepository(new FileStore($root . '/config'));
        self::assertTrue($reloaded->find('admin')?->verify('second password'));

        self::assertSame(CliApplication::EXIT_USAGE, $this->cli($root, ['--add-user=bob'], "short\n"));
        self::assertSame(CliApplication::EXIT_USAGE, $this->cli($root, ['--add-user=Bad Name'], "long enough password\n"));
    }

    /**
     * @param list<string> $args
     */
    private function cli(string $root, array $args, string $stdin): int
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        fwrite($in, $stdin);
        rewind($in);

        return (new CliApplication($root, $in, $out, $err))->run(['campanella', ...$args]);
    }
}
