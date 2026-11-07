<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Storage;

use Campanella\Lite\Exception\InvalidDataException;
use Campanella\Lite\Storage\FrontMatterCodec;
use PHPUnit\Framework\TestCase;

final class FrontMatterCodecTest extends TestCase
{
    public function testSplitsFrontMatterAndBody(): void
    {
        [$meta, $body] = FrontMatterCodec::decode("---\ntitle: Intro\nformat: markdown\n---\nHello **world**.\n", 'intro.md');

        self::assertSame(['title' => 'Intro', 'format' => 'markdown'], $meta);
        self::assertSame("Hello **world**.\n", $body);
    }

    public function testAcceptsWindowsLineEndingsAndAByteOrderMark(): void
    {
        [$meta, $body] = FrontMatterCodec::decode("\u{FEFF}---\r\ntitle: Intro\r\n---\r\nText\r\n", 'intro.md');

        self::assertSame(['title' => 'Intro'], $meta);
        self::assertSame("Text\n", $body);
    }

    public function testAcceptsAnEmptyFrontMatter(): void
    {
        self::assertSame([[], "Body\n"], FrontMatterCodec::decode("---\n---\nBody\n", 'empty.md'));
    }

    public function testAcceptsAMissingBody(): void
    {
        self::assertSame([['title' => 'Only meta'], ''], FrontMatterCodec::decode("---\ntitle: Only meta\n---", 'meta.md'));
    }

    public function testKeepsHorizontalRulesInTheBody(): void
    {
        $body = "Above\n---\nBelow\n";
        [, $decoded] = FrontMatterCodec::decode("---\ntitle: Rules\n---\n" . $body, 'rules.md');

        self::assertSame($body, $decoded);
    }

    public function testRejectsAFileWithoutFrontMatter(): void
    {
        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage('must start with a "---" line');

        FrontMatterCodec::decode("Just text\n", 'plain.md');
    }

    public function testRejectsAnUnclosedFrontMatter(): void
    {
        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage('not closed');

        FrontMatterCodec::decode("---\ntitle: Open\nBody\n", 'open.md');
    }

    public function testRoundTrips(): void
    {
        $meta = ['title' => 'Intro', 'format' => 'markdown'];
        $body = "Line one\n\n- item\n";

        self::assertSame([$meta, $body], FrontMatterCodec::decode(FrontMatterCodec::encode($meta, $body), 'intro.md'));
    }
}
