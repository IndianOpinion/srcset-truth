<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Markup;

/**
 * Reads the attributes of the tag a processor is paused on.
 */
final class Attributes
{
    /**
     * @return array<string, string|true>
     */
    public static function read(\WP_HTML_Tag_Processor $p): array
    {
        $names = $p->get_attribute_names_with_prefix('');
        $attrs = [];
        foreach (is_array($names) ? $names : [] as $name) {
            $value = $p->get_attribute($name);
            if ($value === null) {
                continue;
            }
            $attrs[strtolower((string) $name)] = is_string($value) ? $value : true;
        }

        return $attrs;
    }

    public static function string(\WP_HTML_Tag_Processor $p, string $name): ?string
    {
        $value = $p->get_attribute($name);
        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : '';
    }

    /**
     * A tag rebuilt from attributes, for filters that want tag HTML.
     *
     * @param array<string, string|true> $attrs
     */
    public static function toTag(string $tag, array $attrs): string
    {
        $html = '<' . $tag;
        foreach ($attrs as $name => $value) {
            if (preg_match('/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', (string) $name) !== 1) {
                continue;
            }
            $html .= $value === true ? ' ' . $name : sprintf(' %s="%s"', $name, esc_attr($value));
        }

        return $html . '>';
    }

    /**
     * @return list<string>
     */
    public static function classes(?string $class): array
    {
        if ($class === null || trim($class) === '') {
            return [];
        }
        $list = preg_split('/\s+/', trim($class), -1, PREG_SPLIT_NO_EMPTY);

        return $list === false ? [] : $list;
    }
}
