<?php
if (!class_exists('SPSv2_Logger')) {

    class SPSv2_Logger {
        
        private static $instance;
        private $log_dir;
        private $log_file;
        private $max_size = 1048576; // 1MB
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
        private $current_level = 'debug';

        private function __construct() {
            $this->log_dir = WP_CONTENT_DIR . '/uploads/spsv2-logs/';
            $this->log_file = $this->log_dir . 'spsv2-' . date('Y-m') . '.log';
            
            $this->init_log_dir();
            $this->clean_old_logs();
            add_action('admin_menu', [$this, 'add_logs_page']);
        }

        public static function get_instance() {
            if (!self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function init_log_dir() {
            if (!file_exists($this->log_dir)) {
                wp_mkdir_p($this->log_dir);
                file_put_contents($this->log_dir . '.htaccess', "Deny from all");
            }
        }

        public static function log($message, $level = 'info', $context = []) {
            $self = self::get_instance();
            
            if ($self->should_log($level)) {
                $log_entry = $self->format_entry($message, $level, $context);
                file_put_contents($self->log_file, $log_entry, FILE_APPEND | LOCK_EX);
                $self->rotate_logs();
            }
        }

        private function should_log($level) {
            return $this->log_levels[$level] <= $this->log_levels[$this->current_level];
        }

        private function format_entry($message, $level, $context) {
            $timestamp = date('[Y-m-d H:i:s]');
            $level = strtoupper($level);
            $context_str = !empty($context) ? json_encode($context) : '';
            
            return sprintf("%s [%s] %s %s\n",
                $timestamp,
                str_pad($level, 8),
                $message,
                $context_str
            );
        }

        private function rotate_logs() {
            if (filesize($this->log_file) > $this->max_size) {
                $new_file = $this->log_dir . 'spsv2-' . date('Y-m-d-His') . '.log';
                rename($this->log_file, $new_file);
            }
        }

        private function clean_old_logs($days = 30) {
            $files = glob($this->log_dir . 'spsv2-*.log');
            $now = time();
            
            foreach ($files as $file) {
                if (filemtime($file) < $now - ($days * 86400)) {
                    unlink($file);
                }
            }
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
            
            if (isset($_POST['clear_logs'])) {
                $this->clear_current_log();
                echo '<div class="notice notice-success"><p>' 
                    . __('Current log file cleared', 'spsv2_text_domain') 
                    . '</p></div>';
            }

            echo '<form method="post">';
            echo '<textarea style="width:100%; height:500px; font-family: monospace;" readonly>';
            echo esc_textarea($this->get_logs());
            echo '</textarea><br/>';
            submit_button(
                __('Clear Current Log', 'spsv2_text_domain'),
                'delete',
                'clear_logs'
            );
            echo '</form>';
            echo '</div>';
        }

        private function get_logs() {
            return file_exists($this->log_file) ? 
                file_get_contents($this->log_file) : 
                __('No log entries found', 'spsv2_text_domain');
        }

        private function clear_current_log() {
            if (file_exists($this->log_file)) {
                file_put_contents($this->log_file, '');
            }
        }

        public static function get_log_files() {
            $self = self::get_instance();
            return glob($self->log_dir . 'spsv2-*.log');
        }

        public static function set_log_level($level) {
            $self = self::get_instance();
            if (array_key_exists($level, $self->log_levels)) {
                $self->current_level = $level;
            }
        }
    }

    // Inicialização
    SPSv2_Logger::get_instance();
}
