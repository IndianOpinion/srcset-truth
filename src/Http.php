<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth;

/**
 * Small helpers over the WordPress HTTP API response arrays.
 */
final class Http
{
    /**
     * One header as a string. Repeated headers come back as a list: the
     * last one wins, as it would in a browser for Location or Content-Type.
     *
     * @param array<mixed>|\WP_Error $response
     */
    public static function header($response, string $name): string
    {
        $value = wp_remote_retrieve_header($response, $name);
        if (is_array($value)) {
            $value = end($value);
        }

        return is_string($value) ? trim($value) : '';
    }
}
