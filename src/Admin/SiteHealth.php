<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Admin;

use IndianOpinion\SrcsetTruth\Backfill\Backfill;
use IndianOpinion\SrcsetTruth\Encoding\EncoderFactory;
use IndianOpinion\SrcsetTruth\Files\AttachmentFiles;
use IndianOpinion\SrcsetTruth\Files\Siblings;
use IndianOpinion\SrcsetTruth\Files\Uploads;
use IndianOpinion\SrcsetTruth\Http;

/**
 * Site Health checks (PRD 11, 72).
 */
final class SiteHealth
{
    private const SAMPLE = 100;

    /**
     * @param mixed $tests
     * @return mixed
     */
    public static function tests($tests)
    {
        if (!is_array($tests)) {
            return $tests;
        }
        $tests['direct']['srcset_truth_encoder'] = ['label' => __('WebP encoder', 'srcset-truth'), 'test' => [self::class, 'encoder']];
        $tests['direct']['srcset_truth_content_type'] = ['label' => __('WebP content type', 'srcset-truth'), 'test' => [self::class, 'contentType']];
        $tests['direct']['srcset_truth_backfill'] = ['label' => __('WebP backfill', 'srcset-truth'), 'test' => [self::class, 'backfill']];
        $tests['direct']['srcset_truth_coverage'] = ['label' => __('WebP coverage', 'srcset-truth'), 'test' => [self::class, 'coverage']];

        return $tests;
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(string $test, string $status, string $label, string $description): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'badge' => ['label' => __('Performance', 'srcset-truth'), 'color' => 'blue'],
            'description' => '<p>' . esc_html($description) . '</p>',
            'actions' => '',
            'test' => $test,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function encoder(): array
    {
        $name = EncoderFactory::describe();
        if ($name === 'none') {
            return self::result('srcset_truth_encoder', 'recommended', __('No WebP encoder is available', 'srcset-truth'), __('Neither Imagick with WebP nor GD with WebP was found, so no siblings are created. Sizes correction still works.', 'srcset-truth'));
        }

        return self::result('srcset_truth_encoder', 'good', __('A WebP encoder is available', 'srcset-truth'), sprintf(__('Encoder: %s.', 'srcset-truth'), $name));
    }

    /**
     * Requests one known sibling over HTTP and checks Content-Type.
     *
     * @return array<string, mixed>
     */
    public static function contentType(?string $siblingUrl = null): array
    {
        $siblingUrl = $siblingUrl ?? self::findSiblingUrl();
        if ($siblingUrl === null) {
            return self::result('srcset_truth_content_type', 'good', __('No WebP sibling to check yet', 'srcset-truth'), __('The check runs once a sibling exists.', 'srcset-truth'));
        }
        $response = wp_remote_head($siblingUrl, ['timeout' => 5, 'redirection' => 0]);
        if (is_wp_error($response)) {
            return self::result('srcset_truth_content_type', 'recommended', __('Could not request a WebP sibling', 'srcset-truth'), $response->get_error_message());
        }
        $type = strtolower(trim(explode(';', Http::header($response, 'content-type'))[0]));
        if ($type !== 'image/webp') {
            return self::result(
                'srcset_truth_content_type',
                'critical',
                __('WebP files are served with the wrong content type', 'srcset-truth'),
                sprintf(__('%1$s was served as "%2$s". Some browsers refuse to render WebP that is not sent as image/webp. Add webp to the web server MIME types.', 'srcset-truth'), $siblingUrl, $type === '' ? 'none' : $type)
            );
        }

        return self::result('srcset_truth_content_type', 'good', __('WebP files are served as image/webp', 'srcset-truth'), $siblingUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public static function backfill(): array
    {
        if (!Backfill::enabled()) {
            return self::result('srcset_truth_backfill', 'good', __('The WebP backfill is off', 'srcset-truth'), __('New uploads still get siblings at upload time.', 'srcset-truth'));
        }
        $state = Backfill::state();
        if ($state['last_run'] < time() - 3600) {
            return self::result('srcset_truth_backfill', 'recommended', __('The WebP backfill has not run in the last hour', 'srcset-truth'), __('Check that WP-Cron runs (or a real cron calls wp-cron.php).', 'srcset-truth'));
        }

        return self::result('srcset_truth_backfill', 'good', __('The WebP backfill ran in the last hour', 'srcset-truth'), sprintf(__('Last run: %d created, %d failed.', 'srcset-truth'), $state['last_done'], $state['last_failed']));
    }

    /**
     * @return array<string, mixed>
     */
    public static function coverage(): array
    {
        $total = 0;
        $fresh = 0;
        foreach (Backfill::source()->idsBelow(null, self::SAMPLE) as $id) {
            foreach (AttachmentFiles::served($id) as $file) {
                if (!is_file($file) || !Siblings::hasSourceExtension($file)) {
                    continue;
                }
                $total++;
                if (Siblings::state($file) === 'fresh') {
                    $fresh++;
                }
            }
        }
        $share = $total > 0 ? (int) round($fresh * 100 / $total) : 100;

        return self::result(
            'srcset_truth_coverage',
            $share >= 80 ? 'good' : 'recommended',
            sprintf(__('%d%% of recent originals have a fresh WebP sibling', 'srcset-truth'), $share),
            sprintf(__('%1$d of %2$d files in the newest %3$d image attachments. Files skipped as not beneficial count as missing.', 'srcset-truth'), $fresh, $total, self::SAMPLE)
        );
    }

    private static function findSiblingUrl(): ?string
    {
        $uploads = Uploads::dir();
        foreach (Backfill::source()->idsBelow(null, 20) as $id) {
            foreach (AttachmentFiles::served($id) as $file) {
                if (Siblings::state($file) === 'fresh') {
                    return $uploads['baseurl'] . '/' . str_replace('%2F', '/', rawurlencode(Uploads::relative(Siblings::path($file))));
                }
            }
        }

        return null;
    }
}
