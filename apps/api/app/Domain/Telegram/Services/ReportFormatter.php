<?php

namespace App\Domain\Telegram\Services;

/** Renders ReportService figures as the Telegram daily report (spec §24). Never includes photos. */
final class ReportFormatter
{
    public static function money(int $amount): string
    {
        return number_format($amount, 0, '.', ' ')." so'm";
    }

    public static function duration(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h === 0 ? "{$m} daq" : ($m === 0 ? "{$h} soat" : "{$h} soat {$m} daq");
    }

    /** @param array $branch one entry of ReportService::daily()['branches'] */
    public static function daily(string $date, array $branch): string
    {
        $lines = [
            __('notifications.daily_report_title', ['date' => $date]),
            '',
            __('notifications.branch', ['name' => $branch['branch']['name']]),
        ];
        if ($branch['sessions'] === 0) {
            $lines[] = __('notifications.no_sessions');

            return implode("\n", $lines);
        }
        $lines[] = __('notifications.sessions', ['count' => $branch['sessions']]);
        $lines[] = __('notifications.play_time', ['time' => self::duration($branch['minutes'])]);
        $lines[] = __('notifications.amount', ['amount' => self::money($branch['amount'])]);
        if ($branch['unpaidCount'] > 0) {
            $lines[] = __('notifications.unpaid', ['count' => $branch['unpaidCount'], 'amount' => self::money($branch['unpaidAmount'])]);
        }
        $lines[] = '';
        foreach ($branch['tables'] as $table) {
            if ($table['sessions'] > 0) {
                $lines[] = __('notifications.table_line', ['table' => $table['name'], 'time' => self::duration($table['minutes']), 'amount' => self::money($table['amount'])]);
            }
        }

        return implode("\n", $lines);
    }
}
