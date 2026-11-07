<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Storage;

use Campanella\Lite\Storage\WriteLock;
use Campanella\Lite\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WriteLockTest extends TestCase
{
    use TemporaryDirectory;

    public function testRunsTheCallbackAndReturnsItsResult(): void
    {
        $lock = new WriteLock($this->temporaryDirectory() . '/lock/write.lock');

        self::assertSame(42, $lock->synchronized(static fn (): int => 42));
        self::assertFileExists($this->temporaryDirectory() . '/lock/write.lock');
    }

    public function testIsHeldOnlyDuringTheCallback(): void
    {
        $lock = new WriteLock($this->temporaryDirectory() . '/write.lock');

        self::assertFalse($lock->isHeld());
        self::assertTrue($lock->synchronized(static fn (): bool => $lock->isHeld()));
        self::assertFalse($lock->isHeld());
    }

    public function testAllowsNestedCallsWithoutDeadlock(): void
    {
        $file = $this->temporaryDirectory() . '/write.lock';
        $outer = new WriteLock($file);
        $inner = new WriteLock($file);

        $result = $outer->synchronized(static fn (): string => $inner->synchronized(static fn (): string => 'nested'));

        self::assertSame('nested', $result);
        self::assertFalse($outer->isHeld());
    }

    public function testReleasesTheLockWhenTheCallbackThrows(): void
    {
        $lock = new WriteLock($this->temporaryDirectory() . '/write.lock');

        try {
            $lock->synchronized(static function (): never {
                throw new RuntimeException('failed');
            });
        } catch (RuntimeException) {
        }

        self::assertFalse($lock->isHeld());
        self::assertSame('again', $lock->synchronized(static fn (): string => 'again'));
    }

    public function testBlocksAnotherProcessWhileHeld(): void
    {
        $file = $this->temporaryDirectory() . '/write.lock';
        $lock = new WriteLock($file);

        $otherProcessGotTheLock = $lock->synchronized(static function () use ($file): bool {
            $handle = fopen($file, 'c');
            self::assertNotFalse($handle);
            $acquired = flock($handle, LOCK_EX | LOCK_NB);
            fclose($handle);

            return $acquired;
        });

        self::assertFalse($otherProcessGotTheLock);
    }
}
