<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Tests;

use IndianOpinion\SrcsetTruth\Admin\Probe;
use IndianOpinion\SrcsetTruth\Backfill\AttachmentSource;
use IndianOpinion\SrcsetTruth\Backfill\Backfill;
use IndianOpinion\SrcsetTruth\Encoding\EncoderFactory;
use IndianOpinion\SrcsetTruth\Encoding\SiblingCreator;
use IndianOpinion\SrcsetTruth\Logger;
use IndianOpinion\SrcsetTruth\Plugin;
use IndianOpinion\SrcsetTruth\Settings;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected string $uploads = '';

    /** @var list<string> */
    protected array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        \WPStub::reset();
        \WP_CLI::reset();
        $this->uploads = sys_get_temp_dir() . '/srcset-truth-' . bin2hex(random_bytes(6));
        mkdir($this->uploads . '/2026/11', 0777, true);
        \WPStub::$basedir = $this->uploads;
        EncoderFactory::override(null);
        Backfill::setSource(null);
        Backfill::setClock(null);
        Probe::reset();
        SiblingCreator::$encodes = 0;
        $this->logged = [];
        Logger::setSink(function (string $line): void {
            $this->logged[] = $line;
        });
        Plugin::resetRequest();
        Plugin::resetBoot();
        // A front-end request after the main query, unless a test says otherwise.
        \WPStub::$actionsDone['wp'] = 1;
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->uploads);
        Logger::setSink(null);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function settings(array $values): void
    {
        update_option(Settings::OPTION, array_merge(Settings::defaults(), $values));
        Plugin::resetRequest();
    }

    protected function filter(string $hook, callable $callback, int $args = 1): void
    {
        add_filter($hook, $callback, 10, $args);
    }

    protected function on(string $conditional): void
    {
        \WPStub::$conditionals[$conditional] = true;
    }

    /**
     * A photo-like JPEG with noise, so WebP is clearly smaller.
     */
    protected function jpeg(string $relative, int $width = 64, int $height = 36, int $quality = 95): string
    {
        $path = $this->uploads . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $image = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; $x += 2) {
            for ($y = 0; $y < $height; $y += 2) {
                $colour = imagecolorallocate($image, ($x * 7 + $y) % 256, ($y * 5) % 256, ($x * $y) % 256);
                imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, (int) $colour);
            }
        }
        imagejpeg($image, $path, $quality);
        imagedestroy($image);

        return $path;
    }

    protected function png(string $relative, int $width = 40, int $height = 40, bool $alpha = true): string
    {
        $path = $this->uploads . '/' . $relative;
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, (int) $clear);
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height / 2; $y++) {
                imagesetpixel($image, $x, $y, (int) imagecolorallocatealpha($image, ($x * 9) % 256, ($y * 13) % 256, 90, $alpha ? 0 : 0));
            }
        }
        imagepng($image, $path, 0);
        imagedestroy($image);

        return $path;
    }

    /**
     * Writes a file with a given modification time.
     */
    protected function touchAt(string $path, int $time): void
    {
        touch($path, $time);
        clearstatcache(true, $path);
    }

    /**
     * Registers an attachment with three sizes plus the main file, all
     * real JPEGs under uploads/2026/11.
     *
     * @return array<string, string> size name => path
     */
    protected function attachment(int $id, string $stem = 'photo'): array
    {
        $files = [
            'main' => $this->jpeg("2026/11/{$stem}.jpg", 96, 54),
            'thumbnail' => $this->jpeg("2026/11/{$stem}-150x150.jpg", 30, 30),
            'medium' => $this->jpeg("2026/11/{$stem}-300x169.jpg", 60, 34),
            'medium_large' => $this->jpeg("2026/11/{$stem}-768x432.jpg", 76, 43),
            'large' => $this->jpeg("2026/11/{$stem}-1024x576.jpg", 90, 50),
        ];
        \WPStub::$attachmentMeta[$id] = [
            'width' => 2048,
            'height' => 1152,
            'file' => "2026/11/{$stem}.jpg",
            'sizes' => [
                'thumbnail' => ['file' => "{$stem}-150x150.jpg", 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg'],
                'medium' => ['file' => "{$stem}-300x169.jpg", 'width' => 300, 'height' => 169, 'mime-type' => 'image/jpeg'],
                'medium_large' => ['file' => "{$stem}-768x432.jpg", 'width' => 768, 'height' => 432, 'mime-type' => 'image/jpeg'],
                'large' => ['file' => "{$stem}-1024x576.jpg", 'width' => 1024, 'height' => 576, 'mime-type' => 'image/jpeg'],
            ],
        ];

        return $files;
    }

    /**
     * A sibling written by hand, newer than its original.
     */
    protected function fakeSibling(string $original): string
    {
        $sibling = $original . '.webp';
        file_put_contents($sibling, 'RIFF0000WEBPVP8 ');
        $this->touchAt($sibling, (int) filemtime($original) + 10);

        return $sibling;
    }

    protected function url(string $relative): string
    {
        return \WPStub::$baseurl . '/' . $relative;
    }

    /**
     * The worked example from the PRD: a 1024 image in a card.
     */
    protected function cardTag(string $stem = 'photo', string $extra = ''): string
    {
        $base = \WPStub::$baseurl . '/2026/11/' . $stem;

        return sprintf(
            '<img src="%1$s-1024x576.jpg" srcset="%1$s-1024x576.jpg 1024w, %1$s-300x169.jpg 300w, %1$s-768x432.jpg 768w" sizes="auto, (max-width: 1024px) 100vw, 1024px" width="1024" height="576" loading="lazy" class="wp-image-7 size-large"%2$s>',
            $base,
            $extra
        );
    }

    /**
     * @param list<int> $ids
     */
    protected function source(array $ids): AttachmentSource
    {
        return new class ($ids) implements AttachmentSource {
            /** @param list<int> $ids */
            public function __construct(private array $ids)
            {
                rsort($this->ids);
            }

            public function idsBelow(?int $below, int $limit): array
            {
                $out = array_values(array_filter($this->ids, static fn (int $id): bool => $below === null || $id < $below));

                return array_slice($out, 0, $limit);
            }

            public function idsSince(string $date, int $limit, int $offset): array
            {
                return array_slice($this->ids, $offset, $limit);
            }
        };
    }

    protected function cardMap(): void
    {
        $this->filter('srcset_truth_width_map', static fn (): array => [
            'default' => [
                'card' => [[767, '92vw'], [1024, '46vw'], [null, '380px']],
                'hero' => '(max-width: 767px) 100vw, (max-width: 1200px) 90vw, 1200px',
            ],
        ]);
        $this->filter('srcset_truth_slot_rules', static fn (): array => [['slot' => 'card', 'size' => ['medium', 'medium_large', 'large']]]);
    }
}
