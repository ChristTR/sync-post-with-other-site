<?php
class SPSv2_Post_Meta {
    public static function init() {
        add_filter('manage_posts_columns', [__CLASS__, 'add_sync_column']);
        add_action('manage_posts_custom_column', [__CLASS__, 'render_sync_column'], 10, 2);
    }
    
    public static function add_sync_column($columns) {
        $columns['spsv2_sync_status'] = __('Sync Status', 'spsv2');
        return $columns;
    }
    
    public static function render_sync_column($column, $post_id) {
        if ($column === 'spsv2_sync_status') {
            $last_sync = get_post_meta($post_id, '_spsv2_synced', true);
            echo $last_sync ? date('d/m/Y H:i', $last_sync) : __('Not synced', 'spsv2');
        }
    }
}
