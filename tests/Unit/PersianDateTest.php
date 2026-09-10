<?php

namespace Tests\Unit;

use App\Support\PersianDate;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class PersianDateTest extends TestCase
{
    public function test_it_formats_dates_in_tehran_persian_calendar(): void
    {
        $date = new DateTimeImmutable('2026-03-21 00:00:00', new DateTimeZone('UTC'));

        $this->assertSame('۱۴۰۵/۰۱/۰۱ ۰۳:۳۰', PersianDate::format($date));
    }
}
