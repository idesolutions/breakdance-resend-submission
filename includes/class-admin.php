<?php

namespace BreakdanceResendSubmission;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Injects the resend UI into Breakdance's submission screens.
 */
class Admin
{
    const AJAX_ACTION = 'bdrs_resend';
    const ROW_ACTION = 'bdrs_resend_row';
    const BULK_ACTION = 'bdrs_resend';

    /** @var Resender */
    private $resender;

    public function __construct(Resender $resender)
    {
        $this->resender = $resender;
    }

    public function register(): void
    {
        $postType = Resender::postType();

        add_action('add_meta_boxes_' . $postType, [$this, 'addMetaBox'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handleAjax']);

        add_filter('post_row_actions', [$this, 'rowActions'], 20, 2);
        add_action('admin_post_' . self::ROW_ACTION, [$this, 'handleRowAction']);

        add_filter('bulk_actions-edit-' . $postType, [$this, 'bulkActions']);
        add_filter('handle_bulk_actions-edit-' . $postType, [$this, 'handleBulkAction'], 10, 3);

        add_action('admin_notices', [$this, 'notices']);
        add_filter('removable_query_args', static function ($args) {
            $args[] = 'bdrs_sent';
            $args[] = 'bdrs_failed';
            $args[] = 'bdrs_skipped';
            return $args;
        });
    }

    private function canResend(int $submissionId = 0): bool
    {
        if (!current_user_can(Resender::capability())) {
            return false;
        }
        return $submissionId === 0 || current_user_can('edit_post', $submissionId);
    }

    /* ---------------------------------------------------------------------
     * Single submission screen
     * ------------------------------------------------------------------- */

    public function addMetaBox(): void
    {
        if (!$this->canResend()) {
            return;
        }

        add_meta_box(
            'bdrs_resend',
            __('Resend Email', 'breakdance-resend-submission'),
            [$this, 'renderMetaBox'],
            Resender::postType(),
            'side',
            'high'
        );
    }

    public function enqueue(string $hook): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== Resender::postType() || !in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $url = plugin_dir_url(PLUGIN_FILE);
        wp_enqueue_style('bdrs-admin', $url . 'assets/admin.css', [], VERSION);
        wp_enqueue_script('bdrs-admin', $url . 'assets/admin.js', [], VERSION, true);
        wp_localize_script('bdrs-admin', 'bdrsResend', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::AJAX_ACTION,
            'i18n'    => [
                'sending'     => __('Sending…', 'breakdance-resend-submission'),
                'noneChecked' => __('Select at least one email to send.', 'breakdance-resend-submission'),
                'confirm'     => __('Send the selected email(s) now?', 'breakdance-resend-submission'),
                'failed'      => __('Request failed. Check your connection and try again.', 'breakdance-resend-submission'),
            ],
        ]);
    }

    /**
     * @param \WP_Post $post
     */
    public function renderMetaBox($post): void
    {
        $id = (int) $post->ID;
        $sources = [
            Resender::SOURCE_SNAPSHOT => __('Form settings when submitted', 'breakdance-resend-submission'),
            Resender::SOURCE_CURRENT  => __('Current form settings', 'breakdance-resend-submission'),
        ];

        echo '<div class="bdrs" data-submission="' . esc_attr((string) $id) . '" data-nonce="' . esc_attr(wp_create_nonce(self::AJAX_ACTION . '_' . $id)) . '">';

        echo '<p class="bdrs-source">';
        $first = true;
        foreach ($sources as $source => $label) {
            $available = (bool) $this->resender->getSettings($id, $source);
            printf(
                '<label><input type="radio" name="bdrs_source" value="%s"%s%s> %s%s</label><br>',
                esc_attr($source),
                checked($first && $available, true, false),
                disabled(!$available, true, false),
                esc_html($label),
                $available ? '' : ' <em>(' . esc_html__('unavailable', 'breakdance-resend-submission') . ')</em>'
            );
            $first = $first && !$available;
        }
        echo '</p>';

        foreach (array_keys($sources) as $source) {
            $this->renderEmailList($id, $source);
        }

        echo '<p><label for="bdrs-override"><strong>' . esc_html__('Send to instead (optional)', 'breakdance-resend-submission') . '</strong></label>';
        echo '<input type="text" id="bdrs-override" class="widefat" placeholder="name@example.com, other@example.com">';
        echo '<span class="description">' . esc_html__('Replaces the To address. CC and BCC are dropped.', 'breakdance-resend-submission') . '</span></p>';

        echo '<p><button type="button" class="button button-primary bdrs-send">' . esc_html__('Resend selected', 'breakdance-resend-submission') . '</button></p>';
        echo '<div class="bdrs-result" aria-live="polite"></div>';

        $this->renderLog($id);

        echo '</div>';
    }

    private function renderEmailList(int $id, string $source): void
    {
        $settings = $this->resender->getSettings($id, $source);
        echo '<div class="bdrs-emails" data-source="' . esc_attr($source) . '" hidden>';

        if (!$settings) {
            echo '</div>';
            return;
        }

        $emails = $this->resender->getEmails($settings);
        if (!$emails) {
            echo '<p class="bdrs-empty">' . esc_html__('No Email action is configured on this form.', 'breakdance-resend-submission') . '</p></div>';
            return;
        }

        $form = $this->resender->getFormData($id, $settings);

        echo '<ul>';
        foreach ($emails as $i => $email) {
            $p = $this->resender->preview($email, $form);
            printf(
                '<li><label><input type="checkbox" value="%d"%s> <strong>%s</strong><br><span class="bdrs-to">%s %s</span>%s</label></li>',
                (int) $i,
                checked($p['conditions'], true, false),
                esc_html($p['subject']),
                esc_html__('To:', 'breakdance-resend-submission'),
                esc_html($p['to'] !== '' ? $p['to'] : __('(no valid address)', 'breakdance-resend-submission')),
                $p['conditions'] ? '' : '<br><em class="bdrs-skip">' . esc_html__('Conditions not met, so this was not sent originally.', 'breakdance-resend-submission') . '</em>'
            );
        }
        echo '</ul></div>';
    }

    private function renderLog(int $id): void
    {
        $log = array_slice($this->resender->getLog($id), 0, 10);
        if (!$log) {
            return;
        }

        echo '<details class="bdrs-log"><summary>' . esc_html__('Resend history', 'breakdance-resend-submission') . '</summary><ul>';
        foreach ($log as $entry) {
            $user = !empty($entry['user']) ? get_userdata((int) $entry['user']) : null;
            echo '<li><span class="bdrs-log-meta">' . esc_html(
                mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) ($entry['time'] ?? ''))
                . ($user ? ' · ' . $user->display_name : '')
            ) . '</span><ul>';
            foreach ((array) ($entry['results'] ?? []) as $r) {
                printf(
                    '<li class="%s">%s %s → %s%s</li>',
                    !empty($r['ok']) ? 'bdrs-ok' : 'bdrs-fail',
                    !empty($r['ok']) ? '✓' : '✗',
                    esc_html((string) ($r['subject'] ?? '')),
                    esc_html((string) ($r['to'] ?? '')),
                    empty($r['ok']) && !empty($r['error']) ? ': ' . esc_html((string) $r['error']) : ''
                );
            }
            echo '</ul></li>';
        }
        echo '</ul></details>';
    }

    public function handleAjax(): void
    {
        $id = isset($_POST['submission']) ? absint($_POST['submission']) : 0;

        if (!check_ajax_referer(self::AJAX_ACTION . '_' . $id, 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed. Reload the page and try again.', 'breakdance-resend-submission')], 403);
        }
        if (!$this->canResend($id)) {
            wp_send_json_error(['message' => __('You are not allowed to resend this submission.', 'breakdance-resend-submission')], 403);
        }

        $source = isset($_POST['source']) && $_POST['source'] === Resender::SOURCE_CURRENT
            ? Resender::SOURCE_CURRENT
            : Resender::SOURCE_SNAPSHOT;

        $indexes = isset($_POST['emails']) && is_array($_POST['emails'])
            ? array_values(array_unique(array_map('absint', wp_unslash($_POST['emails']))))
            : [];

        $override = [];
        $rawOverride = isset($_POST['override']) ? sanitize_text_field(wp_unslash($_POST['override'])) : '';
        if ($rawOverride !== '') {
            $override = Resender::parseRecipients($rawOverride);
            if ($override === null) {
                wp_send_json_error(['message' => __('One of the override addresses is not a valid email.', 'breakdance-resend-submission')], 400);
            }
        }

        $result = $this->resender->resend($id, $indexes, $override, $source);

        if (!empty($result['error'])) {
            wp_send_json_error(['message' => $result['error']], 400);
        }

        wp_send_json_success($result);
    }

    /* ---------------------------------------------------------------------
     * Submissions list: row + bulk actions
     * ------------------------------------------------------------------- */

    /**
     * @param array<string, string> $actions
     * @param \WP_Post $post
     */
    public function rowActions($actions, $post): array
    {
        $actions = (array) $actions;
        if (!$post || $post->post_type !== Resender::postType() || !$this->canResend((int) $post->ID)) {
            return $actions;
        }

        $url = wp_nonce_url(
            add_query_arg(['action' => self::ROW_ACTION, 'submission' => (int) $post->ID], admin_url('admin-post.php')),
            self::ROW_ACTION . '_' . (int) $post->ID
        );

        $actions['bdrs_resend'] = sprintf(
            '<a href="%s" onclick="return confirm(%s);">%s</a>',
            esc_url($url),
            esc_attr(wp_json_encode(__('Resend this submission\'s emails to their original recipients?', 'breakdance-resend-submission'))),
            esc_html__('Resend email', 'breakdance-resend-submission')
        );

        return $actions;
    }

    public function handleRowAction(): void
    {
        $id = isset($_GET['submission']) ? absint($_GET['submission']) : 0;
        check_admin_referer(self::ROW_ACTION . '_' . $id);

        if (!$this->canResend($id)) {
            wp_die(esc_html__('You are not allowed to resend this submission.', 'breakdance-resend-submission'), 403);
        }

        $counts = $this->resendMany([$id]);

        $back = wp_get_referer() ?: admin_url('edit.php?post_type=' . Resender::postType());
        wp_safe_redirect(add_query_arg($counts, remove_query_arg(['bdrs_sent', 'bdrs_failed', 'bdrs_skipped'], $back)));
        exit;
    }

    public function bulkActions(array $actions): array
    {
        if ($this->canResend()) {
            $actions[self::BULK_ACTION] = __('Resend emails', 'breakdance-resend-submission');
        }
        return $actions;
    }

    /**
     * @param string $redirect
     * @param string $action
     * @param int[]  $ids
     */
    public function handleBulkAction($redirect, $action, $ids): string
    {
        if ($action !== self::BULK_ACTION) {
            return (string) $redirect;
        }
        if (!$this->canResend()) {
            wp_die(esc_html__('You are not allowed to resend submissions.', 'breakdance-resend-submission'), 403);
        }

        $ids = array_filter(array_map('absint', (array) $ids), function ($id) {
            return $this->canResend($id);
        });

        return add_query_arg($this->resendMany($ids), remove_query_arg(['bdrs_sent', 'bdrs_failed', 'bdrs_skipped'], (string) $redirect));
    }

    /**
     * Resend every eligible email (original recipients, snapshot settings).
     *
     * @param int[] $ids
     */
    private function resendMany(array $ids): array
    {
        $counts = ['bdrs_sent' => 0, 'bdrs_failed' => 0, 'bdrs_skipped' => 0];

        foreach ($ids as $id) {
            $result = $this->resender->resend((int) $id);
            if (!empty($result['error'])) {
                $counts['bdrs_skipped']++;
                continue;
            }
            $counts['bdrs_sent'] += $result['sent'];
            $counts['bdrs_failed'] += $result['failed'];
        }

        return $counts;
    }

    public function notices(): void
    {
        if (!isset($_GET['bdrs_sent']) && !isset($_GET['bdrs_failed']) && !isset($_GET['bdrs_skipped'])) {
            return;
        }

        $sent = absint($_GET['bdrs_sent'] ?? 0);
        $failed = absint($_GET['bdrs_failed'] ?? 0);
        $skipped = absint($_GET['bdrs_skipped'] ?? 0);

        $parts = [];
        /* translators: %d: number of emails */
        $parts[] = sprintf(_n('%d email resent.', '%d emails resent.', $sent, 'breakdance-resend-submission'), $sent);
        if ($failed) {
            /* translators: %d: number of emails */
            $parts[] = sprintf(_n('%d email failed to send.', '%d emails failed to send.', $failed, 'breakdance-resend-submission'), $failed);
        }
        if ($skipped) {
            /* translators: %d: number of submissions */
            $parts[] = sprintf(_n('%d submission skipped (no Email action or form settings).', '%d submissions skipped (no Email action or form settings).', $skipped, 'breakdance-resend-submission'), $skipped);
        }

        $class = $failed || ($skipped && !$sent) ? 'notice-warning' : 'notice-success';
        printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html(implode(' ', $parts)));
    }
}
