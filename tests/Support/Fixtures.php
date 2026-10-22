<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Support;

use RuntimeException;

/**
 * @internal
 */
final readonly class Fixtures
{
    private function __construct() {}

    public static function read(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../fixtures/' . $name);

        return $contents === false ? throw new RuntimeException('Missing fixture ' . $name) : $contents;
    }
}
