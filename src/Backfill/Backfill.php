<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Backfill;

use IndianOpinion\SrcsetTruth\Encoding\SiblingCreator;
use IndianOpinion\SrcsetTruth\Files\AttachmentFiles;
use IndianOpinion\SrcsetTruth\Logger;
use IndianOpinion\SrcsetTruth\Settings;

/**
 * The background backfill (PRD 4.7, lessons 3 and 4): walks the whole
 * library from the newest ID down with a stored cursor, one run at a time,
 * inside a time budget.
 */
final class Backfill
{
    public const HOOK = 'srcset_truth_backfill';

    public const SCHEDULE = 'srcset_truth_every_5_minutes';

    public const STATE_OPTION = 'srcset_truth_backfill_state';

    public const LOCK_OPTION = 'srcset_truth_backfill_lock';

    private const BATCH = 20;

    private static ?AttachmentSource $source = null;

    /** @var (callable(): float)|null */
    private static $clock = null;

    public static function setSource(?AttachmentSource $source): void
    {
        self::$source = $source;
    }

    /**
     * @param (callable(): float)|null $clock
     */
    public static function setClock(?callable $clock): void
    {
        self::$clock = $clock;
    }

    public static function source(): AttachmentSource
    {
        return self::$source ?? new WpdbAttachmentSource();
    }

    private static function now(): float
    {
        return self::$clock !== null ? (float) (self::$clock)() : microtime(true);
    }

    public static function enabled(): bool
    {
        return (bool) apply_filters('srcset_truth_backfill_enabled', Settings::bool('backfill')) && SiblingCreator::enabled();
    }

    public static function interval(): int
    {
        $interval = apply_filters('srcset_truth_backfill_interval', 300);

        return is_int($interval) && $interval >= 60 ? $interval : 300;
    }

    /**
     * @param mixed $schedules
     * @return mixed
     */
    public static function schedules($schedules)
    {
        if (!is_array($schedules)) {
            return $schedules;
        }
        $schedules[self::SCHEDULE] = [
            'interval' => self::interval(),
            'display' => __('Every 5 minutes (srcset-truth backfill)', 'srcset-truth'),
        ];

        return $schedules;
    }

    /**
     * Keep the cron event in line with the setting.
     */
    public static function ensureScheduled(): void
    {
        $next = wp_next_scheduled(self::HOOK);
        if (!self::enabled()) {
            if ($next !== false) {
                wp_clear_scheduled_hook(self::HOOK);
            }

            return;
        }
        if ($next === false) {
            wp_schedule_event(time() + 60, self::SCHEDULE, self::HOOK);
        }
    }

    public static function cron(): void
    {
        if (!self::enabled()) {
            return;
        }
        self::run();
    }

    /**
     * Seconds this run may spend starting encodes: the filtered budget,
     * never more than 80% of max_execution_time when that is set.
     */
    public static function budget(): float
    {
        $budget = apply_filters('srcset_truth_backfill_budget', 45);
        $budget = is_numeric($budget) && $budget > 0 ? (float) $budget : 45.0;
        $max = (int) ini_get('max_execution_time');
        if ($max > 0) {
            $budget = min($budget, $max * 0.8);
        }

        return $budget;
    }

    /**
     * @return array{ran: bool, done: int, failed: int, seconds: float, cursor: ?int, wrapped: bool}
     */
    public static function run(): array
    {
        $budget = self::budget();
        $lockUntil = (int) ceil(time() + $budget + 15);
        if (!self::lock($lockUntil)) {
            return ['ran' => false, 'done' => 0, 'failed' => 0, 'seconds' => 0.0, 'cursor' => null, 'wrapped' => false];
        }
        $start = self::now();
        $state = self::state();
        $cursor = $state['cursor'];
        $done = 0;
        $failed = 0;
        $wrapped = false;
        $source = self::source();
        try {
            while (true) {
                $ids = $source->idsBelow($cursor, self::BATCH);
                if ($ids === []) {
                    if ($cursor === null || $wrapped) {
                        break;
                    }
                    $cursor = null;
                    $wrapped = true;
                    $state['last_full_pass'] = time();
                    continue;
                }
                foreach ($ids as $id) {
                    foreach (AttachmentFiles::served($id) as $file) {
                        if (self::now() - $start >= $budget) {
                            break 3;
                        }
                        $result = SiblingCreator::create($file);
                        if ($result['status'] === 'created') {
                            $done++;
                        } elseif ($result['status'] === 'failed') {
                            $failed++;
                        }
                    }
                    $cursor = $id;
                }
            }
        } finally {
            $seconds = round(self::now() - $start, 3);
            $state['cursor'] = $cursor;
            $state['last_run'] = time();
            $state['last_done'] = $done;
            $state['last_failed'] = $failed;
            $state['last_seconds'] = $seconds;
            update_option(self::STATE_OPTION, $state, false);
            self::unlock();
        }
        Logger::log(sprintf('Backfill: %d created, %d failed, %.1fs, cursor %s.', $done, $failed, $seconds, $cursor === null ? 'top' : (string) $cursor));
        do_action('srcset_truth_backfill_done', $done, $failed, $seconds, $cursor);

        return ['ran' => true, 'done' => $done, 'failed' => $failed, 'seconds' => $seconds, 'cursor' => $cursor, 'wrapped' => $wrapped];
    }

    /**
     * @return array{cursor: ?int, last_run: int, last_full_pass: int, last_done: int, last_failed: int, last_seconds: float}
     */
    public static function state(): array
    {
        $stored = get_option(self::STATE_OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'cursor' => isset($stored['cursor']) && is_numeric($stored['cursor']) ? (int) $stored['cursor'] : null,
            'last_run' => (int) ($stored['last_run'] ?? 0),
            'last_full_pass' => (int) ($stored['last_full_pass'] ?? 0),
            'last_done' => (int) ($stored['last_done'] ?? 0),
            'last_failed' => (int) ($stored['last_failed'] ?? 0),
            'last_seconds' => (float) ($stored['last_seconds'] ?? 0),
        ];
    }

    /**
     * One run at a time. add_option fails when the row exists, which makes
     * it an atomic test-and-set. A lock past its expiry is broken.
     */
    public static function lock(int $until): bool
    {
        if (add_option(self::LOCK_OPTION, $until, '', false)) {
            return true;
        }
        $held = get_option(self::LOCK_OPTION, 0);
        if (is_numeric($held) && (int) $held >= time()) {
            return false;
        }
        Logger::log('Backfill lock had expired; broken.');
        delete_option(self::LOCK_OPTION);

        return add_option(self::LOCK_OPTION, $until, '', false);
    }

    public static function unlock(): void
    {
        delete_option(self::LOCK_OPTION);
    }

    public static function lockState(): string
    {
        $held = get_option(self::LOCK_OPTION, false);
        if ($held === false) {
            return 'free';
        }

        return is_numeric($held) && (int) $held >= time() ? 'held until ' . gmdate('Y-m-d H:i:s', (int) $held) . ' UTC' : 'expired';
    }
}
