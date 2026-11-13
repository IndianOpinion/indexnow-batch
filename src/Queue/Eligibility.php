<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Queue;

use IndianOpinion\IndexNowBatch\Settings;

/**
 * Core WordPress rules for whether a post may be announced (rule 13).
 */
final class Eligibility
{
    private const NEVER = ['revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'user_request', 'custom_css'];

    /** @var list<string>|null */
    private ?array $types = null;

    public function __construct(private Settings $settings)
    {
    }

    /**
     * Allowed post types, after the `indexnow_batch_post_types` filter.
     *
     * @return list<string>
     */
    public function allowedTypes(): array
    {
        if ($this->types === null) {
            $types = apply_filters('indexnow_batch_post_types', $this->settings->postTypes());
            $types = is_array($types) ? array_values(array_filter($types, 'is_string')) : $this->settings->postTypes();
            if ($this->settings->includeAttachments() && !in_array('attachment', $types, true)) {
                $types[] = 'attachment';
            }
            if (!$this->settings->includeAttachments()) {
                $types = array_values(array_diff($types, ['attachment']));
            }
            $this->types = $types;
        }

        return $this->types;
    }

    /**
     * Returns null when the post may be announced, else the drop reason
     * (`type`, `status` or `password`).
     */
    public function check(\WP_Post $post): ?string
    {
        $type = (string) $post->post_type;
        if (in_array($type, self::NEVER, true) || wp_is_post_revision($post) !== false || wp_is_post_autosave($post) !== false) {
            return 'type';
        }
        if (!in_array($type, $this->allowedTypes(), true) || !is_post_type_viewable($type)) {
            return 'type';
        }
        if ($type === 'attachment') {
            if ($post->post_status !== 'inherit' && $post->post_status !== 'publish') {
                return 'status';
            }
            $parent = (int) $post->post_parent;
            if ($parent > 0) {
                $parentPost = get_post($parent);
                if (!$parentPost instanceof \WP_Post || $parentPost->post_status !== 'publish' || $parentPost->post_password !== '') {
                    return 'status';
                }
            }
        } elseif ($post->post_status !== 'publish') {
            return 'status';
        }
        if ((string) $post->post_password !== '') {
            return 'password';
        }

        return null;
    }
}
