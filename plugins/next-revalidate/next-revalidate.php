<?php
/**
 * Plugin Name: Next.js Revalidation (Headless Async Mode)
 * Plugin URI: https://github.com/9d8dev/next-wp
 * Description: Asynchronously revalidates specific Next.js paths (posts/pages) via background tasks on creation or update. Compatible with WordPress REST API and DataViews.
 * Version: 2.1.0
 * Author: 9d8
 * Author URI: https://9d8.dev
 * License: MIT
 */

if (!defined('ABSPATH')) {
    exit;
}

class NextRevalidate {
    private $option_name = 'next_revalidate_settings';
    private $log_option = 'next_revalidate_log';
    private $last_option = 'next_revalidate_last';
    private $cron_hook = 'next_revalidate_async_trigger';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Core Hooks for published/updated content (Handles REST API / DataViews bulk actions too)
        add_action('save_post', [$this, 'on_post_change'], 10, 3);
        add_action('delete_post', [$this, 'on_post_delete']);
        add_action('transition_post_status', [$this, 'on_status_change'], 10, 3);

        // Taxonomy links (only when attached to published content)
        add_action('set_object_terms', [$this, 'on_taxonomy_change'], 10, 6);

        // Background Cron execution hook
        add_action($this->cron_hook, [$this, 'execute_async_revalidation'], 10, 2);
    }

    public function add_admin_menu() {
        add_options_page(
            'Next.js Revalidation',
            'Next.js Revalidation',
            'manage_options',
            'next-revalidate',
            [$this, 'settings_page']
        );
    }

    public function register_settings() {
        register_setting($this->option_name, $this->option_name, [
            'sanitize_callback' => [$this, 'sanitize_settings']
        ]);

        add_settings_section('next_revalidate_main', 'Configuration', null, 'next-revalidate');

        add_settings_field('nextjs_url', 'Next.js Site URL', [$this, 'field_nextjs_url'], 'next-revalidate', 'next_revalidate_main');
        add_settings_field('webhook_secret', 'Webhook Secret', [$this, 'field_webhook_secret'], 'next-revalidate', 'next_revalidate_main');
        add_settings_field('delay_seconds', 'Background Delay (seconds)', [$this, 'field_delay_seconds'], 'next-revalidate', 'next_revalidate_main');
        add_settings_field('max_retries', 'Max Retries', [$this, 'field_max_retries'], 'next-revalidate', 'next_revalidate_main');
        add_settings_field('debug_mode', 'Debug Mode', [$this, 'field_debug_mode'], 'next-revalidate', 'next_revalidate_main');
    }

    public function sanitize_settings($input) {
        $sanitized = [];
        $sanitized['nextjs_url'] = esc_url_raw(rtrim($input['nextjs_url'] ?? '', '/'));
        $sanitized['webhook_secret'] = sanitize_text_field($input['webhook_secret'] ?? '');
        $sanitized['delay_seconds'] = max(1, absint($input['delay_seconds'] ?? 5));
        $sanitized['max_retries'] = min(10, max(0, absint($input['max_retries'] ?? 3)));
        $sanitized['debug_mode'] = !empty($input['debug_mode']);
        return $sanitized;
    }

    public function field_nextjs_url() {
        $options = get_option($this->option_name);
        $value = $options['nextjs_url'] ?? '';
        echo '<input type="url" name="' . $this->option_name . '[nextjs_url]" value="' . esc_attr($value) . '" class="regular-text" placeholder="https://your-nextjs-site.com" />';
    }

    public function field_webhook_secret() {
        $options = get_option($this->option_name);
        $value = $options['webhook_secret'] ?? '';
        echo '<input type="text" name="' . $this->option_name . '[webhook_secret]" value="' . esc_attr($value) . '" class="regular-text" />';
    }

    public function field_delay_seconds() {
        $options = get_option($this->option_name);
        $value = $options['delay_seconds'] ?? 5;
        echo '<input type="number" name="' . $this->option_name . '[delay_seconds]" value="' . esc_attr($value) . '" min="1" max="300" class="small-text" /> seconds';
        echo '<p class="description">How long WordPress waits before triggering the Next.js background request.</p>';
    }

    public function field_max_retries() {
        $options = get_option($this->option_name);
        $value = $options['max_retries'] ?? 3;
        echo '<input type="number" name="' . $this->option_name . '[max_retries]" value="' . esc_attr($value) . '" min="0" max="10" class="small-text" />';
    }

    public function field_debug_mode() {
        $options = get_option($this->option_name);
        $checked = !empty($options['debug_mode']) ? 'checked' : '';
        echo '<label><input type="checkbox" name="' . $this->option_name . '[debug_mode]" value="1" ' . $checked . ' /> Enable debug logging</label>';
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        $last = get_option($this->last_option);
        $log = get_option($this->log_option, []);
        ?>
        <div class="wrap">
            <h1>Next.js Targeted Revalidation Settings</h1>
            <?php if ($last): ?>
            <div class="notice notice-<?php echo $last['success'] ? 'success' : 'error'; ?>">
                <p><strong>Last Background Process:</strong> <?php echo esc_html(date('Y-m-d H:i:s', $last['time'])); ?> — Path Type: <?php echo esc_html($last['type']); ?> — Status: <?php echo $last['success'] ? '✓ Success' : '✗ Failed'; ?></p>
            </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields($this->option_name); do_settings_sections('next-revalidate'); submit_button(); ?>
            </form>

            <hr>
            <h2>Recent Targeted Logs (Max 50)</h2>
            <table class="widefat striped">
                <thead>
                    <tr><th>Time</th><th>Type</th><th>Target Path/Slug</th><th>Action</th><th>Status</th><th>HTTP</th></tr>
                </thead>
                <tbody>
                    <?php if (!empty($log)): foreach ($log as $entry): ?>
                    <tr>
                        <td><?php echo esc_html(date('Y-m-d H:i:s', $entry['time'])); ?></td>
                        <td><?php echo esc_html($entry['type']); ?></td>
                        <td><code>/<?php echo esc_html(($entry['data']['type'] ?? '') . '/' . ($entry['data']['slug'] ?? '')); ?></code></td>
                        <td><?php echo esc_html($entry['data']['action'] ?? '-'); ?></td>
                        <td><?php echo $entry['success'] ? '<span style="color:green;">✓</span>' : '<span style="color:red;">✗</span>'; ?></td>
                        <td><?php echo esc_html($entry['http_code'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; else: ?><tr><td colspan="6">No recent items.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function on_post_change($post_id, $post, $update) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if ($post->post_status !== 'publish') return;
        if (!in_array($post->post_type, ['post', 'page'])) return;

        $this->schedule_revalidation('post', [
            'id' => $post_id,
            'slug' => $post->post_name,
            'type' => $post->post_type,
            'action' => $update ? 'update' : 'create'
        ]);
    }

    public function on_post_delete($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish' || !in_array($post->post_type, ['post', 'page'])) return;

        $this->schedule_revalidation('post', [
            'id' => $post_id,
            'slug' => $post->post_name,
            'type' => $post->post_type,
            'action' => 'delete'
        ]);
    }

    public function on_status_change($new_status, $old_status, $post) {
        if ($new_status === $old_status || !in_array($post->post_type, ['post', 'page'])) return;

        if ($old_status === 'publish' || $new_status === 'publish') {
            $this->schedule_revalidation('post', [
                'id' => $post->ID,
                'slug' => $post->post_name,
                'type' => $post->post_type,
                'action' => 'status_change'
            ]);
        }
    }

    public function on_taxonomy_change($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        if (!in_array($taxonomy, ['category', 'post_tag'])) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        $post = get_post($object_id);
        if (!$post || $post->post_status !== 'publish' || !in_array($post->post_type, ['post', 'page'])) return;

        $this->schedule_revalidation('taxonomy', [
            'id' => $object_id,
            'slug' => $post->post_name,
            'type' => $post->post_type,
            'taxonomy' => $taxonomy,
            'action' => 'update'
        ]);
    }

    private function schedule_revalidation($type, $data) {
        $options = get_option($this->option_name);
        if (empty($options['nextjs_url'])) return;

        $delay = $options['delay_seconds'] ?? 5;
        $args = [$type, $data];
        
        if (!wp_next_scheduled($this->cron_hook, $args)) {
            wp_schedule_single_event(time() + $delay, $this->cron_hook, $args);
            $this->debug_log("Scheduled async revalidation for: " . ($data['slug'] ?? ''), $data);
        }
    }

    public function execute_async_revalidation($type, $data) {
        $options = get_option($this->option_name);
        $max_retries = $options['max_retries'] ?? 3;

        $result = $this->send_with_retry($type, $data, $max_retries);

        update_option($this->last_option, [
            'time' => time(),
            'type' => $type,
            'success' => $result['success'],
            'http_code' => $result['http_code'],
            'error' => $result['error']
        ]);

        $log = get_option($this->log_option, []);
        array_unshift($log, [
            'time' => time(),
            'type' => $type,
            'data' => $data,
            'success' => $result['success'],
            'http_code' => $result['http_code'],
            'error' => $result['error']
        ]);
        update_option($this->log_option, array_slice($log, 0, 50));
    }

    private function send_with_retry($type, $data, $max_retries, $attempt = 0) {
        $result = $this->send_request($type, $data);

        if (!$result['success'] && $attempt < $max_retries) {
            sleep(pow(2, $attempt)); 
            return $this->send_with_retry($type, $data, $max_retries, $attempt + 1);
        }
        return $result;
    }

    private function send_request($type, $data) {
        $options = get_option($this->option_name);
        $url = $options['nextjs_url'] . '/api/revalidate';
        
        $payload = [
            'target' => [
                'type' => $data['type'] ?? 'post', 
                'slug' => $data['slug'] ?? '',     
                'id'   => $data['id'] ?? null       
            ],
            'event' => $data['action'] ?? 'update',
            'timestamp' => time()
        ];

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-webhook-secret' => $options['webhook_secret'] ?? ''
            ],
            'body' => json_encode($payload)
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'http_code' => null, 'error' => $response->get_error_message()];
        }

        $http_code = wp_remote_retrieve_response_code($response);
        return [
            'success' => $http_code === 200,
            'http_code' => $http_code,
            'error' => $http_code === 200 ? null : "HTTP status {$http_code}"
        ];
    }

    private function debug_log($message, $data = null) {
        $options = get_option($this->option_name);
        if (empty($options['debug_mode'])) return;
        error_log('[Next.js Revalidation] ' . $message . ($data ? ' - ' . json_encode($data) : ''));
    }
}

add_action('init', function() {
    new NextRevalidate();
});