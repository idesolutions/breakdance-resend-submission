<?php

namespace BreakdanceResendSubmission;

use Breakdance\Forms\Actions\Email;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rebuilds a stored Breakdance submission and runs Breakdance's own Email
 * action against it, so resent emails look exactly like the originals.
 */
class Resender
{
    const LOG_META_KEY = '_bdrs_resend_log';

    const SOURCE_SNAPSHOT = 'snapshot';
    const SOURCE_CURRENT = 'current';

    /** @var string|null */
    private $lastMailError = null;

    public static function isBreakdanceAvailable(): bool
    {
        return class_exists(Email::class) && function_exists('\Breakdance\Forms\getFormData');
    }

    public static function postType(): string
    {
        return (string) apply_filters('bdrs_post_type', 'breakdance_form_res');
    }

    public static function metaPrefix(): string
    {
        if (function_exists('\Breakdance\BreakdanceOxygen\Strings\__bdox')) {
            return (string) \Breakdance\BreakdanceOxygen\Strings\__bdox('_meta_prefix');
        }
        return '_breakdance_';
    }

    public static function capability(): string
    {
        $cap = function_exists('\Breakdance\Forms\Submission\getFormSubmissionCapability')
            ? \Breakdance\Forms\Submission\getFormSubmissionCapability()
            : 'manage_options';

        return (string) apply_filters('bdrs_capability', $cap);
    }

    public function isSubmission(int $submissionId): bool
    {
        return $submissionId > 0 && get_post_type($submissionId) === self::postType();
    }

    private function meta(int $submissionId, string $key)
    {
        return get_post_meta($submissionId, self::metaPrefix() . $key, true);
    }

    /**
     * Form settings to send with: the snapshot stored with the submission,
     * or the form's settings as they are on the page right now.
     *
     * @return array|null
     */
    public function getSettings(int $submissionId, string $source = self::SOURCE_SNAPSHOT)
    {
        if ($source === self::SOURCE_CURRENT) {
            $postId = (int) $this->meta($submissionId, 'post_id');
            $formId = (int) $this->meta($submissionId, 'form_id');

            if ($postId && $formId && function_exists('\Breakdance\Forms\getFormSettings')) {
                $settings = \Breakdance\Forms\getFormSettings($postId, $formId);
                return is_array($settings) ? $settings : null;
            }
            return null;
        }

        $settings = $this->meta($submissionId, 'form_settings');
        return is_array($settings) ? $settings : null;
    }

    /**
     * Email definitions from the form's Email action.
     *
     * @param array|null $settings
     * @return array<int, array>
     */
    public function getEmails($settings): array
    {
        $emails = $settings['actions']['email']['emails'] ?? [];
        if (!is_array($emails)) {
            return [];
        }

        return array_map([$this, 'normalizeEmail'], array_values($emails));
    }

    /**
     * Submitted values merged with the field definitions (Breakdance "FormData").
     *
     * @param array|null $settings
     */
    public function getFormData(int $submissionId, $settings): array
    {
        $values = $this->meta($submissionId, 'fields');
        $values = is_array($values) ? $values : [];
        $fields = $settings['form']['fields'] ?? [];

        return \Breakdance\Forms\getFormData(is_array($fields) ? $fields : [], $values);
    }

    /**
     * Uploaded files that still exist on disk, in Breakdance's grouped format.
     */
    public function getFiles(int $submissionId): array
    {
        $files = [];

        $uploads = $this->meta($submissionId, 'uploads');
        if (is_array($uploads)) {
            foreach ($uploads as $upload) {
                if (is_array($upload) && !empty($upload['file']) && file_exists($upload['file'])) {
                    $files[] = $upload;
                }
            }
        }

        $attachments = get_posts([
            'numberposts' => -1,
            'post_type'   => 'attachment',
            'post_parent' => $submissionId,
            'post_status' => 'any',
            'fields'      => 'ids',
        ]);
        foreach ($attachments as $attachmentId) {
            $path = get_attached_file((int) $attachmentId);
            if ($path && file_exists($path)) {
                $files[] = [
                    'file' => $path,
                    'url'  => (string) wp_get_attachment_url((int) $attachmentId),
                    'type' => (string) get_post_mime_type((int) $attachmentId),
                ];
            }
        }

        return $files ? ['resend' => $files] : [];
    }

    /**
     * Whether an email's "Send Email Conditionally" rules pass for this submission.
     */
    public function conditionsPass(array $email, array $form): bool
    {
        $conditional = !empty($email['send_email_conditionally']);
        $conditions = $email['conditions'] ?? [];

        if (!$conditional || empty($conditions) || !function_exists('\Breakdance\Forms\evaluateConditions')) {
            return true;
        }

        return (bool) \Breakdance\Forms\evaluateConditions($conditions, $form);
    }

    /**
     * Rendered subject / recipients for display in the admin.
     */
    public function preview(array $email, array $form): array
    {
        $action = new Email();

        return [
            'subject'    => $action->sanitize($form, $email['subject'] ?: 'New contact form message'),
            'to'         => implode(', ', $action->getEmails($action->renderData($form, (string) $email['to']))),
            'conditions' => $this->conditionsPass($email, $form),
        ];
    }

    /**
     * Parse a comma-separated list of addresses; returns null if any is invalid.
     *
     * @return string[]|null
     */
    public static function parseRecipients(string $value)
    {
        $list = array_filter(array_map('trim', explode(',', $value)));
        foreach ($list as $address) {
            if (!is_email($address)) {
                return null;
            }
        }
        return array_values($list);
    }

    /**
     * Resend emails for a submission.
     *
     * @param int        $submissionId
     * @param int[]|null $indexes      Email indexes to send; null = every email whose conditions pass.
     * @param string[]   $override     Send to these addresses instead (CC/BCC dropped).
     * @param string     $source       self::SOURCE_SNAPSHOT or self::SOURCE_CURRENT.
     * @return array{sent: int, failed: int, results: array<int, array>, error?: string}
     */
    public function resend(int $submissionId, $indexes = null, array $override = [], string $source = self::SOURCE_SNAPSHOT): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'results' => []];

        if (!$this->isSubmission($submissionId)) {
            return $out + ['error' => __('Submission not found.', 'breakdance-resend-submission')];
        }

        $settings = $this->getSettings($submissionId, $source);
        if (!$settings) {
            return $out + ['error' => __('Could not load the form settings for this submission.', 'breakdance-resend-submission')];
        }

        $emails = $this->getEmails($settings);
        if (!$emails) {
            return $out + ['error' => __('This form has no Email action configured.', 'breakdance-resend-submission')];
        }

        $form = $this->getFormData($submissionId, $settings);
        $files = $this->getFiles($submissionId);

        $selected = [];
        foreach ($emails as $i => $email) {
            if ($indexes === null ? $this->conditionsPass($email, $form) : in_array($i, $indexes, true)) {
                $selected[$i] = $email;
            }
        }

        if (!$selected) {
            return $out + ['error' => __('No emails selected to send.', 'breakdance-resend-submission')];
        }

        add_action('wp_mail_failed', [$this, 'captureMailError']);

        foreach ($selected as $i => $email) {
            if ($override) {
                $email['to'] = implode(', ', $override);
                $email['cc'] = '';
                $email['bcc'] = '';
            }

            /** @var array $email Lets other code adjust a resent email before it goes out. */
            $email = apply_filters('bdrs_email_before_resend', $email, $submissionId, $i, $form);

            $this->lastMailError = null;
            $action = new Email();
            $preview = $this->preview($email, $form);

            $ok = false;
            try {
                $ok = (bool) $action->submit($email, $form, $files);
            } catch (\Throwable $e) {
                $this->lastMailError = $e->getMessage();
            }

            $result = [
                'index'   => $i,
                'subject' => $preview['subject'],
                'to'      => $preview['to'],
                'ok'      => $ok,
                'error'   => $ok ? '' : ($this->lastMailError ?: ($preview['to'] === ''
                    ? __('No valid recipient address.', 'breakdance-resend-submission')
                    : __('wp_mail() reported a failure.', 'breakdance-resend-submission'))),
            ];

            $out['results'][] = $result;
            $out[$ok ? 'sent' : 'failed']++;

            do_action('bdrs_email_resent', $submissionId, $result, $email);
        }

        remove_action('wp_mail_failed', [$this, 'captureMailError']);

        $this->log($submissionId, [
            'time'     => current_time('mysql'),
            'user'     => get_current_user_id(),
            'source'   => $source,
            'override' => $override,
            'results'  => $out['results'],
        ]);

        return $out;
    }

    /**
     * @param \WP_Error $error
     */
    public function captureMailError($error): void
    {
        if ($error instanceof \WP_Error) {
            $this->lastMailError = $error->get_error_message();
        }
    }

    private function log(int $submissionId, array $entry): void
    {
        add_post_meta($submissionId, self::LOG_META_KEY, $entry);
    }

    /**
     * Resend history, newest first.
     */
    public function getLog(int $submissionId): array
    {
        $entries = get_post_meta($submissionId, self::LOG_META_KEY);
        $entries = is_array($entries) ? array_filter($entries, 'is_array') : [];
        return array_reverse(array_values($entries));
    }

    /**
     * Fill in keys Email::submit() reads without null checks.
     */
    private function normalizeEmail($email): array
    {
        $email = is_array($email) ? $email : [];

        return array_merge([
            'subject'   => '',
            'to'        => '',
            'from'      => '',
            'from_name' => '',
            'reply_to'  => '',
            'message'   => '',
            'cc'        => '',
            'bcc'       => '',
        ], array_map(static function ($v) {
            return $v === null ? '' : $v;
        }, $email));
    }
}
