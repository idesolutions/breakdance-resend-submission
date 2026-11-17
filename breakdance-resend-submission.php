<?php
/**
 * Plugin Name:       Breakdance Resend Submission
 * Plugin URI:        https://github.com/idesolutions/breakdance-resend-submission
 * Description:       Resend emails from Breakdance form submissions. Adds a Resend Email box to each submission, plus row and bulk actions in the submissions list.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            IDE Solutions LLC
 * Author URI:        https://idesolutions.io/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       breakdance-resend-submission
 */

namespace BreakdanceResendSubmission;

if (!defined('ABSPATH')) {
    exit;
}

const VERSION = '1.0.0';
const PLUGIN_FILE = __FILE__;

require_once __DIR__ . '/includes/class-resender.php';
require_once __DIR__ . '/includes/class-admin.php';

add_action('plugins_loaded', static function () {
    if (!Resender::isBreakdanceAvailable()) {
        add_action('admin_notices', static function () {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('Breakdance Resend Submission needs Breakdance (with forms) to be active.', 'breakdance-resend-submission')
                . '</p></div>';
        });
        return;
    }

    (new Admin(new Resender()))->register();
}, 20);
