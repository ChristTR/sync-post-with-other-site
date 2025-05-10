<?php
class SPSv2_Settings {
    private static $option_name = 'spsv2_settings';
    
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }
    
    public static function activate() {
        update_option(self::$option_name, [
            'hosts' => [],
            'security' => [
                'jwt_secret' => bin2hex(random_bytes(32)),
                'force_ssl' => true
            ]
        ]);
    }
    
    public static function deactivate() {
        wp_clear_scheduled_hook('spsv2_process_queue');
    }
    
    public static function add_admin_menu() {
        add_menu_page(
            __('Sync Post Settings', 'spsv2'),
            __('Sync Post v2', 'spsv2'),
            'manage_options',
            'spsv2-settings',
            [__CLASS__, 'render_settings_page'],
            'dashicons-admin-generic'
        );
    }
    
    public static function register_settings() {
        register_setting('spsv2_settings_group', self::$option_name, [
            'sanitize_callback' => [__CLASS__, 'sanitize_settings']
        ]);
    }
    
    public static function sanitize_settings($input) {
        $new_input = [
            'security' => [
                'jwt_secret' => sanitize_text_field($input['security']['jwt_secret']),
                'force_ssl' => isset($input['security']['force_ssl'])
            ],
            'hosts' => []
        ];
        
        if (!empty($input['hosts'])) {
            foreach ($input['hosts'] as $host) {
                $new_input['hosts'][] = [
                    'url' => esc_url_raw($host['url']),
                    'secret' => sanitize_text_field($host['secret'])
                ];
            }
        }
        
        return $new_input;
    }
    
    public static function get_settings() {
        return get_option(self::$option_name);
    }
    
    public static function render_settings_page() {
        SPSv2_Settings_View::render(self::get_settings());
    }
}
