<?php
class SPSv2_Logger {
    private static $log_table = 'spsv2_logs';
    
    public static function init() {
        register_activation_hook(__FILE__, [__CLASS__, 'create_log_table']);
    }
    
    public static function create_log_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$log_table;
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            level VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            context LONGTEXT,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public static function log($message, $level = 'info', $context = []) {
        global $wpdb;
        
        $wpdb->insert($wpdb->prefix . self::$log_table, [
            'level' => $level,
            'message' => $message,
            'context' => maybe_serialize($context),
            'created_at' => current_time('mysql')
        ]);
    }
}
