<?php
if (!class_exists('SPSv2_Logger')) {

    class SPSv2_Logger {
        
        private static $instance;
        private $log_dir;
        private $log_file;
        private $max_size;
        private $log_levels = [
            'emergency' => 0,
            'alert'     => 1,
            'critical'  => 2,
            'error'     => 3,
            'warning'   => 4,
            'notice'    => 5,
            'info'      => 6,
            'debug'     => 7
        ];
        private $current_level;

        private function __construct() {
            $this->init_config();
            $this->init_log_dir();
            $this->clean_old_logs();
            add_action('admin_menu', [$this, 'add_logs_page']);
            add_action('admin_init', [$this, 'handle_log_actions']);
        }

        private function init_config() {
            $this->log_dir = WP_CONTENT_DIR . '/uploads/spsv2-logs/';
            $this->max_size = apply_filters('spsv2_max_log_size', 5 * 1024 * 1024); // 5MB
            $this->current_level = defined('WP_DEBUG') && WP_DEBUG ? 'debug' : 'info';
            $this->log_file = $this->log_dir . 'spsv2-' . date('Y-m') . '.log';
        }

        private function init_log_dir() {
            if (!file_exists($this->log_dir)) {
                if (!wp_mkdir_p($this->log_dir)) {
                    error_log('SPSv2: Failed to create log directory');
                    return;
                }
                $this->protect_log_dir();
            }
        }

        private function protect_log_dir() {
            $htaccess = $this->log_dir . '.htaccess';
            $index = $this->log_dir . 'index.php';
            
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Order deny,allow\nDeny from all");
            }
            
            if (!file_exists($index)) {
                file_put_contents($index, "<?php\n// Silence is golden");
            }
        }

        public static function log($message, $level = 'info', $context = []) {
            $self = self::get_instance();
            
            if ($self->should_log($level)) {
                $log_entry = $self->format_entry($message, $level, $context);
                $self->write_log($log_entry);
            }
        }

        private function write_log($entry) {
            try {
                $result = file_put_contents(
                    $this->log_file, 
                    $entry, 
                    FILE_APPEND | LOCK_EX
                );

                if ($result === false) {
                    error_log('SPSv2: Failed to write log entry');
                } else {
                    $this->rotate_logs();
                }
            } catch (Exception $e) {
                error_log('SPSv2: Logging error - ' . $e->getMessage());
            }
        }

        private function format_entry($message, $level, $context) {
            $timestamp = current_time('mysql');
            $level = strtoupper($level);
            $context = $this->sanitize_context($context);
            
            return sprintf("[%s] %-8s %s %s\n",
                $timestamp,
                $level,
                wp_strip_all_tags($message),
                json_encode($context, JSON_UNESCAPED_SLASHES)
            );
        }

        private function sanitize_context($context) {
            if (isset($context['password']) || isset($context['app_password'])) {
                $context = array_map(function($item) {
                    return is_string($item) ? preg_replace('/password=([^\&]*)/i', 'password=***', $item) : $item;
                }, $context);
            }
            return $context;
        }

        public function add_logs_page() {
            add_submenu_page(
                'tools.php',
                __('SPSv2 Logs', 'spsv2_text_domain'),
                __('SPSv2 Logs', 'spsv2_text_domain'),
                'manage_options',
                'spsv2-logs',
                [$this, 'render_logs_page']
            );
        }

        public function render_logs_page() {
            if (!current_user_can('manage_options')) {
                wp_die(__('Access denied', 'spsv2_text_domain'));
            }

            echo '<div class="wrap">';
            echo '<h1>' . __('Sync Post v2 Logs', 'spsv2_text_domain') . '</h1>';
            
            $this->display_log_actions();
            $this->display_log_files_table();
            
            echo '</div>';
        }

        private function display_log_actions() {
            ?>
            <div class="spsv2-log-actions">
                <form method="post" style="margin-bottom: 20px;">
                    <?php wp_nonce_field('spsv2_log_actions', 'spsv2_log_nonce'); ?>
                    <select name="spsv2_log_file">
                        <?php foreach ($this->get_log_files() as $file) : ?>
                            <option value="<?php echo esc_attr(basename($file)); ?>">
                                <?php echo esc_html(basename($file)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button type="submit" name="action" value="view" class="button">
                        <?php _e('View', 'spsv2_text_domain'); ?>
                    </button>
                    
                    <button type="submit" name="action" value="delete" class="button button-danger"
                            onclick="return confirm('<?php _e('Are you sure?', 'spsv2_text_domain'); ?>')">
                        <?php _e('Delete', 'spsv2_text_domain'); ?>
                    </button>
                    
                    <button type="submit" name="action" value="download" class="button">
                        <?php _e('Download', 'spsv2_text_domain'); ?>
                    </button>
                </form>
            </div>
            <?php
        }

        private function display_log_files_table() {
            $current_file = $this->log_file;
            ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('File', 'spsv2_text_domain'); ?></th>
                        <th><?php _e('Size', 'spsv2_text_domain'); ?></th>
                        <th><?php _e('Last Modified', 'spsv2_text_domain'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->get_log_files() as $file) : ?>
                    <tr>
                        <td>
                            <?php echo esc_html(basename($file)); ?>
                            <?php if ($file === $current_file) : ?>
                                <span class="dashicons dashicons-star-filled"></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo size_format(filesize($file)); ?></td>
                        <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), filemtime($file)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }

        public function handle_log_actions() {
            if (!isset($_POST['spsv2_log_nonce']) || 
                !wp_verify_nonce($_POST['spsv2_log_nonce'], 'spsv2_log_actions')) {
                return;
            }

            $action = $_POST['action'] ?? '';
            $file = $this->log_dir . basename($_POST['spsv2_log_file']);

            switch ($action) {
                case 'delete':
                    if (file_exists($file)) {
                        unlink($file);
                        add_settings_error('spsv2_logs', 'log_deleted', __('Log file deleted', 'spsv2_text_domain'), 'success');
                    }
                    break;
                
                case 'download':
                    if (file_exists($file)) {
                        header('Content-Type: text/plain');
                        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
                        readfile($file);
                        exit;
                    }
                    break;
            }
        }

        // ... (métodos restantes mantidos com melhorias similares)
    }

    // Inicialização segura
    add_action('plugins_loaded', function() {
        SPSv2_Logger::get_instance();
    });
}
