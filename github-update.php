<?php
/**
 * GitHub Auto-Update for SNN Client Comments Plugin
 *
 * Checks for updates directly from GitHub releases
 * and provides update notifications in WordPress admin
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Configuration
$github_repo_owner = 'sinanisler';
$github_repo_name  = 'snn-client-comments';
$plugin_slug       = 'snn-client-comments';
$plugin_file       = 'snn-client-comments/snn-client-comments.php';

/**
 * Check for Plugin Updates from GitHub
 */
add_filter('pre_set_site_transient_update_plugins', 'snn_client_comments_check_github_update');
function snn_client_comments_check_github_update($transient) {
    global $github_repo_owner, $github_repo_name, $plugin_slug, $plugin_file;

    if (empty($transient->checked)) {
        return $transient;
    }

    // Get current plugin version
    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
    $plugin_data = get_plugin_data($plugin_path);
    $current_version = $plugin_data['Version'];

    // GitHub API URL for latest release
    $github_api_url = "https://api.github.com/repos/{$github_repo_owner}/{$github_repo_name}/releases/latest";

    // Fetch latest release from GitHub
    $response = wp_remote_get($github_api_url, array(
        'timeout' => 15,
        'headers' => array(
            'Accept'     => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
        ),
    ));

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return $transient;
    }

    $release_data = json_decode(wp_remote_retrieve_body($response));

    if (!$release_data || !isset($release_data->tag_name)) {
        return $transient;
    }

    $latest_version = ltrim($release_data->tag_name, 'vV');
    $expected_asset_name = $plugin_slug . '.zip';
    $download_url = '';

    // Find the correct asset (plugin zip file)
    if (isset($release_data->assets) && is_array($release_data->assets)) {
        foreach ($release_data->assets as $asset) {
            if (isset($asset->browser_download_url) && $asset->name === $expected_asset_name) {
                $download_url = $asset->browser_download_url;
                break;
            }
        }
    }

    // If no specific asset found, use zipball_url as fallback
    if (empty($download_url) && isset($release_data->zipball_url)) {
        $download_url = $release_data->zipball_url;
    }

    // Only trigger update if new version exists and download URL is found
    if (
        $download_url
        && version_compare($latest_version, $current_version, '>')
    ) {
        $plugin_data = array(
            'slug'        => $plugin_slug,
            'plugin'      => $plugin_file,
            'new_version' => $latest_version,
            'url'         => $release_data->html_url ?? '',
            'package'     => $download_url,
            'tested'      => get_bloginfo('version'),
            'requires_php'=> '7.2',
        );

        $transient->response[$plugin_file] = (object) $plugin_data;
    }

    return $transient;
}

/**
 * Provide Plugin Info for the "View version x.x.x details" popup
 */
add_filter('plugins_api', 'snn_client_comments_plugin_info_from_github', 10, 3);
function snn_client_comments_plugin_info_from_github($result, $action, $args) {
    global $github_repo_owner, $github_repo_name, $plugin_slug, $plugin_file;

    if ($action !== 'plugin_information' || $args->slug !== $plugin_slug) {
        return $result;
    }

    // GitHub API URL for latest release
    $github_api_url = "https://api.github.com/repos/{$github_repo_owner}/{$github_repo_name}/releases/latest";

    // Fetch latest release from GitHub
    $response = wp_remote_get($github_api_url, array(
        'timeout' => 15,
        'headers' => array(
            'Accept'     => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
        ),
    ));

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return $result;
    }

    $release_data = json_decode(wp_remote_retrieve_body($response));
    if (!$release_data || !isset($release_data->tag_name)) {
        return $result;
    }

    $latest_version      = ltrim($release_data->tag_name, 'vV');
    $expected_asset_name = $plugin_slug . '.zip';
    $download_url        = '';

    // Find the correct asset
    if (isset($release_data->assets) && is_array($release_data->assets)) {
        foreach ($release_data->assets as $asset) {
            if (isset($asset->browser_download_url) && $asset->name === $expected_asset_name) {
                $download_url = $asset->browser_download_url;
                break;
            }
        }
    }

    // Fallback to zipball
    if (empty($download_url) && isset($release_data->zipball_url)) {
        $download_url = $release_data->zipball_url;
    }

    // Get current plugin data
    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
    $plugin_data = get_plugin_data($plugin_path);

    // Prepare changelog from release body
    $changelog = !empty($release_data->body)
        ? wp_kses_post($release_data->body)
        : __('See GitHub release notes for details.', 'snn-client-comments');

    // Prepare details for the WP plugin info popup
    $result = (object) array(
        'name'          => $plugin_data['Name'],
        'slug'          => $plugin_slug,
        'version'       => $latest_version,
        'author'        => $plugin_data['Author'],
        'homepage'      => $release_data->html_url ?? '',
        'requires'      => '5.0',
        'tested'        => get_bloginfo('version'),
        'requires_php'  => '7.2',
        'download_link' => $download_url,
        'sections'      => array(
            'description' => $plugin_data['Description'] ?? __('Visual commenting system for WordPress.', 'snn-client-comments'),
            'changelog'   => '<h4>Version ' . esc_html($latest_version) . '</h4>' . $changelog,
        ),
        'banners'       => array(),
        'added'         => isset($release_data->published_at) ? date('Y-m-d', strtotime($release_data->published_at)) : '',
        'last_updated'  => isset($release_data->published_at) ? date('Y-m-d', strtotime($release_data->published_at)) : '',
    );

    return $result;
}

/**
 * Add JavaScript to admin footer to redirect version details link to GitHub
 */
add_action('admin_footer', 'snn_client_comments_github_redirect_version_link');
function snn_client_comments_github_redirect_version_link() {
    global $github_repo_owner, $github_repo_name;

    $github_url = "https://github.com/{$github_repo_owner}/{$github_repo_name}/releases";
    ?>
    <script type="text/javascript">
        (function() {
            const githubUrl = '<?php echo esc_js($github_url); ?>';
            const pluginSlug = 'snn-client-comments';

            function modifyLink(link) {
                const href = link.getAttribute('href');
                if (href && href.includes('plugin-install.php') && href.includes('tab=plugin-information') && href.includes(pluginSlug)) {
                    // Replace the href completely
                    link.href = githubUrl;

                    // Remove thickbox classes
                    link.classList.remove('thickbox', 'open-plugin-details-modal');

                    // Set target to open in new tab
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';

                    // Add click handler as extra safety
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        window.open(githubUrl, '_blank', 'noopener,noreferrer');
                        return false;
                    }, true);
                }
            }

            function processLinks() {
                // Target links in the plugins page
                const links = document.querySelectorAll('.plugin-title a, .update-message a, a[aria-label*="SNN Client Comments"]');
                links.forEach(modifyLink);
            }

            // Process on DOM ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', processLinks);
            } else {
                processLinks();
            }

            // Also watch for dynamically added links
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1) {
                            if (node.matches && node.matches('a')) {
                                modifyLink(node);
                            }
                            const links = node.querySelectorAll && node.querySelectorAll('a');
                            if (links) {
                                links.forEach(modifyLink);
                            }
                        }
                    });
                });
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        })();
    </script>
    <?php
}
