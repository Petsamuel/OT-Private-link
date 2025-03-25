<?php
/*
Plugin Name: One-Time Private Links 
Description: Advanced one-time private links with expiration control, Perfect for sharing private documents or previews without requiring login. 
Version: 1.0
Author: Samuel Peters
*/

if (!defined('ABSPATH')) exit;

// ========================
// Database Setup
// ========================
register_activation_hook(__FILE__, 'otpl_create_database_tables');

function otpl_create_database_tables()
{
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $links_table = $wpdb->prefix . 'otpl_links';
    $access_logs_table = $wpdb->prefix . 'otpl_access_logs';

    $sql = "CREATE TABLE $links_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        token varchar(32) NOT NULL,
        page_url text NOT NULL,
        short_url varchar(50) DEFAULT NULL,
        expiration datetime NOT NULL,
        is_used tinyint(1) DEFAULT 0,
        usage_type enum('single','multiple') DEFAULT 'single',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY token (token)
    ) $charset_collate;

    CREATE TABLE $access_logs_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        token varchar(32) NOT NULL,
        accessed_at datetime DEFAULT CURRENT_TIMESTAMP,
        ip_address varchar(45) DEFAULT NULL,
        user_agent text DEFAULT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// ========================
// Core Plugin Setup
// ========================
add_action('plugins_loaded', 'otpl_init_plugin');

function otpl_init_plugin()
{
    if (!session_id()) session_start();

    add_action('admin_enqueue_scripts', 'otpl_load_assets');
    add_action('wp_enqueue_scripts', 'otpl_load_assets');
    add_action('admin_menu', 'otpl_add_admin_menu');
    add_action('init', 'otpl_handle_access_requests');
    add_action('otpl_daily_cleanup', 'otpl_cleanup_expired_links');
}

function otpl_load_assets()
{
    // Tailwind CSS
    wp_enqueue_style(
        'otpl-tailwind',
        'https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css',
        [],
        null
    );

    // Custom CSS
    wp_enqueue_style(
        'otpl-custom',
        plugins_url('assets/css/custom.css', __FILE__)
    );
}

// ========================
// Admin Interface
// ========================
function otpl_add_admin_menu()
{
    add_menu_page(
        'OT Private Links',
        'OT Private Links',
        'manage_options',
        'otpl-generator',
        'otpl_render_admin_page',
        'dashicons-admin-links',
        99
    );

    add_submenu_page(
        'otpl-generator',
        'Access Logs',
        'Access Logs',
        'manage_options',
        'otpl-logs',
        'otpl_render_logs_page'
    );
}

// ========================
// ADMIN PAGES
// ========================

function otpl_render_admin_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized access', 'otpl'));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'otpl_links';

    // Handle form submission
    if (isset($_POST['generate_link']) && wp_verify_nonce($_POST['_wpnonce'], 'otpl_generate_link')) {
        $page_url = esc_url_raw($_POST['page_url']);
        $expiration_hours = absint($_POST['expiration_hours']);
        $usage_type = sanitize_text_field($_POST['usage_type']);
        $token = bin2hex(random_bytes(16));

        // Calculate expiration time using WordPress time functions
        $expiration = date('Y-m-d H:i:s', current_time('timestamp') + ($expiration_hours * HOUR_IN_SECONDS));

        // Generate short URL
        $short_url = otpl_generate_short_url($token);

        // Store in database
        $wpdb->insert($table_name, [
            'token' => $token,
            'page_url' => $page_url,
            'short_url' => $short_url,
            'expiration' => $expiration,
            'is_used' => 0,
            'usage_type' => $usage_type
        ]);

        // Show success message
        $access_url = home_url('/?otpl_access=true&token=' . $token);
        echo '<div class="notice notice-success p-4 rounded-lg mb-4">'
            . '<p class="font-medium">' . __('New link generated!', 'otpl') . '</p>'
            . '<div class="mt-2 space-y-2">'
            . '<p><span class="font-semibold">' . __('Full URL:', 'otpl') . '</span> <a href="' . esc_url($access_url) . '" target="_blank" class="text-blue-600 hover:underline">' . esc_html($access_url) . '</a></p>'
            . ($short_url ? '<p><span class="font-semibold">' . __('Short URL:', 'otpl') . '</span> <a href="' . esc_url($short_url) . '" target="_blank" class="text-blue-600 hover:underline">' . esc_html($short_url) . '</a></p>' : '')
            . '<p><span class="font-semibold">' . __('Expires:', 'otpl') . '</span> ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($expiration)) . '</p>'
            . '</div></div>';
    }

    // Stats
    $total_links = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $active_links = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE is_used = 0 AND expiration > NOW()");
?>

    <div class="wrap">
        <h1 class="text-3xl font-bold text-gray-800 mb-6"><?php _e('Generate Private Link', 'otpl'); ?></h1>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white p-6 rounded-lg shadow-md border-l-4 border-blue-500">
                <h3 class="font-bold text-gray-700 mb-2"><?php _e('Total Links', 'otpl'); ?></h3>
                <p class="text-3xl font-bold text-blue-600"><?php echo esc_html($total_links); ?></p>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-md border-l-4 border-green-500">
                <h3 class="font-bold text-gray-700 mb-2"><?php _e('Active Links', 'otpl'); ?></h3>
                <p class="text-3xl font-bold text-green-600"><?php echo esc_html($active_links); ?></p>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-md border-l-4 border-purple-500">
                <h3 class="font-bold text-gray-700 mb-2"><?php _e('Expired Links', 'otpl'); ?></h3>
                <p class="text-3xl font-bold text-purple-600"><?php echo esc_html($total_links - $active_links); ?></p>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow-md">
            <form method="post">
                <?php wp_nonce_field('otpl_generate_link'); ?>

                <div class="space-y-6">
                    <div>
                        <label for="page_url" class="block text-sm font-medium text-gray-700 mb-1">
                            <?php _e('Destination URL', 'otpl'); ?>
                        </label>
                        <input
                            type="url"
                            id="page_url"
                            name="page_url"
                            required
                            class="w-full px-4 py-2 rounded-md border border-gray-300 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="https://example.com/private-page">
                    </div>

                    <div class="flex gap-4">
                        <div class="w-1/3">
                            <label for="usage_type" class="block text-sm font-medium text-gray-700 mb-1">
                                <?php _e('Usage Type', 'otpl'); ?>
                            </label>
                            <select
                                id="usage_type"
                                name="usage_type"
                                class="w-full px-4 py-2 rounded-md border border-gray-300 focus:ring-blue-500 focus:border-blue-500">
                                <option value="single"><?php _e('Single Use', 'otpl'); ?></option>
                                <option value="multiple"><?php _e('Multiple Use', 'otpl'); ?></option>
                            </select>
                        </div>

                        <div class="w-1/2">
                            <label for="expiration_hours" class="block text-sm font-medium text-gray-700 mb-1">
                                <?php _e('Expiration Time', 'otpl'); ?>
                            </label>
                            <select
                                id="expiration_hours"
                                name="expiration_hours"
                                class="w-full px-4 py-2 rounded-md border border-gray-300 focus:ring-blue-500 focus:border-blue-500">
                                <option value="1">1 <?php _e('Hour', 'otpl'); ?></option>
                                <option value="24" selected>24 <?php _e('Hours', 'otpl'); ?></option>
                                <option value="72">72 <?php _e('Hours', 'otpl'); ?></option>
                                <option value="168">7 <?php _e('Days', 'otpl'); ?></option>
                                <option value="720">30 <?php _e('Days', 'otpl'); ?></option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <button
                            type="submit"
                            name="generate_link"
                            class="px-6 py-3 bg-blue-600 text-white font-medium rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                            <?php _e('Generate Secure Link', 'otpl'); ?>
                        </button>
                    </div>

                </div>
            </form>
            <div class="mt-8  overflow-hidden">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-800"><?php _e('How It Works', 'otpl'); ?></h2>
                </div>
                <div class="p-6">
                    <ul class="list-disc pl-5 space-y-2 text-gray-700">
                        <li><?php _e('Links expire after the selected time period', 'otpl'); ?></li>
                        <li><?php _e('Choose between single-use or multiple-use links', 'otpl'); ?></li>
                        <li><?php _e('Perfect for sharing private documents or previews', 'otpl'); ?></li>
                        <li><?php _e('No login required for recipients', 'otpl'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
<?php
}

// ========================
// Logs Page
// ========================

function otpl_render_logs_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized access', 'otpl'));
    }

    global $wpdb;
    $logs_table = $wpdb->prefix . 'otpl_access_logs';
    $links_table = $wpdb->prefix . 'otpl_links';

    // Get logs with pagination
    $per_page = 20;
    $current_page = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
    $offset = ($current_page - 1) * $per_page;

    $logs = $wpdb->get_results($wpdb->prepare("
        SELECT l.*, a.accessed_at, a.ip_address, a.user_agent 
        FROM $links_table l
        JOIN $logs_table a ON l.token = a.token
        ORDER BY a.accessed_at DESC
        LIMIT %d OFFSET %d
    ", $per_page, $offset));

    $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM $logs_table");
    $total_pages = ceil($total_logs / $per_page);
?>

    <div class="wrap">
        <h1 class="text-3xl font-bold text-gray-800 mb-6"><?php _e('Access Logs', 'otpl'); ?></h1>

        <div class="bg-white shadow-md rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php _e('Token', 'otpl'); ?></th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php _e('Destination', 'otpl'); ?></th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php _e('Accessed', 'otpl'); ?></th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php _e('IP Address', 'otpl'); ?></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-500"><?php echo esc_html(substr($log->token, 0, 8) . '...'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <a href="<?php echo esc_url($log->page_url); ?>" target="_blank" class="text-blue-600 hover:underline">
                                    <?php echo esc_html(parse_url($log->page_url, PHP_URL_PATH) ?: $log->page_url); ?>
                                </a>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->accessed_at)); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo esc_html($log->ip_address); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-700">
                            <?php printf(__('Showing %d of %d logs', 'otpl'), count($logs), $total_logs); ?>
                        </span>
                        <div class="space-x-2">
                            <?php if ($current_page > 1): ?>
                                <a href="<?php echo add_query_arg('paged', $current_page - 1); ?>" class="px-4 py-2 border rounded-md text-sm font-medium hover:bg-gray-50">
                                    <?php _e('Previous', 'otpl'); ?>
                                </a>
                            <?php endif; ?>

                            <?php if ($current_page < $total_pages): ?>
                                <a href="<?php echo add_query_arg('paged', $current_page + 1); ?>" class="px-4 py-2 border rounded-md text-sm font-medium hover:bg-gray-50">
                                    <?php _e('Next', 'otpl'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php
}

// ========================
// Cleanup Expired Links
// ========================
// Schedule the cleanup event on plugin activation
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('otpl_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'otpl_daily_cleanup');
    }
});

// Cleanup function
function otpl_cleanup_expired_links()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'otpl_links';

    $wpdb->query($wpdb->prepare(
        "DELETE FROM $table_name 
        WHERE expiration < %s",
        current_time('mysql', true)
    ));
}

// Clean up the schedule on deactivation
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('otpl_daily_cleanup');
});

// ========================
// URL Shortening
// ========================
function otpl_generate_short_url($token)
{
    // In a real implementation, you would integrate with a URL shortener API
    // This is a simplified version that uses your own domain
    return home_url('/l/' . substr($token, 0, 8));
}

// ========================
// Access Control
// ========================
function otpl_handle_access_requests()
{
    if (!isset($_GET['otpl_access'])) return;

    global $wpdb;
    $token = sanitize_text_field($_GET['token'] ?? '');
    $table_name = $wpdb->prefix . 'otpl_links';

    // Get link from database
    $link = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE token = %s",
        $token
    ));

    // Validate link exists
    if (!$link) {
        wp_redirect(home_url());
        exit;
    }

    // Check expiration (using current time in MySQL format for proper comparison)
    $current_time = current_time('mysql', true);
    if ($link->expiration < $current_time) {
        wp_redirect(home_url());
        exit;
    }

    // For single-use links, check if already used
    if ($link->usage_type === 'single' && $link->is_used) {
        wp_redirect(home_url());
        exit;
    }

    // Mark as used if single-use
    if ($link->usage_type === 'single') {
        $wpdb->update(
            $table_name,
            ['is_used' => 1],
            ['id' => $link->id]
        );
    }

    // Log access
    otpl_log_access($token);

    // Redirect to target
    wp_redirect($link->page_url);
    exit;
}

function otpl_log_access($token)
{
    global $wpdb;
    $logs_table = $wpdb->prefix . 'otpl_access_logs';

    $wpdb->insert($logs_table, [
        'token' => $token,
        'ip_address' => $_SERVER['REMOTE_ADDR'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT']
    ]);
}
