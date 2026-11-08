<?php

declare(strict_types=1);

namespace Campanella\Lite\Files;

/**
 * Turns a file name into the readable, ASCII-only part of its public address:
 * "Előadóterem 2026.jpg" becomes "eloadoterem-2026".
 */
final class Slug
{
    public const MAX_LENGTH = 60;

    /** Accented Latin letters and their plain forms. */
    private const LETTERS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ą' => 'a', 'ă' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'č' => 'c', 'ć' => 'c', 'ď' => 'd', 'đ' => 'd', 'ð' => 'd',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ě' => 'e', 'ę' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ľ' => 'l', 'ĺ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ň' => 'n', 'ń' => 'n',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ő' => 'o', 'ø' => 'o', 'œ' => 'oe',
        'ř' => 'r', 'ŕ' => 'r', 'š' => 's', 'ś' => 's', 'ș' => 's', 'ş' => 's', 'ß' => 'ss',
        'ť' => 't', 'ț' => 't', 'ţ' => 't', 'þ' => 'th',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ű' => 'u', 'ů' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
    ];

    public static function of(string $filename): string
    {
        $dot = strrpos($filename, '.');
        $name = $dot === false || $dot === 0 ? $filename : substr($filename, 0, $dot);
        $name = strtr(mb_strtolower($name, 'UTF-8'), self::LETTERS);
        $name = (string) preg_replace('/[^a-z0-9]+/', '-', $name);
        $name = trim(substr($name, 0, self::MAX_LENGTH), '-');

        return $name === '' ? 'file' : $name;
    }
}
