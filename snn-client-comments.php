<?php   
/**
 * Plugin Name: SNN Client Comments 
 * Plugin URI: https://github.com/sinanisler/snn-client-comments
 * Description: Visual commenting system for WordPress - Add comments directly on any page location with multi-user collaboration support
 * Version: 0.6
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
define('SNN_CC_DB_VERSION', '1.2'); // Database schema version
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
        guest_token varchar(64) DEFAULT NULL,
        page_url varchar(500) NOT NULL,
        pos_x varchar(20) NOT NULL,
        pos_y varchar(20) NOT NULL,
        comment text NOT NULL,
        status varchar(20) DEFAULT 'active',
        checked_by bigint(20) DEFAULT NULL,
        checked_at datetime DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY parent_id (parent_id),
        KEY user_id (user_id),
        KEY guest_token (guest_token),
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
    
    // Save database version
    update_option('snn_cc_db_version', SNN_CC_DB_VERSION);
}
register_activation_hook(__FILE__, 'snn_cc_create_tables');

/**
 * Check and upgrade database schema if needed
 */
function snn_cc_check_db_upgrade() {
    $current_db_version = get_option('snn_cc_db_version', '0');
    
    // If DB version doesn't match, run upgrade
    if (version_compare($current_db_version, SNN_CC_DB_VERSION, '<')) {
        snn_cc_upgrade_database($current_db_version);
    }
}
add_action('plugins_loaded', 'snn_cc_check_db_upgrade');

/**
 * Upgrade database schema
 */
function snn_cc_upgrade_database($from_version) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'snn_client_comments';
    
    // Check if guest_token column exists
    $column_exists = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = %s 
         AND TABLE_NAME = %s 
         AND COLUMN_NAME = 'guest_token'",
        DB_NAME,
        $table_name
    ));
    
    // Add guest_token column if it doesn't exist
    if (empty($column_exists)) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN guest_token varchar(64) DEFAULT NULL AFTER user_id");
        $wpdb->query("ALTER TABLE $table_name ADD KEY guest_token (guest_token)");
    }

    // Check if checked_by column exists
    $checked_col_exists = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = %s 
         AND TABLE_NAME = %s 
         AND COLUMN_NAME = 'checked_by'",
        DB_NAME,
        $table_name
    ));

    // Add checked_by / checked_at columns if they don't exist
    if (empty($checked_col_exists)) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN checked_by bigint(20) DEFAULT NULL");
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN checked_at datetime DEFAULT NULL");
    }
    
    // Re-run table creation to ensure all tables exist with latest schema
    snn_cc_create_tables();
    
    // Update DB version
    update_option('snn_cc_db_version', SNN_CC_DB_VERSION);
}

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

    // Handle regenerate token
    if (isset($_POST['snn_cc_regenerate_token']) && check_admin_referer('snn_cc_settings_nonce')) {
        snn_cc_regenerate_guest_token();
        echo '<div class="notice notice-success"><p>Guest share link has been regenerated!</p></div>';
    }

    // Handle bulk delete comments
    if (isset($_POST['snn_cc_bulk_delete']) && check_admin_referer('snn_cc_settings_nonce')) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'snn_client_comments';

        if (isset($_POST['delete_comment_ids']) && is_array($_POST['delete_comment_ids'])) {
            $deleted_count = 0;
            foreach ($_POST['delete_comment_ids'] as $comment_id) {
                $comment_id = intval($comment_id);
                // Delete comment and its replies
                $wpdb->delete($table_name, array('id' => $comment_id), array('%d'));
                $wpdb->delete($table_name, array('parent_id' => $comment_id), array('%d'));
                $deleted_count++;
            }
            echo '<div class="notice notice-success"><p>' . $deleted_count . ' comment(s) deleted successfully!</p></div>';
        }
    }

    // Handle clear all comments
    if (isset($_POST['snn_cc_clear_all']) && check_admin_referer('snn_cc_settings_nonce')) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'snn_client_comments';
        $wpdb->query("DELETE FROM $table_name");
        echo '<div class="notice notice-success"><p>All comments have been cleared!</p></div>';
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

        <h2>All Comments</h2>
        <?php
        global $wpdb;
        $table_name = $wpdb->prefix . 'snn_client_comments';

        // Get all comments with page info (only parent comments for cleaner display)
        $all_comments = $wpdb->get_results(
            "SELECT c.*,
             CASE
                WHEN c.user_id = -1 THEN 'Guest'
                ELSE u.display_name
             END as user_name,
             (SELECT COUNT(*) FROM $table_name WHERE parent_id = c.id) as reply_count
             FROM $table_name c
             LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID
             WHERE c.parent_id = 0 AND c.status = 'active'
             ORDER BY c.created_at DESC"
        );

        if (!empty($all_comments)): ?>
        <form method="post" action="" id="snn-cc-comments-form">
            <?php wp_nonce_field('snn_cc_settings_nonce'); ?>

            <div style="margin-bottom: 15px;">
                <button type="submit" name="snn_cc_bulk_delete" class="button" onclick="return confirm('Are you sure you want to delete the selected comments? This will also delete all replies.');">Delete Selected</button>
                <button type="submit" name="snn_cc_clear_all" class="button button-link-delete" style="margin-left: 10px;" onclick="return confirm('Are you sure you want to delete ALL comments? This action cannot be undone!');">Clear All Comments</button>
                <label style="margin-left: 15px;">
                    <input type="checkbox" id="snn-cc-select-all"> Select All
                </label>
            </div>

            <table class="widefat" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" id="snn-cc-select-all-header"></th>
                        <th style="width: 15%;">User</th>
                        <th style="width: 40%;">Comment</th>
                        <th style="width: 25%;">Page</th>
                        <th style="width: 10%;">Replies</th>
                        <th style="width: 10%;">Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_comments as $comment): ?>
                    <tr>
                        <td>
                            <input type="checkbox" name="delete_comment_ids[]" value="<?php echo intval($comment->id); ?>" class="snn-cc-comment-checkbox">
                        </td>
                        <td>
                            <strong><?php echo esc_html($comment->user_name); ?></strong>
                        </td>
                        <td>
                            <?php
                            $comment_text = esc_html($comment->comment);
                            echo strlen($comment_text) > 100 ? substr($comment_text, 0, 100) . '...' : $comment_text;
                            ?>
                        </td>
                        <td>
                            <?php
                            $page_title = 'Unknown Page';
                            $page_id = url_to_postid($comment->page_url);

                            if ($page_id) {
                                $page_title = get_the_title($page_id);
                            } elseif ($comment->page_url === home_url('/')) {
                                $page_title = 'Home Page';
                            } else {
                                // Try to extract a readable name from URL
                                $path = parse_url($comment->page_url, PHP_URL_PATH);
                                $page_title = ucwords(str_replace(array('/', '-', '_'), ' ', trim($path, '/')));
                                if (empty($page_title)) $page_title = 'Home Page';
                            }
                            ?>
                            <a href="<?php echo esc_url($comment->page_url); ?>" target="_blank" title="<?php echo esc_attr($comment->page_url); ?>">
                                <?php echo esc_html($page_title); ?>
                                <span class="dashicons dashicons-external" style="font-size: 14px; text-decoration: none;"></span>
                            </a>
                        </td>
                        <td>
                            <?php echo intval($comment->reply_count); ?>
                        </td>
                        <td>
                            <?php echo esc_html(mysql2date('M j, Y', $comment->created_at)); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>

        <script>
        jQuery(document).ready(function($) {
            // Select all checkboxes functionality
            $('#snn-cc-select-all, #snn-cc-select-all-header').on('change', function() {
                var isChecked = $(this).prop('checked');
                $('.snn-cc-comment-checkbox').prop('checked', isChecked);
                $('#snn-cc-select-all, #snn-cc-select-all-header').prop('checked', isChecked);
            });

            // Update select all when individual checkboxes change
            $('.snn-cc-comment-checkbox').on('change', function() {
                var totalCheckboxes = $('.snn-cc-comment-checkbox').length;
                var checkedCheckboxes = $('.snn-cc-comment-checkbox:checked').length;
                $('#snn-cc-select-all, #snn-cc-select-all-header').prop('checked', totalCheckboxes === checkedCheckboxes);
            });

            // Validate before bulk delete
            $('#snn-cc-comments-form').on('submit', function(e) {
                if ($(e.originalEvent.submitter).attr('name') === 'snn_cc_bulk_delete') {
                    if ($('.snn-cc-comment-checkbox:checked').length === 0) {
                        e.preventDefault();
                        alert('Please select at least one comment to delete.');
                        return false;
                    }
                }
            });
        });
        </script>

        <?php else: ?>
        <p style="color: #666;">No comments found yet.</p>
        <?php endif; ?>

        <hr>

        <?php if ($guest_commenting === '1'): ?>
        <h2>Guest Commenting Share Link</h2>
        <p>Share this link with your clients to allow them to comment anywhere on your site without logging in.</p>

        <?php
        $token = snn_cc_get_global_guest_token();
        $share_url = add_query_arg('snn_guest_token', $token, home_url());
        ?>

        <table class="form-table">
            <tr>
                <th scope="row">Share Link</th>
                <td>
                    <input type="text" id="snn-cc-share-url" value="<?php echo esc_attr($share_url); ?>" readonly style="width: 100%; max-width: 600px; font-family: monospace; background: #f5f5f5; padding: 8px;">
                    <button type="button" id="snn-cc-copy-link" class="button" style="margin-left: 10px;">Copy Link</button>
                    <p class="description">Clients can use this link to comment on any page of your site.</p>
                </td>
            </tr>
        </table>

        <form method="post" action="" style="margin-top: 10px;">
            <?php wp_nonce_field('snn_cc_settings_nonce'); ?>
            <p>
                <input type="submit" name="snn_cc_regenerate_token" class="button" value="Regenerate Share Link"
                    onclick="return confirm('This will invalidate the old link. Are you sure?');">
                <span class="description" style="margin-left: 10px;">Generate a new link (old link will stop working)</span>
            </p>
        </form>

        <script>
        jQuery(document).ready(function($) {
            $('#snn-cc-copy-link').on('click', function() {
                var input = document.getElementById('snn-cc-share-url');
                input.select();
                input.setSelectionRange(0, 99999);

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(input.value).then(function() {
                        $('#snn-cc-copy-link').text('Copied!');
                        setTimeout(function() {
                            $('#snn-cc-copy-link').text('Copy Link');
                        }, 2000);
                    });
                } else {
                    document.execCommand('copy');
                    $('#snn-cc-copy-link').text('Copied!');
                    setTimeout(function() {
                        $('#snn-cc-copy-link').text('Copy Link');
                    }, 2000);
                }
            });
        });
        </script>

        <hr>
        <?php endif; ?>

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
        'title' => '<span class="ab-icon dashicons dashicons-admin-comments"></span><span class="ab-label">CC</span>',
        'href'  => '#',
        'meta'  => array('class' => 'snn-cc-menu-parent')
    ));

    // Add comment button
    $wp_admin_bar->add_node(array(
        'id'    => 'snn-cc-add-btn',
        'parent' => 'snn-cc-menu',
        'title' => '+ Add Comment',
        'href'  => '#',
        'meta'  => array('class' => 'snn-cc-toggle-add')
    ));

    // Add sidebar toggle button
    $wp_admin_bar->add_node(array(
        'id'    => 'snn-cc-sidebar-btn',
        'parent' => 'snn-cc-menu',
        'title' => '☰ View Comments',
        'href'  => '#',
        'meta'  => array('class' => 'snn-cc-toggle-sidebar')
    ));

    // Add all site comments button
    $wp_admin_bar->add_node(array(
        'id'    => 'snn-cc-allsite-btn',
        'parent' => 'snn-cc-menu',
        'title' => '🌐 All Comments',
        'href'  => '#',
        'meta'  => array('class' => 'snn-cc-toggle-allsite')
    ));
}
add_action('admin_bar_menu', 'snn_cc_add_admin_bar_button', 999);

/**
 * Get or generate global guest access token
 */
function snn_cc_get_global_guest_token() {
    $token = get_option('snn_cc_global_guest_token');

    // Generate new token if it doesn't exist
    if (empty($token)) {
        $token = bin2hex(random_bytes(32));
        update_option('snn_cc_global_guest_token', $token);
    }

    return $token;
}

/**
 * Regenerate global guest access token
 */
function snn_cc_regenerate_guest_token() {
    $token = bin2hex(random_bytes(32));
    update_option('snn_cc_global_guest_token', $token);
    return $token;
}

/**
 * Validate guest token
 */
function snn_cc_validate_guest_token($token) {
    if (!get_option('snn_cc_guest_commenting', '0')) {
        return false;
    }

    $global_token = snn_cc_get_global_guest_token();
    return ($token === $global_token);
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

        if (snn_cc_validate_guest_token($token)) {
            // Store token in session
            if (!session_id()) {
                session_start();
            }
            $_SESSION['snn_guest_token'] = $token;
            return true;
        }
    } elseif (isset($_SESSION['snn_guest_token'])) {
        $token = $_SESSION['snn_guest_token'];
        return snn_cc_validate_guest_token($token);
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
    add_action('wp_footer', function() use ($marker_color, $marker_style, $allow_replies, $user_id, $user_name, $user_initials, $admin_bar_height, $guest_commenting, $is_guest) {
    ?>
    <style>
        /* Admin Bar Styles */
        #wpadminbar #wp-admin-bar-snn-cc-menu .ab-icon:before {
            content: "\f101";
            top: 2px;
        }
        #wpadminbar #wp-admin-bar-snn-cc-add-btn .dashicons,
        #wpadminbar #wp-admin-bar-snn-cc-sidebar-btn .dashicons {
            font-size: 16px;
            line-height: 1;
            vertical-align: middle;
            margin-right: 4px;
        }
        #wpadminbar #wp-admin-bar-snn-cc-add-btn.active,
        #wpadminbar #wp-admin-bar-snn-cc-sidebar-btn.active {
            background: <?php echo esc_attr($marker_color); ?> !important;
        }

        /* Fixed Guest Buttons (for users without admin bar) */
        <?php if ($is_guest): ?>
        .snn-cc-guest-controls {
            position: fixed;
            bottom: 20px;
            left: 20px;
            z-index: 999997;
            display: flex;
            gap: 10px;
        }
        .snn-cc-guest-btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: <?php echo esc_attr($marker_color); ?>;
            color: #fff;
            border: 3px solid #fff;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: transform 0.2s, box-shadow 0.2s;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, sans-serif;
        }
        .snn-cc-guest-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 15px rgba(0,0,0,0.4);
        }
        .snn-cc-guest-btn.active {
            background: #d63638;
        }
        .snn-cc-guest-btn .dashicons {
            font-size: 24px;
            width: 24px;
            height: 24px;
            line-height: 1;
        }
        <?php endif; ?>

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

        /* All Site Comments Panel */
        .snn-cc-allsite-panel {
            position: fixed;
            top: <?php echo $admin_bar_height; ?>px;
            right: -370px;
            width: 350px;
            height: calc(100vh - <?php echo $admin_bar_height; ?>px);
            background: #fff;
            box-shadow: -2px 0 10px rgba(0,0,0,0.1);
            z-index: 999998;
            transition: right 0.3s ease;
            overflow-y: auto;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, sans-serif;
        }
        .snn-cc-allsite-panel.active {
            right: 0;
        }
        .snn-cc-allsite-header {
            padding: 15px;
            background: #f5f5f5;
            border-bottom: 1px solid #ddd;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .snn-cc-allsite-header h3 {
            margin: 0;
            font-size: 16px;
            color: #333;
        }
        .snn-cc-allsite-close {
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
        .snn-cc-allsite-close:hover {
            color: #000;
        }
        .snn-cc-allsite-list {
            padding: 10px;
        }
        .snn-cc-allsite-page-group {
            margin-bottom: 14px;
        }
        .snn-cc-allsite-page-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #999;
            padding: 6px 4px 4px;
            border-bottom: 1px solid #eee;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .snn-cc-allsite-page-link {
            color: #999;
            text-decoration: none;
        }
        .snn-cc-allsite-page-link:hover {
            color: <?php echo esc_attr($marker_color); ?>;
            text-decoration: underline;
        }
        .snn-cc-allsite-current-badge {
            background: <?php echo esc_attr($marker_color); ?>;
            color: #fff;
            font-size: 9px;
            padding: 1px 5px;
            border-radius: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .snn-cc-allsite-item {
            padding: 10px 12px;
            margin-bottom: 6px;
            background: #f9f9f9;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.2s;
            border-left: 3px solid <?php echo esc_attr($marker_color); ?>;
        }
        .snn-cc-allsite-item:hover {
            background: #f0f0f0;
        }
        .snn-cc-allsite-item-user {
            font-weight: bold;
            font-size: 13px;
            color: #333;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
        }
        .snn-cc-allsite-item-text {
            font-size: 12px;
            color: #666;
            line-height: 1.4;
            margin-bottom: 4px;
        }
        .snn-cc-allsite-item-meta {
            font-size: 11px;
            color: #999;
        }
        .snn-cc-allsite-empty,
        .snn-cc-allsite-loading {
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

        /* Check Button & Badge */
        .snn-cc-check-btn {
            background: none;
            border: 1px solid #ccc;
            border-radius: 4px;
            cursor: pointer;
            padding: 2px 7px;
            font-size: 12px;
            color: #888;
            transition: background 0.15s, color 0.15s, border-color 0.15s;
            line-height: 1.4;
        }
        .snn-cc-check-btn:hover {
            background: #eafaf1;
            color: #27ae60;
            border-color: #27ae60;
        }
        .snn-cc-check-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 13px;
            cursor: default;
            position: relative;
            color: #27ae60;
            font-weight: 500;
        }
        .snn-cc-check-badge .snn-cc-check-tooltip {
            display: none;
            position: absolute;
            bottom: calc(100% + 5px);
            left: 0;
            background: #222;
            color: #fff;
            padding: 5px 9px;
            border-radius: 5px;
            font-size: 11px;
            white-space: nowrap;
            z-index: 1000001;
            pointer-events: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.25);
        }
        .snn-cc-check-badge .snn-cc-check-tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 10px;
            border: 5px solid transparent;
            border-top-color: #222;
        }
        .snn-cc-check-badge:hover .snn-cc-check-tooltip {
            display: block;
        }

        /* Checked marker ring */
        .snn-cc-marker.is-checked {
            box-shadow: 0 0 0 3px #2ecc71, 0 2px 8px rgba(0,0,0,0.3);
        }
        /* Checked indicator dot on marker */
        .snn-cc-marker.is-checked::before {
            content: '✓';
            position: absolute;
            bottom: -7px;
            right: -7px;
            width: 16px;
            height: 16px;
            background: #2ecc71;
            border-radius: 50%;
            border: 2px solid #fff;
            font-size: 9px;
            line-height: 12px;
            text-align: center;
            color: #fff;
            font-weight: bold;
            z-index: 1;
        }

        /* Checked badge in sidebar / allsite items */
        .snn-cc-checked-icon {
            color: #2ecc71;
            font-size: 12px;
            margin-left: 4px;
        }
    </style>

    <script>
    jQuery(document).ready(function($) {
        let clickMode = false;
        let comments = [];
        let sidebarOpen = false;
        let allSitePanelOpen = false;
        let allSiteComments = [];

        const markerColor = '<?php echo esc_js($marker_color); ?>';
        const markerStyle = '<?php echo esc_js($marker_style); ?>';
        const allowReplies = <?php echo $allow_replies ? 'true' : 'false'; ?>;
        const currentUserId = <?php echo $user_id; ?>;
        const ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
        const nonce = '<?php echo wp_create_nonce('snn_cc_nonce'); ?>';
        const guestCommenting = <?php echo $guest_commenting ? 'true' : 'false'; ?>;
        const isGuest = <?php echo $is_guest ? 'true' : 'false'; ?>;
        const currentGuestToken = isGuest ? '<?php echo isset($_SESSION['snn_guest_token']) ? esc_js($_SESSION['snn_guest_token']) : ''; ?>' : '';

        // Initialize
        init();

        function init() {
            createSidebar();
            createAllSitePanel();
            <?php if ($is_guest): ?>
            createGuestControls();
            <?php endif; ?>
            loadComments();
            bindEvents();
            checkViewCommentParam();
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

        // Create guest control buttons
        function createGuestControls() {
            const controls = $('<div class="snn-cc-guest-controls">' +
                '<button class="snn-cc-guest-btn snn-cc-guest-add-btn" title="Add Comment">' +
                    '+' +
                '</button>' +
                '<button class="snn-cc-guest-btn snn-cc-guest-sidebar-btn" title="View Comments">' +
                    '☰' +
                '</button>' +
                '<button class="snn-cc-guest-btn snn-cc-guest-allsite-btn" title="All Site Comments">' +
                    '🌐' +
                '</button>' +
            '</div>');
            $('body').append(controls);
        }

        // Create all site comments panel HTML
        function createAllSitePanel() {
            const panel = $('<div class="snn-cc-allsite-panel">' +
                '<div class="snn-cc-allsite-header">' +
                    '<h3>All Site Comments</h3>' +
                    '<button class="snn-cc-allsite-close">&times;</button>' +
                '</div>' +
                '<div class="snn-cc-allsite-list"></div>' +
            '</div>');
            $('body').append(panel);
        }

        // Bind events
        function bindEvents() {
            // Toggle add comment mode (Admin bar and Guest buttons)
            $('#wp-admin-bar-snn-cc-add-btn, .snn-cc-guest-add-btn').on('click', function(e) {
                e.preventDefault();
                clickMode = !clickMode;
                $('#wp-admin-bar-snn-cc-add-btn, .snn-cc-guest-add-btn').toggleClass('active', clickMode);
                $('body').toggleClass('snn-cc-click-mode', clickMode);
            });

            // Toggle sidebar (Admin bar and Guest buttons)
            $('#wp-admin-bar-snn-cc-sidebar-btn, .snn-cc-guest-sidebar-btn, .snn-cc-sidebar-close').on('click', function(e) {
                e.preventDefault();
                sidebarOpen = !sidebarOpen;
                $('.snn-cc-sidebar').toggleClass('active', sidebarOpen);
                $('#wp-admin-bar-snn-cc-sidebar-btn, .snn-cc-guest-sidebar-btn').toggleClass('active', sidebarOpen);
            });

            // Toggle all site comments panel
            $('#wp-admin-bar-snn-cc-allsite-btn, .snn-cc-guest-allsite-btn').on('click', function(e) {
                e.preventDefault();
                allSitePanelOpen = !allSitePanelOpen;
                if (allSitePanelOpen) {
                    loadAllSiteComments();
                }
                $('.snn-cc-allsite-panel').toggleClass('active', allSitePanelOpen);
                $('#wp-admin-bar-snn-cc-allsite-btn, .snn-cc-guest-allsite-btn').toggleClass('active', allSitePanelOpen);
            });

            $('body').on('click', '.snn-cc-allsite-close', function(e) {
                e.preventDefault();
                allSitePanelOpen = false;
                $('.snn-cc-allsite-panel').removeClass('active');
                $('#wp-admin-bar-snn-cc-allsite-btn, .snn-cc-guest-allsite-btn').removeClass('active');
            });

            // Handle click to add comment
            $('body').on('click', function(e) {
                if (!clickMode) return;

                // Ignore clicks on admin bar, guest buttons, markers, popups, and sidebar
                if ($(e.target).closest('#wpadminbar, .snn-cc-guest-controls, .snn-cc-marker, .snn-cc-popup, .snn-cc-sidebar').length) {
                    return;
                }

                e.preventDefault();
                e.stopPropagation();

                clickMode = false;
                $('#wp-admin-bar-snn-cc-add-btn, .snn-cc-guest-add-btn').removeClass('active');
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

            // Click on all-site panel item
            $('body').on('click', '.snn-cc-allsite-item', function(e) {
                const commentId = $(this).data('comment-id');
                const pageUrl   = $(this).data('page-url');
                const isSamePage = $(this).data('same-page') === '1';

                if (isSamePage) {
                    // Close panel and navigate to the marker on this page
                    allSitePanelOpen = false;
                    $('.snn-cc-allsite-panel').removeClass('active');
                    $('#wp-admin-bar-snn-cc-allsite-btn, .snn-cc-guest-allsite-btn').removeClass('active');

                    const marker = $('.snn-cc-marker[data-comment-id="' + commentId + '"]');
                    if (marker.length) {
                        $('html, body').animate({
                            scrollTop: marker.offset().top - 150
                        }, 500);
                        marker.css('transform', 'scale(1.4)');
                        setTimeout(function() {
                            marker.css('transform', '');
                            const comment = findCommentById(parseInt(commentId));
                            if (comment) {
                                showCommentPopup(comment, marker.offset().left, marker.offset().top);
                            }
                        }, 500);
                    }
                } else {
                    // Navigate to the other page, passing the comment ID
                    let targetUrl = pageUrl;
                    try {
                        const urlObj = new URL(pageUrl);
                        urlObj.searchParams.set('snn_view_comment', commentId);
                        <?php if ($is_guest): ?>
                        if (currentGuestToken) {
                            urlObj.searchParams.set('snn_guest_token', currentGuestToken);
                        }
                        <?php endif; ?>
                        targetUrl = urlObj.toString();
                    } catch(err) {
                        targetUrl = pageUrl + (pageUrl.indexOf('?') === -1 ? '?' : '&') + 'snn_view_comment=' + commentId;
                    }
                    window.location.href = targetUrl;
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

            // Check / Uncheck comment (logged-in non-guest users only)
            $('body').on('click', '.snn-cc-check-btn', function(e) {
                e.stopPropagation();
                const commentId = $(this).data('comment-id');
                checkComment(commentId, $(this));
            });
        }

        // Load comments from server
        function loadComments() {
            // Remove snn_guest_token from URL for consistent page identification
            const cleanUrl = removeTokenFromUrl(window.location.href);

            // Prepare data
            const requestData = {
                action: 'snn_cc_get_comments',
                page_url: cleanUrl,
                nonce: nonce
            };

            // Include guest token if user is guest
            if (isGuest && currentGuestToken) {
                requestData.guest_token = currentGuestToken;
            }

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: requestData,
                success: function(response) {
                    if (response.success) {
                        comments = response.data;
                        displayMarkers();
                        updateSidebar();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Load comments error:', status, error);
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

                const marker = $('<div class="snn-cc-marker' + (hasReplies ? ' has-replies' : '') + (comment.checked_by ? ' is-checked' : '') + '">' + markerContent + '</div>');
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
                        (comment.checked_by ? ' <span class="snn-cc-checked-icon" title="Checked by ' + escapeHtml(comment.checked_by_name || 'user') + '">✅</span>' : '') +
                    '</div>' +
                '</div>');

                list.append(item);
            });
        }

        // Load all sitewide comments from server
        function loadAllSiteComments() {
            const list = $('.snn-cc-allsite-list');
            list.html('<div class="snn-cc-allsite-loading"><span class="snn-cc-loading"></span> Loading...</div>');

            const requestData = {
                action: 'snn_cc_get_all_comments',
                nonce: nonce
            };

            if (isGuest && currentGuestToken) {
                requestData.guest_token = currentGuestToken;
            }

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: requestData,
                success: function(response) {
                    if (response.success) {
                        allSiteComments = response.data;
                        updateAllSitePanel();
                    } else {
                        list.html('<div class="snn-cc-allsite-empty">Could not load comments.</div>');
                    }
                },
                error: function() {
                    list.html('<div class="snn-cc-allsite-empty">Could not load comments.</div>');
                }
            });
        }

        // Render the all-site panel grouped by page
        function updateAllSitePanel() {
            const list = $('.snn-cc-allsite-list');
            list.empty();

            const parentComments = allSiteComments.filter(c => c.parent_id == 0);

            if (parentComments.length === 0) {
                list.html('<div class="snn-cc-allsite-empty">No comments found sitewide.</div>');
                return;
            }

            // Group by page_url
            const grouped = {};
            parentComments.forEach(function(comment) {
                const url = comment.page_url;
                if (!grouped[url]) grouped[url] = [];
                grouped[url].push(comment);
            });

            const currentCleanUrl = removeTokenFromUrl(window.location.href);

            Object.keys(grouped).forEach(function(pageUrl) {
                const pageComments = grouped[pageUrl];
                const isSamePage = (pageUrl === currentCleanUrl);

                // Derive a human-readable page name from the URL
                let pageName = pageUrl;
                try {
                    const urlObj = new URL(pageUrl);
                    const path = urlObj.pathname.replace(/\/$/, '');
                    if (path === '' || path === '/') {
                        pageName = 'Home Page';
                    } else {
                        pageName = path.split('/').pop();
                        pageName = decodeURIComponent(pageName).replace(/-/g, ' ').replace(/_/g, ' ');
                        pageName = pageName.charAt(0).toUpperCase() + pageName.slice(1);
                    }
                } catch(err) { /* keep raw URL */ }

                const group = $('<div class="snn-cc-allsite-page-group"></div>');
                const titleBar = $('<div class="snn-cc-allsite-page-title">' +
                    '<a href="' + escapeHtml(pageUrl) + '" target="_blank" class="snn-cc-allsite-page-link" title="' + escapeHtml(pageUrl) + '">' + escapeHtml(pageName) + '</a>' +
                    (isSamePage ? ' <span class="snn-cc-allsite-current-badge">current</span>' : '') +
                '</div>');
                group.append(titleBar);

                pageComments.forEach(function(comment) {
                    const replyCount = allSiteComments.filter(c => c.parent_id == comment.id).length;
                    const item = $('<div class="snn-cc-allsite-item"' +
                        ' data-comment-id="' + comment.id + '"' +
                        ' data-page-url="' + escapeHtml(pageUrl) + '"' +
                        ' data-same-page="' + (isSamePage ? '1' : '0') + '">' +
                        '<div class="snn-cc-allsite-item-user">' +
                            '<span class="snn-cc-sidebar-item-avatar" style="background:' + getUserColor(comment.user_id) + '">' + getInitials(comment.user_name) + '</span>' +
                            escapeHtml(comment.user_name) +
                        '</div>' +
                        '<div class="snn-cc-allsite-item-text">' + escapeHtml(comment.comment.substring(0, 80)) + (comment.comment.length > 80 ? '...' : '') + '</div>' +
                        '<div class="snn-cc-allsite-item-meta">' +
                            formatDate(comment.created_at) +
                            (replyCount > 0 ? ' &bull; ' + replyCount + ' ' + (replyCount === 1 ? 'reply' : 'replies') : '') +
                            (comment.checked_by ? ' <span class="snn-cc-checked-icon" title="Checked by ' + escapeHtml(comment.checked_by_name || 'user') + '">✅</span>' : '') +
                        '</div>' +
                    '</div>');
                    group.append(item);
                });

                list.append(group);
            });
        }

        // Auto-open a specific comment if snn_view_comment is in the URL
        function checkViewCommentParam() {
            const urlParams = new URLSearchParams(window.location.search);
            const viewCommentId = urlParams.get('snn_view_comment');
            if (!viewCommentId) return;

            // Poll until the marker appears (comments finish loading)
            let attempts = 0;
            const checkInterval = setInterval(function() {
                attempts++;
                const marker = $('.snn-cc-marker[data-comment-id="' + viewCommentId + '"]');
                if (marker.length || attempts > 50) {
                    clearInterval(checkInterval);
                    if (!marker.length) return;
                    setTimeout(function() {
                        $('html, body').animate({
                            scrollTop: marker.offset().top - 150
                        }, 800);
                        marker.css('transform', 'scale(1.4)');
                        setTimeout(function() {
                            marker.css('transform', '');
                            const comment = findCommentById(parseInt(viewCommentId));
                            if (comment) {
                                showCommentPopup(comment, marker.offset().left, marker.offset().top);
                            }
                        }, 800);
                    }, 300);
                }
            }, 100);
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
            // Check ownership: for guests, compare tokens; for logged in users, compare user IDs
            const canEdit = isGuest
                ? (comment.user_id == -1 && comment.guest_token == currentGuestToken)
                : (comment.user_id == currentUserId);

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
                    // Check ownership for each reply
                    const canEditReply = isGuest
                        ? (reply.user_id == -1 && reply.guest_token == currentGuestToken)
                        : (reply.user_id == currentUserId);
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

            // Check badge / button — only for logged-in (non-guest) users
            if (!isGuest) {
                html += buildCheckBadge(comment);
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

            // Remove snn_guest_token from URL for consistent page identification
            const cleanUrl = removeTokenFromUrl(window.location.href);

            // Prepare data
            const requestData = {
                action: 'snn_cc_save_comment',
                comment: comment,
                pos_x: x + 'px',
                pos_y: y + 'px',
                page_url: cleanUrl,
                parent_id: parentId,
                nonce: nonce
            };

            // Include guest token if user is guest
            if (isGuest && currentGuestToken) {
                requestData.guest_token = currentGuestToken;
            }

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: requestData,
                success: function(response) {
                    if (response.success) {
                        $('.snn-cc-popup').remove();
                        loadComments();
                    } else {
                        alert('Error saving comment: ' + (response.data || 'Unknown error'));
                        btn.text(originalText).prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    console.error('Response:', xhr.responseText);
                    alert('Error saving comment: ' + error);
                    btn.text(originalText).prop('disabled', false);
                }
            });
        }

        // Update comment
        function updateComment(commentId, newComment, popup) {
            const btn = popup.find('.snn-cc-popup-btn-update');
            const originalText = btn.text();
            btn.html('<span class="snn-cc-loading"></span>').prop('disabled', true);

            // Prepare data
            const requestData = {
                action: 'snn_cc_update_comment',
                comment_id: commentId,
                comment: newComment,
                nonce: nonce
            };

            // Include guest token if user is guest
            if (isGuest && currentGuestToken) {
                requestData.guest_token = currentGuestToken;
            }

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: requestData,
                success: function(response) {
                    if (response.success) {
                        popup.remove();
                        loadComments();
                    } else {
                        alert('Error updating comment: ' + (response.data || 'Unknown error'));
                        btn.text(originalText).prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Update comment error:', status, error);
                    alert('Error updating comment: ' + error);
                    btn.text(originalText).prop('disabled', false);
                }
            });
        }

        // Delete comment
        function deleteComment(commentId, popup) {
            // Prepare data
            const requestData = {
                action: 'snn_cc_delete_comment',
                comment_id: commentId,
                nonce: nonce
            };

            // Include guest token if user is guest
            if (isGuest && currentGuestToken) {
                requestData.guest_token = currentGuestToken;
            }

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: requestData,
                success: function(response) {
                    if (response.success) {
                        popup.remove();
                        loadComments();
                    } else {
                        alert('Error deleting comment: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Delete comment error:', status, error);
                    alert('Error deleting comment: ' + error);
                }
            });
        }

        // Helper functions
        function findCommentById(id) {
            return comments.find(c => c.id == id);
        }

        // Build the check badge (if checked) or check button (if not checked)
        function buildCheckBadge(comment) {
            if (!comment) return '';
            if (comment.checked_by) {
                const who  = escapeHtml(comment.checked_by_name || 'User');
                const when = formatDate(comment.checked_at);
                return '<span class="snn-cc-check-badge">' +
                    '✅' +
                    '<span class="snn-cc-check-tooltip">Checked by ' + who + ' &middot; ' + when + '</span>' +
                '</span>';
            }
            return '<button class="snn-cc-popup-comment-action snn-cc-check-btn" ' +
                'data-comment-id="' + comment.id + '">☑ Check</button>';
        }

        // Toggle checked state
        function checkComment(commentId, btn) {
            const originalHtml = btn.html();
            btn.html('…').prop('disabled', true);

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snn_cc_check_comment',
                    comment_id: commentId,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Update the comment object in our local array
                        const comment = findCommentById(commentId);
                        if (comment) {
                            if (response.data.checked) {
                                comment.checked_by       = response.data.checked_by;
                                comment.checked_by_name  = response.data.checked_by_name;
                                comment.checked_at       = response.data.checked_at;
                            } else {
                                comment.checked_by       = null;
                                comment.checked_by_name  = null;
                                comment.checked_at       = null;
                            }
                        }
                        // Replace button/badge in the open popup
                        btn.replaceWith(buildCheckBadge(comment));
                        // Update marker ring on page
                        const marker = $('.snn-cc-marker[data-comment-id="' + commentId + '"]');
                        if (response.data.checked) {
                            marker.addClass('is-checked');
                        } else {
                            marker.removeClass('is-checked');
                        }
                        // Refresh sidebar
                        updateSidebar();
                    } else {
                        alert('Error: ' + (response.data || 'Could not update check state'));
                        btn.html(originalHtml).prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Error: Could not reach server');
                    btn.html(originalHtml).prop('disabled', false);
                }
            });
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

        function removeTokenFromUrl(url) {
            try {
                const urlObj = new URL(url);
                urlObj.searchParams.delete('snn_guest_token');
                urlObj.searchParams.delete('snn_view_comment');
                return urlObj.toString();
            } catch (e) {
                // Fallback for invalid URLs
                return url
                    .replace(/[?&]snn_guest_token=[^&]+/, '')
                    .replace(/[?&]snn_view_comment=[^&]+/, '')
                    .replace(/\?$/, '');
            }
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
 * AJAX: Get comments for current page
 */
function snn_cc_get_comments() {
    // Ensure session is started for guest users
    if (!session_id()) {
        @session_start();
    }

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
        "SELECT c.id, c.parent_id, c.user_id, c.guest_token, c.page_url, c.pos_x, c.pos_y, c.comment, c.status, c.created_at, c.updated_at, c.checked_by, c.checked_at,
         CASE
            WHEN c.user_id = -1 THEN 'Guest'
            ELSE u.display_name
         END as user_name,
         cu.display_name as checked_by_name
         FROM $table_name c
         LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID
         LEFT JOIN {$wpdb->users} cu ON c.checked_by = cu.ID
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
    // Ensure session is started for guest users
    if (!session_id()) {
        @session_start();
    }

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

    // Use -1 for guest users and store their token for ownership tracking
    if ($is_guest) {
        $user_id = -1;
        // Try to get guest token from POST (sent by JavaScript), fallback to session
        $guest_token = '';
        if (isset($_POST['guest_token']) && !empty($_POST['guest_token'])) {
            $guest_token = sanitize_text_field($_POST['guest_token']);
        } elseif (isset($_SESSION['snn_guest_token'])) {
            $guest_token = $_SESSION['snn_guest_token'];
        }

        // Debug: Log guest token for troubleshooting
        if (empty($guest_token)) {
            error_log('SNN CC: Guest token empty in save_comment. POST token: ' . (isset($_POST['guest_token']) ? $_POST['guest_token'] : 'not set') . ', Session: ' . print_r($_SESSION, true));
        }
    } else {
        $user_id = get_current_user_id();
        $guest_token = null;
    }

    if (empty($comment)) {
        wp_send_json_error('Comment is required');
        return;
    }

    $result = $wpdb->insert(
        $table_name,
        array(
            'parent_id' => $parent_id,
            'user_id' => $user_id,
            'guest_token' => $guest_token,
            'page_url' => $page_url,
            'pos_x' => $pos_x,
            'pos_y' => $pos_y,
            'comment' => $comment,
            'status' => 'active'
        ),
        array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
    );

    if ($result) {
        wp_send_json_success(array('id' => $wpdb->insert_id));
    } else {
        $error = $wpdb->last_error;
        error_log('SNN CC: Database insert failed: ' . $error);
        wp_send_json_error('Failed to save comment: ' . $error);
    }
}
add_action('wp_ajax_snn_cc_save_comment', 'snn_cc_save_comment');
add_action('wp_ajax_nopriv_snn_cc_save_comment', 'snn_cc_save_comment');

/**
 * AJAX: Update comment
 */
function snn_cc_update_comment() {
    // Ensure session is started for guest users
    if (!session_id()) {
        @session_start();
    }

    check_ajax_referer('snn_cc_nonce', 'nonce');

    // Allow guests if they have valid token
    $is_guest = snn_cc_is_guest_user();
    if (!is_user_logged_in() && !$is_guest) {
        wp_send_json_error('Not logged in');
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'snn_client_comments';

    $comment_id = intval($_POST['comment_id']);
    $comment = sanitize_textarea_field($_POST['comment']);

    if (empty($comment)) {
        wp_send_json_error('Comment is required');
        return;
    }

    // Check ownership - different logic for guests vs logged in users
    if ($is_guest) {
        // Try to get guest token from POST (sent by JavaScript), fallback to session
        $guest_token = '';
        if (isset($_POST['guest_token']) && !empty($_POST['guest_token'])) {
            $guest_token = sanitize_text_field($_POST['guest_token']);
        } elseif (isset($_SESSION['snn_guest_token'])) {
            $guest_token = $_SESSION['snn_guest_token'];
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND user_id = -1 AND guest_token = %s",
            $comment_id,
            $guest_token
        ));
    } else {
        $user_id = get_current_user_id();
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND user_id = %d",
            $comment_id,
            $user_id
        ));
    }

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
    // Ensure session is started for guest users
    if (!session_id()) {
        @session_start();
    }

    check_ajax_referer('snn_cc_nonce', 'nonce');

    // Allow guests if they have valid token
    $is_guest = snn_cc_is_guest_user();
    if (!is_user_logged_in() && !$is_guest) {
        wp_send_json_error('Not logged in');
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'snn_client_comments';

    $comment_id = intval($_POST['comment_id']);

    // Check ownership - different logic for guests vs logged in users
    if ($is_guest) {
        // Try to get guest token from POST (sent by JavaScript), fallback to session
        $guest_token = '';
        if (isset($_POST['guest_token']) && !empty($_POST['guest_token'])) {
            $guest_token = sanitize_text_field($_POST['guest_token']);
        } elseif (isset($_SESSION['snn_guest_token'])) {
            $guest_token = $_SESSION['snn_guest_token'];
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND user_id = -1 AND guest_token = %s",
            $comment_id,
            $guest_token
        ));
    } else {
        $user_id = get_current_user_id();
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND user_id = %d",
            $comment_id,
            $user_id
        ));
    }

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

/**
 * AJAX: Toggle "Checked" state on a comment (logged-in users only)
 */
function snn_cc_check_comment() {
    check_ajax_referer('snn_cc_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error('Must be logged in to check comments');
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'snn_client_comments';
    $comment_id = intval($_POST['comment_id']);
    $user_id    = get_current_user_id();

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d AND status = 'active'",
        $comment_id
    ));

    if (!$existing) {
        wp_send_json_error('Comment not found');
        return;
    }

    // Toggle: if already checked by the same user → uncheck; otherwise check
    if ($existing->checked_by == $user_id) {
        $wpdb->update(
            $table_name,
            array('checked_by' => null, 'checked_at' => null),
            array('id' => $comment_id),
            array('%s', '%s'),
            array('%d')
        );
        wp_send_json_success(array('checked' => false));
    } else {
        $current_user = wp_get_current_user();
        $now          = current_time('mysql');
        $wpdb->update(
            $table_name,
            array('checked_by' => $user_id, 'checked_at' => $now),
            array('id' => $comment_id),
            array('%d', '%s'),
            array('%d')
        );
        wp_send_json_success(array(
            'checked'          => true,
            'checked_by'       => $user_id,
            'checked_by_name'  => $current_user->display_name,
            'checked_at'       => $now,
        ));
    }
}
add_action('wp_ajax_snn_cc_check_comment', 'snn_cc_check_comment');

/**
 * AJAX: Get all comments sitewide (no page filter)
 */
function snn_cc_get_all_comments() {
    if (!session_id()) {
        @session_start();
    }

    check_ajax_referer('snn_cc_nonce', 'nonce');

    $is_guest = snn_cc_is_guest_user();
    if (!is_user_logged_in() && !$is_guest) {
        wp_send_json_error('Not logged in');
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'snn_client_comments';

    $comments = $wpdb->get_results(
        "SELECT c.id, c.parent_id, c.user_id, c.guest_token, c.page_url, c.pos_x, c.pos_y, c.comment, c.status, c.created_at, c.updated_at, c.checked_by, c.checked_at,
         CASE
            WHEN c.user_id = -1 THEN 'Guest'
            ELSE u.display_name
         END as user_name,
         cu.display_name as checked_by_name
         FROM $table_name c
         LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID
         LEFT JOIN {$wpdb->users} cu ON c.checked_by = cu.ID
         WHERE c.status = 'active'
         ORDER BY c.page_url ASC, c.parent_id ASC, c.created_at DESC",
        ARRAY_A
    );

    wp_send_json_success($comments);
}
add_action('wp_ajax_snn_cc_get_all_comments', 'snn_cc_get_all_comments');
add_action('wp_ajax_nopriv_snn_cc_get_all_comments', 'snn_cc_get_all_comments');

/**
 * Add "Delete All Data" link to plugin action links on plugins page
 */
function snn_cc_add_plugin_action_links($links, $file) {
    if ($file === plugin_basename(__FILE__)) {
        $delete_link = '<a href="' . wp_nonce_url(admin_url('plugins.php?snn_cc_delete_all_data=1'), 'snn_cc_delete_all_data') . '" style="color: #b32d2e;" onclick="return confirm(\'Are you sure you want to delete ALL plugin data? This will remove all comments, settings, and database tables. This action cannot be undone!\');">Delete All Data</a>';
        $links['delete_data'] = $delete_link;
    }
    return $links;
}
add_filter('plugin_action_links', 'snn_cc_add_plugin_action_links', 10, 2);

/**
 * Handle delete all data request
 */
function snn_cc_handle_delete_all_data() {
    if (!isset($_GET['snn_cc_delete_all_data']) || $_GET['snn_cc_delete_all_data'] !== '1') {
        return;
    }

    // Verify nonce and permissions
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to perform this action.');
    }

    if (!wp_verify_nonce($_GET['_wpnonce'], 'snn_cc_delete_all_data')) {
        wp_die('Security check failed.');
    }

    global $wpdb;

    // Drop database tables
    $comments_table = $wpdb->prefix . 'snn_client_comments';
    $tokens_table = $wpdb->prefix . 'snn_client_comments_tokens';

    $wpdb->query("DROP TABLE IF EXISTS $comments_table");
    $wpdb->query("DROP TABLE IF EXISTS $tokens_table");

    // Delete all plugin options
    delete_option('snn_cc_enabled');
    delete_option('snn_cc_marker_color');
    delete_option('snn_cc_show_in_frontend');
    delete_option('snn_cc_allow_replies');
    delete_option('snn_cc_marker_style');
    delete_option('snn_cc_auto_collapse');
    delete_option('snn_cc_guest_commenting');
    delete_option('snn_cc_db_version');
    delete_option('snn_cc_global_guest_token');

    // Redirect back to plugins page with success message
    wp_redirect(admin_url('plugins.php?snn_cc_data_deleted=1'));
    exit;
}
add_action('admin_init', 'snn_cc_handle_delete_all_data');

/**
 * Show admin notice after data deletion
 */
function snn_cc_show_deletion_notice() {
    if (isset($_GET['snn_cc_data_deleted']) && $_GET['snn_cc_data_deleted'] === '1') {
        echo '<div class="notice notice-success is-dismissible"><p><strong>SNN Client Comments:</strong> All plugin data has been deleted successfully. You can now deactivate and delete the plugin.</p></div>';
    }
}
add_action('admin_notices', 'snn_cc_show_deletion_notice');
