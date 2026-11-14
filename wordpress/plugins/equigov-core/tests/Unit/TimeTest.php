<?php

declare(strict_types=1);

namespace EquiGov\Core\Tests\Unit;

use EquiGov\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class TimeTest extends TestCase
{
    public function testGmtGetsExplicitOffset(): void
    {
        self::assertSame('2026-11-13T19:48:17+00:00', Time::gmtToIso('2026-11-13 19:48:17'));
    }

    public function testEmptyAndZeroDates(): void
    {
        self::assertNull(Time::gmtToIso(''));
        self::assertNull(Time::gmtToIso(null));
        self::assertNull(Time::gmtToIso('0000-00-00 00:00:00'));
    }
}
