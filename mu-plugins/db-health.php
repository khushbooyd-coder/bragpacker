<?php
/**
 * Plugin Name: DB Health (Auto)
 * Description: Weekly cleanup of transients, Woo sessions, Action Scheduler, and heavy autoload.
 */
if (!defined('ABSPATH')) { exit; }

add_action('init', function () {
  if (!wp_next_scheduled('db_health_weekly')) {
    wp_schedule_event(time() + 300, 'weekly', 'db_health_weekly');
  }
});

add_action('db_health_weekly', function () {
  global $wpdb;

  // 1) Transients (temporary cache)
  $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'");

  // 2) Flip heavy non-core autoloaded options (> 5 KB)
  $core = "(siteurl|home|blogname|blogdescription|admin_email|active_plugins|template|stylesheet|rewrite_rules|cron|sidebars_widgets)";
  $wpdb->query(
    $wpdb->prepare(
      "UPDATE {$wpdb->options}
       SET autoload='no'
       WHERE autoload='yes'
         AND LENGTH(option_value) > %d
         AND option_name NOT REGEXP %s
         AND option_name NOT LIKE %s",
      5*1024, $core, '%user_roles'
    )
  );

  // 3) Woo expired sessions
  $sessions = $wpdb->prefix . 'woocommerce_sessions';
  if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $sessions))) {
    $wpdb->query("DELETE FROM {$sessions} WHERE session_expiry < UNIX_TIMESTAMP()");
  }

  // 4) Action Scheduler cleanup & orphan logs
  $a = $wpdb->prefix . 'actionscheduler_actions';
  $l = $wpdb->prefix . 'actionscheduler_logs';
  if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $a))) {
    $wpdb->query("DELETE FROM {$a} WHERE status IN ('complete','failed') AND scheduled_date_gmt < (NOW() - INTERVAL 45 DAY)");
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $l))) {
      $wpdb->query("DELETE l FROM {$l} l LEFT JOIN {$a} a ON a.action_id = l.action_id WHERE a.action_id IS NULL");
    }
  }

  // 5) Refresh stats (harmless if host skips)
  $wpdb->query("ANALYZE TABLE {$wpdb->options}, {$wpdb->posts}, {$wpdb->postmeta}, {$wpdb->usermeta}");
});

// Visit https://golive.bragpacker.com/?db_health_key=PUT_LONG_RANDOM_KEY to run immediately
add_action('init', function () {
  if (!empty($_GET['db_health_key']) && $_GET['db_health_key'] === 'PUT_LONG_RANDOM_KEY') {
    do_action('db_health_weekly');
    wp_die('DB health job executed.');
  }
});

