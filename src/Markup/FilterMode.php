<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Markup;

use IndianOpinion\SrcsetTruth\Plugin;
use IndianOpinion\SrcsetTruth\Request;

/**
 * Filter mode (PRD 55a): content images, featured images and attachment
 * images through core filters. All three call ImageProcessor.
 */
final class FilterMode
{
    /**
     * wp_get_attachment_image_attributes.
     *
     * @param array<string, mixed> $attr
     * @param mixed $attachment
     * @param mixed $size
     * @return array<string, mixed>
     */
    public static function attachmentAttributes($attr, $attachment = null, $size = null): array
    {
        if (!is_array($attr) || !Request::isFrontHtml()) {
            return is_array($attr) ? $attr : [];
        }
        $id = is_object($attachment) && isset($attachment->ID) ? (int) $attachment->ID : 0;
        $width = 0;
        if ($id > 0) {
            $src = wp_get_attachment_image_src($id, is_string($size) || is_array($size) ? $size : 'thumbnail');
            if (is_array($src) && isset($src[1])) {
                $width = (int) $src[1];
            }
        }
        $strings = [];
        foreach ($attr as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value) || is_float($value))) {
                $strings[strtolower($name)] = (string) $value;
            } elseif (is_string($name) && $value === true) {
                $strings[strtolower($name)] = true;
            }
        }
        $p = new \WP_HTML_Tag_Processor(Attributes::toTag('img', $strings));
        if (!$p->next_tag(['tag_name' => 'img'])) {
            return $attr;
        }
        $outcome = ImageProcessor::process($p, [
            'attachment_id' => $id,
            'size' => $size,
            'image_width' => $width,
            'source' => 'attachment',
        ], ['no_wrap' => true]);
        foreach ($outcome['attrs'] as $name => $value) {
            if (!array_key_exists($name, $strings) || $strings[$name] !== $value) {
                $attr[$name] = $value;
            }
        }

        return $attr;
    }

    /**
     * wp_get_attachment_image: the finished tag of any attachment image a
     * theme prints. The attribute filter above cannot add elements, so with
     * <picture> wrapping on (the default) this is where those images are
     * wrapped. Featured images come through here first; the thumbnail
     * filter then finds them inside <picture> and leaves them.
     *
     * @param mixed $html
     * @param mixed $attachmentId
     * @param mixed $size
     * @return mixed
     */
    public static function attachmentImage($html, $attachmentId = 0, $size = null)
    {
        if (!is_string($html) || $html === '' || !Plugin::pictureWrap() || !Request::isFrontHtml()) {
            return $html;
        }
        $base = ['attachment_id' => (int) $attachmentId, 'source' => 'attachment'];
        if (is_string($size) || is_array($size)) {
            $base['size'] = $size;
            $src = (int) $attachmentId > 0 ? wp_get_attachment_image_src((int) $attachmentId, $size) : false;
            if (is_array($src) && isset($src[1])) {
                $base['image_width'] = (int) $src[1];
            }
        }

        return self::fragment($html, $base);
    }

    /**
     * wp_content_img_tag.
     *
     * @param mixed $html
     * @param mixed $context
     * @param mixed $attachmentId
     * @return mixed
     */
    public static function contentImage($html, $context = '', $attachmentId = 0)
    {
        if (!is_string($html) || !Request::isFrontHtml()) {
            return $html;
        }

        return self::fragment($html, ['attachment_id' => (int) $attachmentId, 'source' => 'content']);
    }

    /**
     * post_thumbnail_html.
     *
     * @param mixed $html
     * @param mixed $postId
     * @param mixed $thumbnailId
     * @param mixed $size
     * @return mixed
     */
    public static function thumbnail($html, $postId = 0, $thumbnailId = 0, $size = null)
    {
        if (!is_string($html) || $html === '' || !Request::isFrontHtml()) {
            return $html;
        }
        $base = ['attachment_id' => (int) $thumbnailId, 'source' => 'thumbnail'];
        if (is_string($size) || is_array($size)) {
            $base['size'] = $size;
            $src = (int) $thumbnailId > 0 ? wp_get_attachment_image_src((int) $thumbnailId, $size) : false;
            if (is_array($src) && isset($src[1])) {
                $base['image_width'] = (int) $src[1];
            }
        }

        return self::fragment($html, $base);
    }

    /**
     * Process every IMG in a fragment of HTML.
     *
     * @param array<string, mixed> $base
     */
    public static function fragment(string $html, array $base): string
    {
        if (stripos($html, '<img') === false) {
            return $html;
        }
        $p = new \WP_HTML_Tag_Processor($html);
        $pictureDepth = 0;
        $wraps = [];
        while ($p->next_tag(['tag_closers' => 'visit'])) {
            $tag = (string) $p->get_tag();
            if ($tag === 'PICTURE') {
                $pictureDepth = max(0, $pictureDepth + ($p->is_tag_closer() ? -1 : 1));
                continue;
            }
            if ($tag !== 'IMG' || $p->is_tag_closer()) {
                continue;
            }
            $id = Wrapper::nextId();
            $outcome = ImageProcessor::process($p, $base, ['inside_picture' => $pictureDepth > 0, 'wrap_id' => $id]);
            if ($outcome['wrap'] !== null) {
                $wraps[$id] = $outcome['wrap'];
            }
        }
        $out = $p->get_updated_html();

        return $wraps === [] ? $out : Wrapper::apply($out, $wraps);
    }
}
