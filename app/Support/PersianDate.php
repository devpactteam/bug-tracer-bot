<?php

namespace App\Support;

use DateTimeInterface;
use IntlDateFormatter;

final class PersianDate
{
    public static function format(DateTimeInterface|string|null $value, string $pattern = 'yyyy/MM/dd HH:mm'): string
    {
        if ($value === null) {
            return '—';
        }

        $formatter = new IntlDateFormatter(
            'fa_IR@calendar=persian',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            'Asia/Tehran',
            IntlDateFormatter::TRADITIONAL,
            $pattern,
        );

        return $formatter->format($value) ?: '—';
    }
}
