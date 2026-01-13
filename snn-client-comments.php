<?php
/**
 * Plugin Name: SNN Client Comments
 * Plugin URI: https://github.com/sinanisler/snn-client-comments
 * Description: Visual commenting system for WordPress - Add comments directly on any page location with multi-user collaboration support
 * Version: 0.2
 * Author: sinanisler
 * Author URI: https://github.com/sinanisler
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: snn-client-comments
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SNN_CC_VERSION', '1.0.0');
define('SNN_CC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SNN_CC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include GitHub Auto-Update functionality
require_once SNN_CC_PLUGIN_DIR . 'github-update.php';

/**
 * Create database tables on activation
 */
function snn_cc_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Main comments table
    $table_name = $wpdb->prefix . 'snn_client_comments';
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        parent_id bigint(20) DEFAULT 0,
        user_id bigint(20) NOT NULL,
        page_url varchar(500) NOT NULL,
        pos_x varchar(20) NOT NULL,
        pos_y varchar(20) NOT NULL,
        comment text NOT NULL,
        status varchar(20) DEFAULT 'active',
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY parent_id (parent_id),
        KEY user_id (user_id),
        KEY page_url (page_url(191)),
        KEY status (status)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Guest access tokens table
    $tokens_table = $wpdb->prefix . 'snn_client_comments_tokens';
    $sql_tokens = "CREATE TABLE IF NOT EXISTS $tokens_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        token varchar(64) NOT NULL UNIQUE,
        page_url varchar(500) NOT NULL,
        created_by bigint(20) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        expires_at datetime NULL,
        last_used datetime NULL,
        is_active tinyint(1) DEFAULT 1,
        PRIMARY KEY (id),
        KEY token (token),
        KEY page_url (page_url(191)),
        KEY created_by (created_by)
    ) $charset_collate;";

    dbDelta($sql_tokens);

    // Set default options
    add_option('snn_cc_enabled', '1');
    add_option('snn_cc_marker_color', '#0073aa');
    add_option('snn_cc_show_in_frontend', '1');
    add_option('snn_cc_allow_replies', '1');
    add_option('snn_cc_marker_style', 'initials');
    add_option('snn_cc_auto_collapse', '0');
    add_option('snn_cc_guest_commenting', '0');
}
register_activation_hook(__FILE__, 'snn_cc_create_tables');

/**
 * Add settings page under Settings menu
 */
function snn_cc_add_settings_page() {
    add_options_page(
        'SNN Client Comments Settings',
        'Client Comments',
        'manage_options',
        'snn-client-comments',
        'snn_cc_settings_page'
    );
}
add_action('admin_menu', 'snn_cc_add_settings_page');

/**
 * Register settings
 */
function snn_cc_register_settings() {
    register_setting('snn_cc_settings', 'snn_cc_enabled');
    register_setting('snn_cc_settings', 'snn_cc_marker_color');
    register_setting('snn_cc_settings', 'snn_cc_show_in_frontend');
    register_setting('snn_cc_settings', 'snn_cc_allow_replies');
    register_setting('snn_cc_settings', 'snn_cc_marker_style');
    register_setting('snn_cc_settings', 'snn_cc_auto_collapse');
    register_setting('snn_cc_settings', 'snn_cc_guest_commenting');
}
add_action('admin_init', 'snn_cc_register_settings');

/**
 * Settings page HTML
 */
function snn_cc_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Save settings
    if (isset($_POST['snn_cc_save_settings']) && check_admin_referer('snn_cc_settings_nonce')) {
        update_option('snn_cc_enabled', isset($_POST['snn_cc_enabled']) ? '1' : '0');
        update_option('snn_cc_marker_color', sanitize_hex_color($_POST['snn_cc_marker_color']));
        update_option('snn_cc_show_in_frontend', isset($_POST['snn_cc_show_in_frontend']) ? '1' : '0');
        update_option('snn_cc_allow_replies', isset($_POST['snn_cc_allow_replies']) ? '1' : '0');
        update_option('snn_cc_marker_style', sanitize_text_field($_POST['snn_cc_marker_style']));
        update_option('snn_cc_auto_collapse', isset($_POST['snn_cc_auto_collapse']) ? '1' : '0');
        update_option('snn_cc_guest_commenting', isset($_POST['snn_cc_guest_commenting']) ? '1' : '0');

        echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
    }

    $enabled = get_option('snn_cc_enabled', '1');
    $marker_color = get_option('snn_cc_marker_color', '#0073aa');
    $show_in_frontend = get_option('snn_cc_show_in_frontend', '1');
    $allow_replies = get_option('snn_cc_allow_replies', '1');
    $marker_style = get_option('snn_cc_marker_style', 'initials');
    $auto_collapse = get_option('snn_cc_auto_collapse', '0');
    $guest_commenting = get_option('snn_cc_guest_commenting', '0');

    ?>
    <div class="wrap">
        <h1>SNN Client Comments Settings</h1>

        <form method="post" action="">
            <?php wp_nonce_field('snn_cc_settings_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Enable Plugin</th>
                    <td>
                        <label>
                            <input type="checkbox" name="snn_cc_enabled" value="1" <?php checked($enabled, '1'); ?>>
                            Enable client comments system
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Marker Color</th>
                    <td>
                        <input type="color" name="snn_cc_marker_color" value="<?php echo esc_attr($marker_color); ?>">
                        <p class="description">Choose the color for comment markers</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Display Options</th>
                    <td>
                        <label>
                            <input type="checkbox" name="snn_cc_show_in_frontend" value="1" <?php checked($show_in_frontend, '1'); ?>>
                            Show in frontend
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Marker Style</th>
                    <td>
                        <select name="snn_cc_marker_style">
                            <option value="initials" <?php selected($marker_style, 'initials'); ?>>User Initials</option>
                            <option value="number" <?php selected($marker_style, 'number'); ?>>Numbers</option>
                            <option value="icon" <?php selected($marker_style, 'icon'); ?>>Icon</option>
                        </select>
                        <p class="description">How to display markers on the page</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Features</th>
                    <td>
                        <label>
                            <input type="checkbox" name="snn_cc_allow_replies" value="1" <?php checked($allow_replies, '1'); ?>>
                            Allow threaded replies
                        </label><br>
                        <label>
                            <input type="checkbox" name="snn_cc_auto_collapse" value="1" <?php checked($auto_collapse, '1'); ?>>
                            Auto-collapse old comments
                        </label><br>
                        <label>
                            <input type="checkbox" name="snn_cc_guest_commenting" value="1" <?php checked($guest_commenting, '1'); ?>>
                            Enable Guest Commenting
                        </label>
                        <p class="description">Allow guests to comment using secure share links</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" name="snn_cc_save_settings" class="button button-primary" value="Save Settings">
            </p>
        </form>

        <hr>

        <h2>Statistics</h2>
        <?php
        global $wpdb;
        $table_name = $wpdb->prefix . 'snn_client_comments';
        $total_comments = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE parent_id = 0");
        $total_replies = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE parent_id > 0");
        $total_users = $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM $table_name");
        ?>
        <table class="widefat">
            <tr>
                <td><strong>Total Comments:</strong></td>
                <td><?php echo intval($total_comments); ?></td>
            </tr>
            <tr>
                <td><strong>Total Replies:</strong></td>
                <td><?php echo intval($total_replies); ?></td>
            </tr>
            <tr>
                <td><strong>Active Users:</strong></td>
                <td><?php echo intval($total_users); ?></td>
            </tr>
        </table>

        <hr>

    </div>
    <?php
}

/**
 * Add button to admin bar
 */
function snn_cc_add_admin_bar_button($wp_admin_bar) {
    if (!is_user_logged_in()) return;
    if (!get_option('snn_cc_enabled', '1')) return;

    // Check display options
    $show_in_frontend = get_option('snn_cc_show_in_frontend', '1');

    // Don't show buttons on admin dashboard/pages (only frontend)
    if (is_admin()) return;

    // Only show on frontend if enabled
    if (!$show_in_frontend) return;

    $guest_commenting = get_option('snn_cc_guest_commenting', '0');

    // Add parent menu
    $wp_admin_bar->add_node(array(
        'id'    => 'snn-cc-menu',
        'title' => '<span class="ab-icon dashicons dashicons-admin-comments"></span><span class="ab-label">Comments</span>',
        'href'  => '#',
        'meta'  => array('class' => 'snn-cc-menu-parent')
    ));

    // Add comment button
    $wp_admin_bar->add_node(array(
        'id'    => 'snn-cc-add-btn',
        'parent' => 'snn-cc-menu',
        'title' => '<span class="dashicons dashicons-location-alt"></span> Add Comment',
        'href'  => '#',
        'meta'  => array('class' => 'snn-cc-toggle-add')
    ));

    // Add sidebar toggle button
    $wp_admin_bar->add_node(array(
        'id'    => 'snn-cc-sidebar-btn',
        'parent' => 'snn-cc-menu',
        'title' => '<span class="dashicons dashicons-list-view"></span> View Comments',
        'href'  => '#',
        'meta'  => array('class' => 'snn-cc-toggle-sidebar')
    ));

    // Add Copy Share button if guest commenting is enabled
    if ($guest_commenting === '1') {
        $wp_admin_bar->add_node(array(
            'id'    => 'snn-cc-share-btn',
            'parent' => 'snn-cc-menu',
            'title' => '<span class="dashicons dashicons-share"></span> Copy Share Link',
            'href'  => '#',
            'meta'  => array('class' => 'snn-cc-copy-share')
        ));
    }
}
add_action('admin_bar_menu', 'snn_cc_add_admin_bar_button', 999);

/**
 * Generate guest access token
 */
function snn_cc_generate_guest_token($page_url) {
    global $wpdb;
    $tokens_table = $wpdb->prefix . 'snn_client_comments_tokens';

    // Generate secure random token
    $token = bin2hex(random_bytes(32));
    $user_id = get_current_user_id();

    // Insert token into database
    $wpdb->insert(
        $tokens_table,
        array(
            'token' => $token,
            'page_url' => $page_url,
            'created_by' => $user_id,
            'is_active' => 1
        ),
        array('%s', '%s', '%d', '%d')
    );

    return $token;
}

/**
 * Validate guest token
 */
function snn_cc_validate_guest_token($token, $page_url) {
    global $wpdb;
    $tokens_table = $wpdb->prefix . 'snn_client_comments_tokens';

    $token_data = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tokens_table WHERE token = %s AND page_url = %s AND is_active = 1",
        $token,
        $page_url
    ));

    if ($token_data) {
        // Update last used timestamp
        $wpdb->update(
            $tokens_table,
            array('last_used' => current_time('mysql')),
            array('id' => $token_data->id),
            array('%s'),
            array('%d')
        );

        return true;
    }

    return false;
}

/**
 * Check if current user is guest with valid token
 */
function snn_cc_is_guest_user() {
    if (is_user_logged_in()) {
        return false;
    }

    if (!get_option('snn_cc_guest_commenting', '0')) {
        return false;
    }

    // Check if guest token is in session or URL
    if (isset($_GET['snn_guest_token'])) {
        $token = sanitize_text_field($_GET['snn_guest_token']);
        $page_url = home_url($_SERVER['REQUEST_URI']);
        $page_url = strtok($page_url, '?'); // Remove query string for validation

        if (snn_cc_validate_guest_token($token, $page_url)) {
            // Store token in session
            if (!session_id()) {
                session_start();
            }
            $_SESSION['snn_guest_token'] = $token;
            $_SESSION['snn_guest_page'] = $page_url;
            return true;
        }
    } elseif (isset($_SESSION['snn_guest_token']) && isset($_SESSION['snn_guest_page'])) {
        $token = $_SESSION['snn_guest_token'];
        $page_url = $_SESSION['snn_guest_page'];

        return snn_cc_validate_guest_token($token, $page_url);
    }

    return false;
}

/**
 * Initialize session for guest users
 */
function snn_cc_init_session() {
    if (!session_id() && !is_user_logged_in()) {
        session_start();
    }
}
add_action('init', 'snn_cc_init_session', 1);

/**
 * Enqueue scripts and styles
 */
function snn_cc_enqueue_scripts() {
    // Check if user is logged in or is a valid guest
    $is_guest = snn_cc_is_guest_user();
    if (!is_user_logged_in() && !$is_guest) return;

    if (!get_option('snn_cc_enabled', '1')) return;

    // Check display options
    $show_in_frontend = get_option('snn_cc_show_in_frontend', '1');

    // Don't show on admin dashboard/pages (only frontend)
    if (is_admin()) return;

    // Only show on frontend if enabled
    if (!$show_in_frontend) return;

    // Enqueue dependencies
    wp_enqueue_style('dashicons');
    wp_enqueue_script('jquery');

    $marker_color = get_option('snn_cc_marker_color', '#0073aa');
    $marker_style = get_option('snn_cc_marker_style', 'initials');
    $allow_replies = get_option('snn_cc_allow_replies', '1');
    $guest_commenting = get_option('snn_cc_guest_commenting', '0');

    // Handle user data for both logged in and guest users
    if ($is_guest) {
        $user_id = -1; // Special ID for guest users
        $user_name = 'Guest';
        $user_initials = 'G';
    } else {
        $current_user = wp_get_current_user();
        $user_id = get_current_user_id();
        $user_name = $current_user->display_name;
        $user_initials = snn_cc_get_user_initials($user_name);
    }

    // Check if admin bar is showing
    $admin_bar_height = is_admin_bar_showing() ? 32 : 0;

    // Add inline styles and scripts
    add_action('wp_footer', function() use ($marker_color, $marker_style, $allow_replies, $user_id, $user_name, $user_initials, $admin_bar_height, $guest_commenting) {
    ?>
    <style>
        /* Admin Bar Styles */
        #wpadminbar #wp-admin-bar-snn-cc-menu .ab-icon:before {
            content: "\f101";
            top: 2px;
        }
        #wpadminbar #wp-admin-bar-snn-cc-add-btn .dashicons {
            font-size: 16px;
            line-height: 1;
            vertical-align: middle;
            margin-right: 4px;
        }
        #wpadminbar #wp-admin-bar-snn-cc-sidebar-btn .dashicons,
        #wpadminbar #wp-admin-bar-snn-cc-share-btn .dashicons {
            font-size: 16px;
            line-height: 1;
            vertical-align: middle;
            margin-right: 4px;
        }
        #wpadminbar #wp-admin-bar-snn-cc-add-btn.active,
        #wpadminbar #wp-admin-bar-snn-cc-sidebar-btn.active {
            background: <?php echo esc_attr($marker_color); ?> !important;
        }

        /* Share notification */
        .snn-cc-notification {
            position: fixed;
            top: <?php echo $admin_bar_height + 10; ?>px;
            right: 20px;
            background: #4caf50;
            color: #fff;
            padding: 12px 20px;
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            z-index: 9999999;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { transform: translateX(400px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* Click Mode Cursor */
        body.snn-cc-click-mode * {
            cursor: crosshair !important;
        }

        /* Comment Markers */
        .snn-cc-marker {
            position: absolute;
            width: 36px;
            height: 36px;
            background: <?php echo esc_attr($marker_color); ?>;
            border-radius: 50%;
            border: 3px solid #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            cursor: pointer;
            z-index: 999999;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: bold;
            font-size: 14px;
            transition: transform 0.2s, box-shadow 0.2s;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, sans-serif;
        }
        .snn-cc-marker:hover {
            transform: scale(1.15);
            box-shadow: 0 4px 12px rgba(0,0,0,0.4);
        }
        .snn-cc-marker.has-replies:after {
            content: '';
            position: absolute;
            top: -2px;
            right: -2px;
            width: 12px;
            height: 12px;
            background: #d63638;
            border-radius: 50%;
            border: 2px solid #fff;
        }

        /* Sidebar */
        .snn-cc-sidebar {
            position: fixed;
            top: <?php echo $admin_bar_height; ?>px;
            left: -320px;
            width: 300px;
            height: calc(100vh - <?php echo $admin_bar_height; ?>px);
            background: #fff;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            z-index: 999998;
            transition: left 0.3s ease;
            overflow-y: auto;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, sans-serif;
        }
        .snn-cc-sidebar.active {
            left: 0;
        }
        .snn-cc-sidebar-header {
            padding: 15px;
            background: #f5f5f5;
            border-bottom: 1px solid #ddd;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .snn-cc-sidebar-header h3 {
            margin: 0;
            font-size: 16px;
            color: #333;
        }
        .snn-cc-sidebar-close {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: #666;
            padding: 0;
            width: 24px;
            height: 24px;
            line-height: 1;
        }
        .snn-cc-sidebar-close:hover {
            color: #000;
        }
        .snn-cc-sidebar-list {
            padding: 10px;
        }
        .snn-cc-sidebar-item {
            padding: 12px;
            margin-bottom: 8px;
            background: #f9f9f9;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.2s;
            border-left: 3px solid <?php echo esc_attr($marker_color); ?>;
        }
        .snn-cc-sidebar-item:hover {
            background: #f0f0f0;
        }
        .snn-cc-sidebar-item-user {
            font-weight: bold;
            font-size: 13px;
            color: #333;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
        }
        .snn-cc-sidebar-item-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: <?php echo esc_attr($marker_color); ?>;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            margin-right: 8px;
            font-weight: bold;
        }
        .snn-cc-sidebar-item-text {
            font-size: 12px;
            color: #666;
            line-height: 1.4;
            margin-bottom: 4px;
        }
        .snn-cc-sidebar-item-meta {
            font-size: 11px;
            color: #999;
        }
        .snn-cc-sidebar-item-replies {
            margin-left: 32px;
            margin-top: 8px;
            padding-left: 10px;
            border-left: 2px solid #e0e0e0;
        }
        .snn-cc-sidebar-empty {
            padding: 30px;
            text-align: center;
            color: #999;
            font-size: 14px;
        }

        /* Comment Popup */
        .snn-cc-popup {
            position: absolute;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            z-index: 1000000;
            min-width: 320px;
            max-width: 420px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, sans-serif;
        }
        .snn-cc-popup-header {
            padding: 12px 15px;
            background: #f5f5f5;
            border-bottom: 1px solid #ddd;
            border-radius: 6px 6px 0 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .snn-cc-popup-user {
            display: flex;
            align-items: center;
            font-size: 13px;
            font-weight: 600;
            color: #333;
        }
        .snn-cc-popup-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: <?php echo esc_attr($marker_color); ?>;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            margin-right: 8px;
            font-weight: bold;
        }
        .snn-cc-popup-close {
            background: none;
            border: none;
            font-size: 20px;
            color: #666;
            cursor: pointer;
            padding: 0;
            width: 24px;
            height: 24px;
            line-height: 1;
        }
        .snn-cc-popup-close:hover {
            color: #000;
        }
        .snn-cc-popup-body {
            padding: 15px;
            max-height: 400px;
            overflow-y: auto;
        }
        .snn-cc-popup-comment {
            margin-bottom: 12px;
        }
        .snn-cc-popup-comment-header {
            display: flex;
            align-items: center;
            margin-bottom: 6px;
            font-size: 12px;
        }
        .snn-cc-popup-comment-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: <?php echo esc_attr($marker_color); ?>;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            margin-right: 8px;
            font-weight: bold;
        }
        .snn-cc-popup-comment-user {
            font-weight: 600;
            color: #333;
            margin-right: 8px;
        }
        .snn-cc-popup-comment-time {
            color: #999;
            font-size: 11px;
        }
        .snn-cc-popup-comment-text {
            padding: 10px;
            background: #f9f9f9;
            border-radius: 4px;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-size: 13px;
            line-height: 1.5;
            color: #333;
            margin-bottom: 8px;
        }
        .snn-cc-popup-comment-actions {
            display: flex;
            gap: 10px;
            font-size: 12px;
        }
        .snn-cc-popup-comment-action {
            background: none;
            border: none;
            color: #2271b1;
            cursor: pointer;
            padding: 0;
            font-size: 12px;
        }
        .snn-cc-popup-comment-action:hover {
            text-decoration: underline;
        }
        .snn-cc-popup-replies {
            margin-left: 32px;
            margin-top: 10px;
            padding-left: 12px;
            border-left: 2px solid #e0e0e0;
        }
        .snn-cc-popup-reply {
            margin-bottom: 10px;
        }
        .snn-cc-popup-form {
            margin-top: 12px;
        }
        .snn-cc-popup-form textarea {
            width: 100%;
            min-height: 70px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: inherit;
            font-size: 13px;
            resize: vertical;
            margin-bottom: 10px;
            box-sizing: border-box;
        }
        .snn-cc-popup-form textarea:focus {
            outline: none;
            border-color: <?php echo esc_attr($marker_color); ?>;
        }
        .snn-cc-popup-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            padding: 12px 15px;
            background: #f9f9f9;
            border-top: 1px solid #ddd;
            border-radius: 0 0 6px 6px;
        }
        .snn-cc-popup-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .snn-cc-popup-btn-primary {
            background: <?php echo esc_attr($marker_color); ?>;
            color: #fff;
        }
        .snn-cc-popup-btn-primary:hover {
            opacity: 0.9;
        }
        .snn-cc-popup-btn-secondary {
            background: #f0f0f0;
            color: #333;
        }
        .snn-cc-popup-btn-secondary:hover {
            background: #e0e0e0;
        }
        .snn-cc-popup-btn-danger {
            background: #d63638;
            color: #fff;
        }
        .snn-cc-popup-btn-danger:hover {
            background: #b32d2e;
        }

        /* Reply Form in Popup */
        .snn-cc-reply-form {
            background: #f5f5f5;
            padding: 12px;
            border-radius: 4px;
            margin-top: 10px;
        }

        /* Loading Spinner */
        .snn-cc-loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid <?php echo esc_attr($marker_color); ?>;
            border-radius: 50%;
            animation: snn-cc-spin 0.8s linear infinite;
        }
        @keyframes snn-cc-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>

    <script>
    jQuery(document).ready(function($) {
        let clickMode = false;
        let comments = [];
        let sidebarOpen = false;

        const markerColor = '<?php echo esc_js($marker_color); ?>';
        const markerStyle = '<?php echo esc_js($marker_style); ?>';
        const allowReplies = <?php echo $allow_replies ? 'true' : 'false'; ?>;
        const currentUserId = <?php echo $user_id; ?>;
        const ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
        const nonce = '<?php echo wp_create_nonce('snn_cc_nonce'); ?>';
        const guestCommenting = <?php echo $guest_commenting ? 'true' : 'false'; ?>;

        // Initialize
        init();

        function init() {
            createSidebar();
            loadComments();
            bindEvents();
        }

        // Create sidebar HTML
        function createSidebar() {
            const sidebar = $('<div class="snn-cc-sidebar">' +
                '<div class="snn-cc-sidebar-header">' +
                    '<h3>Comments on this page</h3>' +
                    '<button class="snn-cc-sidebar-close">&times;</button>' +
                '</div>' +
                '<div class="snn-cc-sidebar-list"></div>' +
            '</div>');
            $('body').append(sidebar);
        }

        // Bind events
        function bindEvents() {
            // Toggle add comment mode
            $('#wp-admin-bar-snn-cc-add-btn').on('click', function(e) {
                e.preventDefault();
                clickMode = !clickMode;
                $(this).toggleClass('active', clickMode);
                $('body').toggleClass('snn-cc-click-mode', clickMode);
            });

            // Toggle sidebar
            $('#wp-admin-bar-snn-cc-sidebar-btn, .snn-cc-sidebar-close').on('click', function(e) {
                e.preventDefault();
                sidebarOpen = !sidebarOpen;
                $('.snn-cc-sidebar').toggleClass('active', sidebarOpen);
                $('#wp-admin-bar-snn-cc-sidebar-btn').toggleClass('active', sidebarOpen);
            });

            // Handle click to add comment
            $('body').on('click', function(e) {
                if (!clickMode) return;

                // Ignore clicks on admin bar, markers, popups, and sidebar
                if ($(e.target).closest('#wpadminbar, .snn-cc-marker, .snn-cc-popup, .snn-cc-sidebar').length) {
                    return;
                }

                e.preventDefault();
                e.stopPropagation();

                clickMode = false;
                $('#wp-admin-bar-snn-cc-add-btn').removeClass('active');
                $('body').removeClass('snn-cc-click-mode');

                const x = e.pageX;
                const y = e.pageY;

                showAddCommentPopup(x, y);
            });

            // Click on marker
            $('body').on('click', '.snn-cc-marker', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const commentId = $(this).data('comment-id');
                const comment = findCommentById(commentId);

                if (comment) {
                    const offset = $(this).offset();
                    showCommentPopup(comment, offset.left, offset.top);
                }
            });

            // Click on sidebar item
            $('body').on('click', '.snn-cc-sidebar-item', function(e) {
                if ($(e.target).is('button')) return;

                const commentId = $(this).data('comment-id');
                const marker = $('.snn-cc-marker[data-comment-id="' + commentId + '"]');

                if (marker.length) {
                    // Scroll to marker
                    $('html, body').animate({
                        scrollTop: marker.offset().top - 100
                    }, 500);

                    // Highlight marker
                    marker.css('transform', 'scale(1.3)');
                    setTimeout(() => marker.css('transform', ''), 500);

                    // Show popup
                    const comment = findCommentById(commentId);
                    if (comment) {
                        showCommentPopup(comment, marker.offset().left, marker.offset().top);
                    }
                }
            });

            // Close popup
            $('body').on('click', '.snn-cc-popup-close, .snn-cc-popup-btn-cancel', function() {
                $('.snn-cc-popup').remove();
            });

            // Save new comment
            $('body').on('click', '.snn-cc-popup-btn-save', function() {
                const popup = $(this).closest('.snn-cc-popup');
                const textarea = popup.find('textarea');
                const comment = textarea.val().trim();
                const pos = popup.data('pos');

                if (!comment) {
                    alert('Please enter a comment');
                    return;
                }

                saveComment(comment, pos.x, pos.y, 0, popup);
            });

            // Save reply
            $('body').on('click', '.snn-cc-popup-btn-reply', function() {
                const form = $(this).closest('.snn-cc-reply-form');
                const textarea = form.find('textarea');
                const comment = textarea.val().trim();
                const parentId = form.data('parent-id');
                const popup = $(this).closest('.snn-cc-popup');
                const parentComment = findCommentById(parentId);

                if (!comment) {
                    alert('Please enter a reply');
                    return;
                }

                saveComment(comment, parentComment.pos_x, parentComment.pos_y, parentId, form);
            });

            // Edit comment
            $('body').on('click', '.snn-cc-popup-btn-edit', function() {
                const commentId = $(this).data('comment-id');
                const comment = findCommentById(commentId);
                const popup = $(this).closest('.snn-cc-popup');

                popup.find('.snn-cc-popup-body').html(
                    '<textarea class="snn-cc-popup-textarea">' + $('<div>').text(comment.comment).html() + '</textarea>'
                );
                popup.find('.snn-cc-popup-actions').html(
                    '<button class="snn-cc-popup-btn snn-cc-popup-btn-primary snn-cc-popup-btn-update" data-comment-id="' + commentId + '">Update</button>' +
                    '<button class="snn-cc-popup-btn snn-cc-popup-btn-secondary snn-cc-popup-btn-cancel">Cancel</button>'
                );
            });

            // Update comment
            $('body').on('click', '.snn-cc-popup-btn-update', function() {
                const commentId = $(this).data('comment-id');
                const popup = $(this).closest('.snn-cc-popup');
                const newComment = popup.find('textarea').val().trim();

                if (!newComment) {
                    alert('Please enter a comment');
                    return;
                }

                updateComment(commentId, newComment, popup);
            });

            // Delete comment
            $('body').on('click', '.snn-cc-popup-btn-delete', function() {
                if (!confirm('Are you sure you want to delete this comment? This will also delete all replies.')) return;

                const commentId = $(this).data('comment-id');
                const popup = $(this).closest('.snn-cc-popup');

                deleteComment(commentId, popup);
            });

            // Show reply form
            $('body').on('click', '.snn-cc-popup-comment-action-reply', function() {
                const commentId = $(this).data('comment-id');
                const commentDiv = $(this).closest('.snn-cc-popup-comment');

                // Remove existing reply forms
                $('.snn-cc-reply-form').remove();

                const replyForm = $('<div class="snn-cc-reply-form" data-parent-id="' + commentId + '">' +
                    '<textarea placeholder="Write a reply..." rows="3"></textarea>' +
                    '<div class="snn-cc-popup-actions">' +
                        '<button class="snn-cc-popup-btn snn-cc-popup-btn-primary snn-cc-popup-btn-reply">Reply</button>' +
                        '<button class="snn-cc-popup-btn snn-cc-popup-btn-secondary snn-cc-popup-btn-cancel-reply">Cancel</button>' +
                    '</div>' +
                '</div>');

                commentDiv.append(replyForm);
                replyForm.find('textarea').focus();
            });

            // Cancel reply
            $('body').on('click', '.snn-cc-popup-btn-cancel-reply', function() {
                $(this).closest('.snn-cc-reply-form').remove();
            });

            // Copy Share Link
            $('#wp-admin-bar-snn-cc-share-btn a').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                generateShareLink();
            });
        }

        // Load comments from server
        function loadComments() {
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snn_cc_get_comments',
                    page_url: window.location.href,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        comments = response.data;
                        displayMarkers();
                        updateSidebar();
                    }
                }
            });
        }

        // Display markers on page
        function displayMarkers() {
            $('.snn-cc-marker').remove();

            // Only show parent comments as markers
            const parentComments = comments.filter(c => c.parent_id == 0);

            parentComments.forEach(function(comment, index) {
                const hasReplies = comments.some(c => c.parent_id == comment.id);
                let markerContent = '';

                if (markerStyle === 'initials') {
                    markerContent = getInitials(comment.user_name);
                } else if (markerStyle === 'number') {
                    markerContent = index + 1;
                } else {
                    markerContent = '💬';
                }

                const marker = $('<div class="snn-cc-marker' + (hasReplies ? ' has-replies' : '') + '">' + markerContent + '</div>');
                marker.css({
                    left: comment.pos_x,
                    top: comment.pos_y,
                    background: getUserColor(comment.user_id)
                });
                marker.data('comment-id', comment.id);
                marker.attr('data-comment-id', comment.id);
                $('body').append(marker);
            });
        }

        // Update sidebar
        function updateSidebar() {
            const list = $('.snn-cc-sidebar-list');
            list.empty();

            const parentComments = comments.filter(c => c.parent_id == 0);

            if (parentComments.length === 0) {
                list.html('<div class="snn-cc-sidebar-empty">No comments on this page yet</div>');
                return;
            }

            parentComments.forEach(function(comment) {
                const replies = comments.filter(c => c.parent_id == comment.id);
                const replyCount = replies.length;

                const item = $('<div class="snn-cc-sidebar-item" data-comment-id="' + comment.id + '">' +
                    '<div class="snn-cc-sidebar-item-user">' +
                        '<span class="snn-cc-sidebar-item-avatar" style="background: ' + getUserColor(comment.user_id) + '">' + getInitials(comment.user_name) + '</span>' +
                        comment.user_name +
                    '</div>' +
                    '<div class="snn-cc-sidebar-item-text">' + escapeHtml(comment.comment.substring(0, 80)) + (comment.comment.length > 80 ? '...' : '') + '</div>' +
                    '<div class="snn-cc-sidebar-item-meta">' +
                        formatDate(comment.created_at) +
                        (replyCount > 0 ? ' • ' + replyCount + ' ' + (replyCount === 1 ? 'reply' : 'replies') : '') +
                    '</div>' +
                '</div>');

                list.append(item);
            });
        }

        // Show add comment popup
        function showAddCommentPopup(x, y) {
            $('.snn-cc-popup').remove();

            const popup = $('<div class="snn-cc-popup">' +
                '<div class="snn-cc-popup-header">' +
                    '<div class="snn-cc-popup-user">' +
                        '<span class="snn-cc-popup-avatar"><?php echo esc_js($user_initials); ?></span>' +
                        '<?php echo esc_js($user_name); ?>' +
                    '</div>' +
                    '<button class="snn-cc-popup-close">&times;</button>' +
                '</div>' +
                '<div class="snn-cc-popup-body">' +
                    '<textarea placeholder="Add your comment..." rows="4"></textarea>' +
                '</div>' +
                '<div class="snn-cc-popup-actions">' +
                    '<button class="snn-cc-popup-btn snn-cc-popup-btn-primary snn-cc-popup-btn-save">Save</button>' +
                    '<button class="snn-cc-popup-btn snn-cc-popup-btn-secondary snn-cc-popup-btn-cancel">Cancel</button>' +
                '</div>' +
            '</div>');

            positionPopup(popup, x, y);
            $('body').append(popup);
            popup.find('textarea').focus();
            popup.data('pos', {x: x, y: y});
        }

        // Show comment popup
        function showCommentPopup(comment, x, y) {
            $('.snn-cc-popup').remove();

            const replies = comments.filter(c => c.parent_id == comment.id);
            const canEdit = comment.user_id == currentUserId;

            let popupHTML = '<div class="snn-cc-popup">' +
                '<div class="snn-cc-popup-header">' +
                    '<div class="snn-cc-popup-user">' +
                        '<span class="snn-cc-popup-avatar" style="background: ' + getUserColor(comment.user_id) + '">' + getInitials(comment.user_name) + '</span>' +
                        comment.user_name +
                    '</div>' +
                    '<button class="snn-cc-popup-close">&times;</button>' +
                '</div>' +
                '<div class="snn-cc-popup-body">';

            // Main comment
            popupHTML += renderComment(comment, canEdit);

            // Replies
            if (replies.length > 0) {
                popupHTML += '<div class="snn-cc-popup-replies">';
                replies.forEach(function(reply) {
                    const canEditReply = reply.user_id == currentUserId;
                    popupHTML += renderComment(reply, canEditReply, true);
                });
                popupHTML += '</div>';
            }

            popupHTML += '</div>';

            // Actions
            if (canEdit) {
                popupHTML += '<div class="snn-cc-popup-actions">' +
                    '<button class="snn-cc-popup-btn snn-cc-popup-btn-primary snn-cc-popup-btn-edit" data-comment-id="' + comment.id + '">Edit</button>' +
                    '<button class="snn-cc-popup-btn snn-cc-popup-btn-danger snn-cc-popup-btn-delete" data-comment-id="' + comment.id + '">Delete</button>' +
                '</div>';
            }

            popupHTML += '</div>';

            const popup = $(popupHTML);
            positionPopup(popup, x, y);
            $('body').append(popup);
        }

        // Render single comment
        function renderComment(comment, canEdit, isReply) {
            let html = '<div class="snn-cc-popup-comment' + (isReply ? ' snn-cc-popup-reply' : '') + '">' +
                '<div class="snn-cc-popup-comment-header">' +
                    '<span class="snn-cc-popup-comment-avatar" style="background: ' + getUserColor(comment.user_id) + '">' + getInitials(comment.user_name) + '</span>' +
                    '<span class="snn-cc-popup-comment-user">' + comment.user_name + '</span>' +
                    '<span class="snn-cc-popup-comment-time">' + formatDate(comment.created_at) + '</span>' +
                '</div>' +
                '<div class="snn-cc-popup-comment-text">' + escapeHtml(comment.comment) + '</div>' +
                '<div class="snn-cc-popup-comment-actions">';

            if (allowReplies && !isReply) {
                html += '<button class="snn-cc-popup-comment-action snn-cc-popup-comment-action-reply" data-comment-id="' + comment.id + '">Reply</button>';
            }

            if (canEdit) {
                html += '<button class="snn-cc-popup-comment-action snn-cc-popup-btn-edit" data-comment-id="' + comment.id + '">Edit</button>';
                html += '<button class="snn-cc-popup-comment-action snn-cc-popup-btn-delete" data-comment-id="' + comment.id + '">Delete</button>';
            }

            html += '</div></div>';

            return html;
        }

        // Position popup
        function positionPopup(popup, x, y) {
            popup.css({
                left: x + 'px',
                top: (y + 15) + 'px'
            });

            // Adjust if off-screen
            setTimeout(function() {
                const popupWidth = popup.outerWidth();
                const popupHeight = popup.outerHeight();
                const windowWidth = $(window).width();
                const windowHeight = $(window).height();
                const scrollTop = $(window).scrollTop();

                if (x + popupWidth > windowWidth) {
                    popup.css('left', (x - popupWidth - 15) + 'px');
                }

                if (y + popupHeight > scrollTop + windowHeight) {
                    popup.css('top', (y - popupHeight - 50) + 'px');
                }
            }, 10);
        }

        // Save comment
        function saveComment(comment, x, y, parentId, element) {
            const btn = element.find('.snn-cc-popup-btn-primary, .snn-cc-popup-btn-reply');
            const originalText = btn.text();
            btn.html('<span class="snn-cc-loading"></span>').prop('disabled', true);

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snn_cc_save_comment',
                    comment: comment,
                    pos_x: x + 'px',
                    pos_y: y + 'px',
                    page_url: window.location.href,
                    parent_id: parentId,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('.snn-cc-popup').remove();
                        loadComments();
                    } else {
                        alert('Error saving comment: ' + (response.data || 'Unknown error'));
                        btn.text(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Error saving comment');
                    btn.text(originalText).prop('disabled', false);
                }
            });
        }

        // Update comment
        function updateComment(commentId, newComment, popup) {
            const btn = popup.find('.snn-cc-popup-btn-update');
            const originalText = btn.text();
            btn.html('<span class="snn-cc-loading"></span>').prop('disabled', true);

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snn_cc_update_comment',
                    comment_id: commentId,
                    comment: newComment,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        popup.remove();
                        loadComments();
                    } else {
                        alert('Error updating comment: ' + (response.data || 'Unknown error'));
                        btn.text(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Error updating comment');
                    btn.text(originalText).prop('disabled', false);
                }
            });
        }

        // Delete comment
        function deleteComment(commentId, popup) {
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snn_cc_delete_comment',
                    comment_id: commentId,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        popup.remove();
                        loadComments();
                    } else {
                        alert('Error deleting comment: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Error deleting comment');
                }
            });
        }

        // Generate and copy share link
        function generateShareLink() {
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snn_cc_generate_share_link',
                    page_url: window.location.href,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success && response.data.url) {
                        copyToClipboard(response.data.url);
                        showNotification('Share link copied to clipboard!');
                    } else {
                        alert('Error generating share link: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Error generating share link');
                }
            });
        }

        // Copy to clipboard
        function copyToClipboard(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text);
            } else {
                // Fallback for older browsers
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
            }
        }

        // Show notification
        function showNotification(message) {
            const notification = $('<div class="snn-cc-notification">' + message + '</div>');
            $('body').append(notification);

            setTimeout(function() {
                notification.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        }

        // Helper functions
        function findCommentById(id) {
            return comments.find(c => c.id == id);
        }

        function getInitials(name) {
            if (!name) return '?';
            const parts = name.trim().split(' ');
            if (parts.length === 1) {
                return parts[0].substring(0, 2).toUpperCase();
            }
            return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        }

        function getUserColor(userId) {
            const colors = [
                '#0073aa', '#d63638', '#00a32a', '#f6a306', '#8e44ad',
                '#e74c3c', '#3498db', '#2ecc71', '#f39c12', '#9b59b6',
                '#1abc9c', '#34495e', '#e67e22', '#c0392b', '#16a085'
            ];
            return colors[userId % colors.length];
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            const now = new Date();
            const diff = now - date;
            const seconds = Math.floor(diff / 1000);
            const minutes = Math.floor(seconds / 60);
            const hours = Math.floor(minutes / 60);
            const days = Math.floor(hours / 24);

            if (days > 7) {
                return date.toLocaleDateString();
            } else if (days > 0) {
                return days + 'd ago';
            } else if (hours > 0) {
                return hours + 'h ago';
            } else if (minutes > 0) {
                return minutes + 'm ago';
            } else {
                return 'Just now';
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    });
    </script>
    <?php
    }); // Close wp_footer anonymous function
}
// Enqueue scripts on wp_enqueue_scripts hook to ensure jQuery loads properly
add_action('wp_enqueue_scripts', 'snn_cc_enqueue_scripts', 999);

/**
 * Get user initials
 */
function snn_cc_get_user_initials($name) {
    if (empty($name)) return '?';

    $parts = explode(' ', trim($name));
    if (count($parts) === 1) {
        return strtoupper(substr($parts[0], 0, 2));
    }

    return strtoupper($parts[0][0] . $parts[count($parts) - 1][0]);
}

/**
 * AJAX: Generate share link
 */
function snn_cc_generate_share_link() {
    check_ajax_referer('snn_cc_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error('Not logged in');
        return;
    }

    if (!get_option('snn_cc_guest_commenting', '0')) {
        wp_send_json_error('Guest commenting is not enabled');
        return;
    }

    $page_url = esc_url_raw($_POST['page_url']);
    $page_url = strtok($page_url, '?'); // Remove existing query params

    // Generate token
    $token = snn_cc_generate_guest_token($page_url);

    // Build share URL
    $share_url = add_query_arg('snn_guest_token', $token, $page_url);

    wp_send_json_success(array('url' => $share_url));
}
add_action('wp_ajax_snn_cc_generate_share_link', 'snn_cc_generate_share_link');

/**
 * AJAX: Get comments for current page
 */
function snn_cc_get_comments() {
    check_ajax_referer('snn_cc_nonce', 'nonce');

    // Allow guests if they have valid token
    $is_guest = snn_cc_is_guest_user();
    if (!is_user_logged_in() && !$is_guest) {
        wp_send_json_error('Not logged in');
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'snn_client_comments';
    $page_url = esc_url_raw($_POST['page_url']);

    $comments = $wpdb->get_results($wpdb->prepare(
        "SELECT c.*,
         CASE
            WHEN c.user_id = -1 THEN 'Guest'
            ELSE u.display_name
         END as user_name
         FROM $table_name c
         LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID
         WHERE c.page_url = %s AND c.status = 'active'
         ORDER BY c.parent_id ASC, c.created_at ASC",
        $page_url
    ), ARRAY_A);

    wp_send_json_success($comments);
}
add_action('wp_ajax_snn_cc_get_comments', 'snn_cc_get_comments');
add_action('wp_ajax_nopriv_snn_cc_get_comments', 'snn_cc_get_comments');

/**
 * AJAX: Save new comment
 */
function snn_cc_save_comment() {
    check_ajax_referer('snn_cc_nonce', 'nonce');

    // Allow guests if they have valid token
    $is_guest = snn_cc_is_guest_user();
    if (!is_user_logged_in() && !$is_guest) {
        wp_send_json_error('Not logged in');
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'snn_client_comments';

    $comment = sanitize_textarea_field($_POST['comment']);
    $pos_x = sanitize_text_field($_POST['pos_x']);
    $pos_y = sanitize_text_field($_POST['pos_y']);
    $page_url = esc_url_raw($_POST['page_url']);
    $parent_id = isset($_POST['parent_id']) ? intval($_POST['parent_id']) : 0;

    // Use -1 for guest users
    $user_id = $is_guest ? -1 : get_current_user_id();

    if (empty($comment)) {
        wp_send_json_error('Comment is required');
        return;
    }

    $result = $wpdb->insert(
        $table_name,
        array(
            'parent_id' => $parent_id,
            'user_id' => $user_id,
            'page_url' => $page_url,
            'pos_x' => $pos_x,
            'pos_y' => $pos_y,
            'comment' => $comment,
            'status' => 'active'
        ),
        array('%d', '%d', '%s', '%s', '%s', '%s', '%s')
    );

    if ($result) {
        wp_send_json_success(array('id' => $wpdb->insert_id));
    } else {
        wp_send_json_error('Failed to save comment');
    }
}
add_action('wp_ajax_snn_cc_save_comment', 'snn_cc_save_comment');
add_action('wp_ajax_nopriv_snn_cc_save_comment', 'snn_cc_save_comment');

/**
 * AJAX: Update comment
 */
function snn_cc_update_comment() {
    check_ajax_referer('snn_cc_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error('Not logged in');
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'snn_client_comments';

    $comment_id = intval($_POST['comment_id']);
    $comment = sanitize_textarea_field($_POST['comment']);
    $user_id = get_current_user_id();

    if (empty($comment)) {
        wp_send_json_error('Comment is required');
        return;
    }

    // Check ownership
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d AND user_id = %d",
        $comment_id,
        $user_id
    ));

    if (!$existing) {
        wp_send_json_error('You can only edit your own comments');
        return;
    }

    $result = $wpdb->update(
        $table_name,
        array('comment' => $comment),
        array('id' => $comment_id),
        array('%s'),
        array('%d')
    );

    if ($result !== false) {
        wp_send_json_success();
    } else {
        wp_send_json_error('Failed to update comment');
    }
}
add_action('wp_ajax_snn_cc_update_comment', 'snn_cc_update_comment');
add_action('wp_ajax_nopriv_snn_cc_update_comment', 'snn_cc_update_comment');

/**
 * AJAX: Delete comment
 */
function snn_cc_delete_comment() {
    check_ajax_referer('snn_cc_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error('Not logged in');
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'snn_client_comments';

    $comment_id = intval($_POST['comment_id']);
    $user_id = get_current_user_id();

    // Check ownership
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d AND user_id = %d",
        $comment_id,
        $user_id
    ));

    if (!$existing) {
        wp_send_json_error('You can only delete your own comments');
        return;
    }

    // Delete comment and all its replies
    $wpdb->delete($table_name, array('id' => $comment_id), array('%d'));
    $wpdb->delete($table_name, array('parent_id' => $comment_id), array('%d'));

    wp_send_json_success();
}
add_action('wp_ajax_snn_cc_delete_comment', 'snn_cc_delete_comment');
add_action('wp_ajax_nopriv_snn_cc_delete_comment', 'snn_cc_delete_comment');
