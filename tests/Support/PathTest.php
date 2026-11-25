<?php

namespace KdrDev\Dissect\Tests\Support;

use KdrDev\Dissect\Support\Path;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PathTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function paths(): array
    {
        return [
            'unix' => ['/srv/app/routes/api.php', true],
            'windows' => ['C:\\Users\\dev\\app\\routes\\api.php', true],
            'windows, forward slashes' => ['c:/Users/dev/app', true],
            'a network share' => ['\\\\server\\share\\app', true],
            'relative' => ['app/Models', false],
            'dotted' => ['.dissect/snapshot.json', false],
            // Relative to drive C's working directory, not absolute.
            'a drive with no separator' => ['C:app', false],
            'nothing' => ['', false],
        ];
    }

    #[Test]
    #[DataProvider('paths')]
    public function it_knows_an_absolute_path_on_any_platform(string $path, bool $absolute): void
    {
        $this->assertSame($absolute, Path::isAbsolute($path));
    }
}
