<?php

declare(strict_types=1);

namespace Campanella\Lite\Tests\Storage;

use Campanella\Lite\Exception\InvalidDataException;
use Campanella\Lite\Storage\YamlCodec;
use PHPUnit\Framework\TestCase;

final class YamlCodecTest extends TestCase
{
    public function testDecodesAMapping(): void
    {
        $data = YamlCodec::decode("name: Example\nlanguages: [en, hu]\n", 'site.yaml');

        self::assertSame(['name' => 'Example', 'languages' => ['en', 'hu']], $data);
    }

    public function testAnEmptyDocumentIsAnEmptyMapping(): void
    {
        self::assertSame([], YamlCodec::decode('', 'empty.yaml'));
    }

    public function testRejectsBrokenYamlAndNamesTheFile(): void
    {
        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage('Invalid data in pages/1.yaml');

        YamlCodec::decode("title: [unclosed\n", 'pages/1.yaml');
    }

    public function testRejectsATopLevelList(): void
    {
        $this->expectException(InvalidDataException::class);

        YamlCodec::decode("- one\n- two\n", 'list.yaml');
    }

    public function testRejectsAScalarDocument(): void
    {
        $this->expectException(InvalidDataException::class);

        YamlCodec::decode('just text', 'scalar.yaml');
    }

    public function testRefusesToInstantiatePhpObjects(): void
    {
        $this->expectException(InvalidDataException::class);

        YamlCodec::decode("value: !php/object 'O:8:\"stdClass\":0:{}'\n", 'evil.yaml');
    }

    public function testRefusesPhpConstants(): void
    {
        $this->expectException(InvalidDataException::class);

        YamlCodec::decode("value: !php/const PHP_VERSION\n", 'evil.yaml');
    }

    public function testRoundTripsData(): void
    {
        $data = [
            'id' => 2,
            'title' => ['en' => 'About us', 'hu' => 'Rólunk'],
            'placements' => ['body.main' => ['intro', 'team']],
            'empty' => [],
            'text' => "Line one\nLine two",
        ];

        self::assertSame($data, YamlCodec::decode(YamlCodec::encode($data), 'roundtrip.yaml'));
    }

    public function testQuotesValuesThatWouldChangeType(): void
    {
        $data = ['title' => 'true', 'number' => '12', 'null' => 'null', 'colon' => 'a: b'];

        self::assertSame($data, YamlCodec::decode(YamlCodec::encode($data), 'types.yaml'));
    }
}
