<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Tests\Integration;

use IndianOpinion\SrcsetTruth\Backfill\Backfill;
use IndianOpinion\SrcsetTruth\Encoding\EncoderFactory;
use IndianOpinion\SrcsetTruth\Files\SkipLedger;
use IndianOpinion\SrcsetTruth\Lifecycle;
use IndianOpinion\SrcsetTruth\Plugin;
use IndianOpinion\SrcsetTruth\Tests\FakeEncoder;
use IndianOpinion\SrcsetTruth\Tests\TestCase;

/**
 * PRD 4.7: acceptance tests 37 to 42.
 */
final class BackfillTest extends TestCase
{
    private FakeEncoder $fake;

    /** @var list<string> */
    private array $order = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeEncoder(function (string $s, string $d): void {
            $this->order[] = basename($s);
            file_put_contents($d, 'x');
        });
        EncoderFactory::override([$this->fake]);
    }

    public function testT37WalksFromNewestWithCursorAndWraps(): void
    {
        foreach ([3, 5, 9] as $id) {
            $this->attachment($id, 'p' . $id);
        }
        Backfill::setSource($this->source([3, 5, 9]));
        $clock = 0.0;
        Backfill::setClock(static function () use (&$clock): float {
            return $clock += 1.0;
        });
        $this->filter('srcset_truth_backfill_budget', static fn (): int => 5);
        $first = Backfill::run();
        self::assertSame('p9.jpg', $this->order[0], 'newest first');
        self::assertSame(9, $first['cursor']);
        self::assertSame(9, Backfill::state()['cursor']);

        Backfill::setClock(null);
        remove_all_filters('srcset_truth_backfill_budget');
        $second = Backfill::run();
        self::assertTrue($second['wrapped']);
        self::assertGreaterThan(0, Backfill::state()['last_full_pass']);
        self::assertCount(12, $this->order, 'every served file once: 3 attachments x 4 files');
        self::assertSame(3, $second['cursor']);
    }

    public function testT38StopsAtBudgetAndRespectsExecutionTimeCap(): void
    {
        $this->attachment(1, 'a');
        $this->attachment(2, 'b');
        Backfill::setSource($this->source([1, 2]));
        $clock = 0.0;
        Backfill::setClock(static function () use (&$clock): float {
            return $clock += 10.0;
        });
        $result = Backfill::run();
        // Budget 45 s, 10 s per tick: four encodes start, then it stops.
        self::assertSame(4, $result['done']);
        self::assertSame(2, $result['cursor']);

        $old = ini_get('max_execution_time');
        ini_set('max_execution_time', '30');
        self::assertSame(24.0, Backfill::budget());
        ini_set('max_execution_time', (string) $old);
        self::assertSame(45.0, Backfill::budget());
    }

    public function testT39HeldLockExitsAndExpiredLockIsBroken(): void
    {
        Backfill::setSource($this->source([]));
        add_option(Backfill::LOCK_OPTION, time() + 100, '', false);
        self::assertFalse(Backfill::run()['ran']);
        update_option(Backfill::LOCK_OPTION, time() - 5);
        self::assertTrue(Backfill::run()['ran']);
        self::assertFalse(get_option(Backfill::LOCK_OPTION, false), 'lock released');
    }

    public function testT40BackfillOffMeansNoCronButUploadStillConverts(): void
    {
        $this->settings(['backfill' => true]);
        Backfill::ensureScheduled();
        self::assertNotFalse(wp_next_scheduled(Backfill::HOOK));
        $this->settings(['backfill' => false]);
        Backfill::ensureScheduled();
        self::assertFalse(wp_next_scheduled(Backfill::HOOK));
        $this->attachment(4);
        Backfill::setSource($this->source([4]));
        Backfill::cron();
        self::assertSame(0, $this->fake->calls);
        Lifecycle::onGenerate(\WPStub::$attachmentMeta[4], 4);
        self::assertSame(4, $this->fake->calls);
    }

    public function testT41DeletingAnAttachmentDeletesItsSiblings(): void
    {
        $files = $this->attachment(7);
        Lifecycle::onGenerate(\WPStub::$attachmentMeta[7], 7);
        self::assertFileExists($files['large'] . '.webp');
        SkipLedger::record($files['thumbnail'], 'not-beneficial');
        Lifecycle::onDelete(7);
        foreach ($files as $file) {
            self::assertFileDoesNotExist($file . '.webp');
            self::assertFileExists($file, 'originals are core\'s to delete');
        }
        self::assertSame([], SkipLedger::counts());
    }

    public function testT42MediaEditorEditCreatesAndRemoves(): void
    {
        $files = $this->attachment(7);
        Lifecycle::onGenerate(\WPStub::$attachmentMeta[7], 7);
        // The editor writes new files and saves new metadata.
        $edited = $this->jpeg('2026/11/photo-e1700000000-1024x576.jpg', 90, 50);
        $meta = \WPStub::$attachmentMeta[7];
        $meta['sizes']['large']['file'] = 'photo-e1700000000-1024x576.jpg';
        Lifecycle::onUpdate($meta, 7);
        self::assertFileExists($edited . '.webp');
        // Core removes the old file through wp_delete_file.
        Lifecycle::onDeleteFile($files['large']);
        self::assertFileDoesNotExist($files['large'] . '.webp');
    }

    public function testT42RegeneratedThumbnailsRefreshThroughStaleness(): void
    {
        $files = $this->attachment(7);
        Lifecycle::onGenerate(\WPStub::$attachmentMeta[7], 7);
        $calls = $this->fake->calls;
        Lifecycle::onGenerate(\WPStub::$attachmentMeta[7], 7);
        self::assertSame($calls, $this->fake->calls, 'fresh siblings are not redone');
        $this->touchAt($files['medium'], time() + 50);
        Plugin::resetRequest();
        Lifecycle::onGenerate(\WPStub::$attachmentMeta[7], 7);
        self::assertSame($calls + 1, $this->fake->calls);
    }

    public function testDoneActionFires(): void
    {
        Backfill::setSource($this->source([]));
        $args = null;
        add_action('srcset_truth_backfill_done', static function (...$a) use (&$args): void {
            $args = $a;
        }, 10, 4);
        Backfill::run();
        self::assertIsArray($args);
        self::assertCount(4, $args);
    }

    public function testScheduleIsRegistered(): void
    {
        $schedules = Backfill::schedules([]);
        self::assertSame(300, $schedules[Backfill::SCHEDULE]['interval']);
    }
}
