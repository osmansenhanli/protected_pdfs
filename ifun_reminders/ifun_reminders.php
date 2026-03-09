<?php
/*
Plugin Name: iFun Reminders
Description: Centralized reminder queue with a 10-minute WP-Cron runner.
Version: 1.0.0
Author: iFun Learning
*/

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('IFUN_REMINDERS_TABLE')) {
    define('IFUN_REMINDERS_TABLE', 'ifun_reminders_queue');
}

if (!defined('IFUN_REMINDERS_CRON_HOOK')) {
    define('IFUN_REMINDERS_CRON_HOOK', 'ifun_reminders_run_queue');
}

if (!defined('IFUN_REMINDERS_CRON_SCHEDULE')) {
    define('IFUN_REMINDERS_CRON_SCHEDULE', 'ifun_reminders_interval');
}

if (!defined('IFUN_REMINDERS_DEFAULT_INTERVAL_MINUTES')) {
    define('IFUN_REMINDERS_DEFAULT_INTERVAL_MINUTES', 5);
}

if (!defined('IFUN_REMINDERS_BATCH_SIZE')) {
    define('IFUN_REMINDERS_BATCH_SIZE', 50);
}

if (!defined('IFUN_REMINDERS_MAX_ATTEMPTS')) {
    define('IFUN_REMINDERS_MAX_ATTEMPTS', 5);
}

class IFUN_Reminders {
    public static function init() {
        add_filter('cron_schedules', [__CLASS__, 'add_cron_schedule']);
        add_action('init', [__CLASS__, 'ensure_cron_scheduled']);
        add_action(IFUN_REMINDERS_CRON_HOOK, [__CLASS__, 'run_queue']);
    }

    public static function activate() {
        self::create_table();
        self::ensure_cron_scheduled();
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled(IFUN_REMINDERS_CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, IFUN_REMINDERS_CRON_HOOK);
            $timestamp = wp_next_scheduled(IFUN_REMINDERS_CRON_HOOK);
        }
    }

    public static function add_cron_schedule($schedules) {
        $minutes = self::get_cron_interval_minutes();
        $schedules[IFUN_REMINDERS_CRON_SCHEDULE] = [
            'interval' => $minutes * MINUTE_IN_SECONDS,
            'display'  => sprintf(__('Every %d Minutes', 'ifun-reminders'), $minutes),
        ];

        return $schedules;
    }

    public static function ensure_cron_scheduled() {
        $minutes = self::get_cron_interval_minutes();
        $expected_interval = $minutes * MINUTE_IN_SECONDS;

        if (function_exists('wp_get_scheduled_event')) {
            $event = wp_get_scheduled_event(IFUN_REMINDERS_CRON_HOOK);
            if ($event && isset($event->interval) && (int) $event->interval !== (int) $expected_interval) {
                wp_unschedule_event($event->timestamp, IFUN_REMINDERS_CRON_HOOK, $event->args);
                $event = null;
            }
            if (!$event) {
                wp_schedule_event(time() + 60, IFUN_REMINDERS_CRON_SCHEDULE, IFUN_REMINDERS_CRON_HOOK);
            }
            return;
        }

        if (!wp_next_scheduled(IFUN_REMINDERS_CRON_HOOK)) {
            wp_schedule_event(time() + 60, IFUN_REMINDERS_CRON_SCHEDULE, IFUN_REMINDERS_CRON_HOOK);
        }
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . IFUN_REMINDERS_TABLE;
    }

    public static function create_table() {
        global $wpdb;

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            email VARCHAR(255) NOT NULL,
            send_at_utc DATETIME NOT NULL,
            timezone VARCHAR(64) NOT NULL,
            template_key VARCHAR(100) NOT NULL,
            payload LONGTEXT NULL,
            idempotency_key VARCHAR(191) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            sent_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_idempotency (idempotency_key),
            KEY idx_status_send_at (status, send_at_utc),
            KEY idx_email (email)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function enqueue($email, $send_at_local, $timezone, $template_key, array $payload = []) {
        global $wpdb;

        $email = sanitize_email($email);
        if (!is_email($email)) {
            return new WP_Error('invalid_email', 'Invalid email address.');
        }

        $timezone = self::normalize_timezone($timezone);
        if (!$timezone) {
            return new WP_Error('invalid_timezone', 'Invalid timezone.');
        }

        $send_at_utc = self::to_utc_datetime($send_at_local, $timezone);
        if (!$send_at_utc) {
            return new WP_Error('invalid_send_at', 'Invalid send date/time.');
        }

        $template_key = sanitize_key($template_key);
        if ($template_key === '') {
            return new WP_Error('invalid_template_key', 'Template key is required.');
        }

        $payload_json = !empty($payload) ? wp_json_encode($payload) : null;
        $idempotency_key = self::idempotency_key($email, $send_at_utc, $template_key, $payload_json);

        $table = self::table_name();
        $now = current_time('mysql', 1);

        $inserted = $wpdb->insert(
            $table,
            [
                'created_at'       => $now,
                'updated_at'       => $now,
                'email'            => $email,
                'send_at_utc'      => $send_at_utc,
                'timezone'         => $timezone,
                'template_key'     => $template_key,
                'payload'          => $payload_json,
                'idempotency_key'  => $idempotency_key,
                'status'           => 'pending',
                'attempts'         => 0,
                'last_error'       => null,
                'sent_at'          => null,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        if ($inserted === false) {
            if (stripos((string) $wpdb->last_error, 'Duplicate') !== false) {
                return true;
            }
            return new WP_Error('db_insert_failed', 'Could not register reminder.');
        }

        return (int) $wpdb->insert_id;
    }

    public static function run_queue() {
        global $wpdb;

        $table = self::table_name();
        $now_gmt = gmdate('Y-m-d H:i:s');

        $jobs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE status = %s
                   AND send_at_utc <= %s
                 ORDER BY send_at_utc ASC, id ASC
                 LIMIT %d",
                'pending',
                $now_gmt,
                IFUN_REMINDERS_BATCH_SIZE
            ),
            ARRAY_A
        );

        if (empty($jobs)) {
            return;
        }

        foreach ($jobs as $job) {
            $job_id = (int) $job['id'];

            $locked = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table}
                     SET status = %s, updated_at = %s
                     WHERE id = %d AND status = %s",
                    'processing',
                    current_time('mysql', 1),
                    $job_id,
                    'pending'
                )
            );

            if ($locked !== 1) {
                continue;
            }

            $result = self::send_job($job);
            if ($result === true) {
                $wpdb->update(
                    $table,
                    [
                        'status'     => 'sent',
                        'updated_at' => current_time('mysql', 1),
                        'sent_at'    => current_time('mysql', 1),
                        'last_error' => null,
                    ],
                    ['id' => $job_id],
                    ['%s', '%s', '%s', '%s'],
                    ['%d']
                );
                do_action('ifun_reminders_job_terminal', $job, 'sent', $result);
                continue;
            }

            if (is_wp_error($result) && $result->get_error_code() === 'skip_send') {
                $wpdb->update(
                    $table,
                    [
                        'status'     => 'skipped',
                        'updated_at' => current_time('mysql', 1),
                        'last_error' => substr((string) $result->get_error_message(), 0, 1000),
                    ],
                    ['id' => $job_id],
                    ['%s', '%s', '%s'],
                    ['%d']
                );
                do_action('ifun_reminders_job_terminal', $job, 'skipped', $result);
                continue;
            }

            if (is_wp_error($result) && $result->get_error_code() === 'defer_send') {
                $data = $result->get_error_data();
                $next_send_at = '';
                if (is_array($data) && !empty($data['next_send_at_utc'])) {
                    $next_send_at = (string) $data['next_send_at_utc'];
                }
                if ($next_send_at === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $next_send_at)) {
                    $next_send_at = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
                }

                $wpdb->update(
                    $table,
                    [
                        'status'      => 'pending',
                        'send_at_utc' => $next_send_at,
                        'updated_at'  => current_time('mysql', 1),
                        'last_error'  => substr((string) $result->get_error_message(), 0, 1000),
                    ],
                    ['id' => $job_id],
                    ['%s', '%s', '%s', '%s'],
                    ['%d']
                );
                continue;
            }

            $attempts = ((int) $job['attempts']) + 1;
            $maxed_out = $attempts >= IFUN_REMINDERS_MAX_ATTEMPTS;

            $next_status = $maxed_out ? 'failed' : 'pending';
            $next_send_at = $maxed_out
                ? $job['send_at_utc']
                : gmdate('Y-m-d H:i:s', time() + self::retry_delay_seconds($attempts));

            $error_message = is_wp_error($result)
                ? $result->get_error_message()
                : 'Unknown send error';

            $wpdb->update(
                $table,
                [
                    'status'     => $next_status,
                    'attempts'   => $attempts,
                    'send_at_utc'=> $next_send_at,
                    'updated_at' => current_time('mysql', 1),
                    'last_error' => substr((string) $error_message, 0, 1000),
                ],
                ['id' => $job_id],
                ['%s', '%d', '%s', '%s', '%s'],
                ['%d']
            );
        }
    }

    private static function send_job(array $job) {
        $payload = [];
        if (!empty($job['payload'])) {
            $decoded = json_decode($job['payload'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $should_send = apply_filters('ifun_reminders_should_send', true, $job, $payload);
        if (is_wp_error($should_send)) {
            return $should_send;
        }

        if (!$should_send) {
            return new WP_Error('skip_send', 'Skipped by ifun_reminders_should_send filter.');
        }

        $template = self::build_template($job['template_key'], $job['email'], $payload, $job);
        if (is_wp_error($template)) {
            return $template;
        }

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $sent = self::deliver_mail($job['email'], $template['subject'], $template['body'], $headers);
        if (is_wp_error($sent)) {
            return $sent;
        }

        if (self::should_send_admin_copy()) {
            $admin_email = self::admin_copy_email();
            if ($admin_email !== '' && strcasecmp($admin_email, (string) $job['email']) !== 0) {
                $copy_subject = 'Copy: ' . (string) $template['subject'];
                $copy = self::deliver_mail($admin_email, $copy_subject, $template['body'], $headers);
                if (is_wp_error($copy)) {
                    return new WP_Error('admin_copy_failed', 'Admin copy failed: ' . $copy->get_error_message());
                }
            }
        }

        return true;
    }

    private static function deliver_mail($to, $subject, $body, array $headers = []) {
        if (function_exists('ifun_send_mail')) {
            $sent = ifun_send_mail($to, $subject, $body, $headers);
            if ($sent === true || $sent === 1) {
                return true;
            }
            if (is_wp_error($sent)) {
                return $sent;
            }
            return new WP_Error('mail_failed', 'ifun_send_mail failed.');
        }

        $sent = wp_mail($to, $subject, $body, $headers);
        if (!$sent) {
            return new WP_Error('mail_failed', 'wp_mail failed.');
        }

        return true;
    }

    private static function build_template($template_key, $email, array $payload, array $job) {
        $template_key = sanitize_key($template_key);
        $file_template = self::load_template_from_file($template_key, $email, $payload, $job);
        if (!is_wp_error($file_template)) {
            return $file_template;
        }

        $filtered = apply_filters('ifun_reminders_build_template', null, $template_key, $email, $payload, $job);
        if (is_array($filtered) && !empty($filtered['subject']) && !empty($filtered['body'])) {
            return [
                'subject' => (string) $filtered['subject'],
                'body'    => (string) $filtered['body'],
            ];
        }

        return new WP_Error('unknown_template', 'Unknown reminder template: ' . $template_key);
    }

    private static function load_template_from_file($template_key, $email, array $payload, array $job) {
        // Integrations can override where template files live via:
        // add_filter('ifun_reminders_template_file_path', function($path, $template_key){ ... }, 10, 2);
        $file = self::template_file_path($template_key);
        if (!is_file($file)) {
            return new WP_Error('template_file_not_found', 'Template file not found.');
        }

        require_once $file;

        $function = 'ifun_reminders_template_' . $template_key;
        if (!function_exists($function)) {
            return new WP_Error('template_handler_not_found', 'Template handler not found.');
        }

        $result = call_user_func($function, $email, $payload, $job);
        if (!is_array($result) || empty($result['subject']) || empty($result['body'])) {
            return new WP_Error('template_handler_invalid', 'Template handler returned invalid response.');
        }

        return [
            'subject' => (string) $result['subject'],
            'body'    => (string) $result['body'],
        ];
    }

    private static function template_file_path($template_key) {
        $name = str_replace('_', '-', sanitize_key($template_key));
        $default_path = plugin_dir_path(__FILE__) . 'email-types/' . $name . '.php';
        $resolved = apply_filters('ifun_reminders_template_file_path', $default_path, $template_key);

        return is_string($resolved) ? $resolved : $default_path;
    }

    private static function normalize_timezone($timezone) {
        $timezone = trim((string) $timezone);
        if ($timezone === '') {
            $timezone = wp_timezone_string();
        }

        if ($timezone === '') {
            $timezone = 'UTC';
        }

        try {
            new DateTimeZone($timezone);
            return $timezone;
        } catch (Exception $e) {
            return null;
        }
    }

    private static function to_utc_datetime($send_at_local, $timezone) {
        try {
            $dt = new DateTime((string) $send_at_local, new DateTimeZone($timezone));
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }

    private static function idempotency_key($email, $send_at_utc, $template_key, $payload_json) {
        return hash('sha256', strtolower($email) . '|' . $send_at_utc . '|' . $template_key . '|' . (string) $payload_json);
    }

    private static function retry_delay_seconds($attempts) {
        $steps = [
            1 => 10 * MINUTE_IN_SECONDS,
            2 => 30 * MINUTE_IN_SECONDS,
            3 => 2 * HOUR_IN_SECONDS,
            4 => 6 * HOUR_IN_SECONDS,
        ];

        return isset($steps[$attempts]) ? $steps[$attempts] : DAY_IN_SECONDS;
    }

    private static function get_cron_interval_minutes() {
        $opt = get_option('ifun_reminders_cron_interval_minutes', IFUN_REMINDERS_DEFAULT_INTERVAL_MINUTES);
        $minutes = (int) $opt;
        $minutes = (int) apply_filters('ifun_reminders_cron_interval_minutes', $minutes);
        if ($minutes < 1) {
            $minutes = IFUN_REMINDERS_DEFAULT_INTERVAL_MINUTES;
        }
        return $minutes;
    }

    private static function should_send_admin_copy() {
        $opt = get_option('ifun_reminders_send_admin_copy', '1');
        $enabled = in_array(strtolower((string) $opt), ['1', 'true', 'yes', 'on'], true);
        return (bool) apply_filters('ifun_reminders_send_admin_copy', $enabled);
    }

    private static function admin_copy_email() {
        $email = apply_filters('ifun_reminders_admin_copy_email', get_option('admin_email'));
        return is_email($email) ? (string) $email : '';
    }
}

IFUN_Reminders::init();
register_activation_hook(__FILE__, ['IFUN_Reminders', 'activate']);
register_deactivation_hook(__FILE__, ['IFUN_Reminders', 'deactivate']);

if (!function_exists('ifun_reminders_enqueue')) {
    function ifun_reminders_enqueue($email, $send_at_local, $timezone, $template_key, $payload = []) {
        if (!is_array($payload)) {
            $payload = [];
        }
        return IFUN_Reminders::enqueue($email, $send_at_local, $timezone, $template_key, $payload);
    }
}
