<?php
if (!class_exists('SPSv2_Sync')) {

    class SPSv2_Sync {

        private $settings;
        private $logger;
        private $queue;
        private $max_retries = 3;
        private $retry_delay = 300; // 5 minutos

        public function __construct() {
            $this->settings = get_option('spsv2_settings');
            $this->logger = SPSv2_Logger::get_instance();
            $this->queue = new SPSv2_Queue();
            
            add_action('save_post', [$this, 'handle_post_save'], 10, 3);
            add_action('spsv2_process_queue', [$this, 'process_queue']);
            add_action('spsv2_retry_failed', [$this, 'retry_failed_syncs']);
            
            $this->schedule_events();
        }

        private function schedule_events() {
            if (!wp_next_scheduled('spsv2_process_queue')) {
                wp_schedule_event(time(), 'five_minutes', 'spsv2_process_queue');
            }
            
            if (!wp_next_scheduled('spsv2_retry_failed')) {
                wp_schedule_event(time(), 'daily', 'spsv2_retry_failed');
            }
        }

        public function handle_post_save($post_id, $post, $update) {
            if (!$this->should_sync($post_id, $post)) return;

            $selected_hosts = SPSv2_Post_Meta::get_selected_hosts($post_id);
            
            foreach ($selected_hosts as $host_key => $host_config) {
                $this->queue->add_job([
                    'post_id' => $post_id,
                    'host_key' => $host_key,
                    'attempt' => 0
                ]);
            }
        }

        public function process_queue() {
            $batch = $this->queue->get_batch(10);
            
            foreach ($batch as $job) {
                $this->process_job($job);
            }
        }

        private function process_job($job) {
            $post = get_post($job['post_id']);
            $host_config = $this->settings['hosts'][$job['host_key']] ?? null;

            if (!$post || !$host_config) {
                $this->queue->delete_job($job);
                return;
            }

            $this->sync_post($post, $host_config, $job);
        }

        public function sync_post($post, $host_config, $job = []) {
            $remote_id = $this->get_remote_id($post->ID, $host_config['url']);
            $api_url = $this->prepare_api_url($host_config['url'], $remote_id);

            $request_args = $this->prepare_request($post, $host_config);
            $response = wp_remote_post($api_url, $request_args);

            $this->handle_response($response, $post->ID, $host_config, $job, $remote_id);
        }

        private function prepare_api_url($base_url, $remote_id = null) {
            $url = trailingslashit($base_url) . 'wp-json/wp/v2/posts';
            return $remote_id ? $url . '/' . $remote_id : $url;
        }

        private function prepare_request($post, $host_config) {
            $data = [
                'title' => $post->post_title,
                'content' => $this->process_content_images($post->post_content, $host_config),
                'status' => 'publish',
                'meta' => $this->prepare_meta_data($post->ID),
                'yoast_meta' => $this->prepare_yoast_meta($post->ID),
                'taxonomies' => $this->prepare_taxonomies($post->ID)
            ];

            // Featured Image
            if ($featured_image_id = get_post_thumbnail_id($post->ID)) {
                $remote_image_id = $this->sync_media($featured_image_id, $host_config);
                if ($remote_image_id) {
                    $data['featured_media'] = $remote_image_id;
                }
            }

            return apply_filters('spsv2_pre_sync_request', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode(
                        $host_config['username'] . ':' . $host_config['app_password']
                    ),
                    'Content-Type' => 'application/json'
                ],
                'body' => wp_json_encode($data),
                'timeout' => 30
            ], $post, $host_config);
        }

        private function process_content_images($content, $host_config) {
            preg_match_all('/<img[^>]+src="([^"]+)"[^>]*>/i', $content, $matches);
            
            foreach ($matches[1] as $image_url) {
                $local_image_id = attachment_url_to_postid($image_url);
                if ($local_image_id) {
                    $remote_image_id = $this->sync_media($local_image_id, $host_config);
                    if ($remote_image_id) {
                        $remote_url = $this->get_remote_image_url($remote_image_id, $host_config);
                        $content = str_replace($image_url, $remote_url, $content);
                    }
                }
            }
            
            return $content;
        }

        private function sync_media($image_id, $host_config) {
            $existing_remote_id = get_post_meta($image_id, "_spsv2_media_{$host_config['url']}", true);
            if ($existing_remote_id) return $existing_remote_id;

            $image_path = get_attached_file($image_id);
            $image_data = wp_get_attachment_metadata($image_id);
            $mime_type = get_post_mime_type($image_id);

            $api_url = trailingslashit($host_config['url']) . 'wp-json/wp/v2/media';
            
            $response = wp_remote_post($api_url, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode(
                        $host_config['username'] . ':' . $host_config['app_password']
                    ),
                    'Content-Disposition' => 'attachment; filename="' . basename($image_path) . '"',
                    'Content-Type' => $mime_type
                ],
                'body' => file_get_contents($image_path),
                'timeout' => 30
            ]);

            if (wp_remote_retrieve_response_code($response) === 201) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                update_post_meta($image_id, "_spsv2_media_{$host_config['url']}", $body['id']);
                return $body['id'];
            }

            return null;
        }

        private function prepare_taxonomies($post_id) {
            $taxonomies = [];
            $post_type = get_post_type($post_id);
            $supported_taxonomies = get_object_taxonomies($post_type, 'names');

            foreach ($supported_taxonomies as $taxonomy) {
                $terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'all']);
                
                foreach ($terms as $term) {
                    $taxonomies[$taxonomy][] = [
                        'name' => $term->name,
                        'slug' => $term->slug,
                        'description' => $term->description
                    ];
                }
            }

            return $taxonomies;
        }

        private function handle_response($response, $post_id, $host_config, $job, $remote_id = null) {
            $status_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($status_code === 201 || $status_code === 200) {
                $this->handle_success($post_id, $body, $host_config, $remote_id);
                $this->queue->delete_job($job);
            } else {
                $this->handle_error($post_id, $host_config, $job, $status_code, $body);
            }
        }

        private function handle_success($post_id, $body, $host_config, $existing_remote_id) {
            $remote_id = $existing_remote_id ?: $body['id'];
            
            update_post_meta(
                $post_id,
                "_spsv2_sync_{$host_config['url']}",
                [
                    'remote_id' => $remote_id,
                    'last_sync' => current_time('mysql'),
                    'status' => 'success'
                ]
            );

            // Sync taxonomies after post creation
            $this->sync_taxonomies($post_id, $remote_id, $host_config);
        }

        private function sync_taxonomies($source_post_id, $remote_post_id, $host_config) {
            $taxonomies = $this->prepare_taxonomies($source_post_id);
            $api_url = trailingslashit($host_config['url']) . "wp-json/wp/v2/posts/{$remote_post_id}";

            foreach ($taxonomies as $taxonomy => $terms) {
                $remote_terms = [];
                
                foreach ($terms as $term) {
                    $remote_term_id = $this->get_or_create_remote_term($term, $taxonomy, $host_config);
                    if ($remote_term_id) {
                        $remote_terms[] = $remote_term_id;
                    }
                }
                
                if (!empty($remote_terms)) {
                    wp_remote_post($api_url, [
                        'headers' => [
                            'Authorization' => 'Basic ' . base64_encode(
                                $host_config['username'] . ':' . $host_config['app_password']
                            ),
                            'Content-Type' => 'application/json'
                        ],
                        'body' => wp_json_encode([$taxonomy => $remote_terms])
                    ]);
                }
            }
        }

        private function get_or_create_remote_term($term, $taxonomy, $host_config) {
            $api_url = trailingslashit($host_config['url']) . 'wp-json/wp/v2/' . $taxonomy;
            
            // Verifica se o termo já existe
            $search = wp_remote_get(add_query_arg(['search' => $term['name']), $api_url), [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode(
                        $host_config['username'] . ':' . $host_config['app_password']
                    )
                ]
            ]);

            if (wp_remote_retrieve_response_code($search) === 200) {
                $terms = json_decode(wp_remote_retrieve_body($search));
                foreach ($terms as $remote_term) {
                    if ($remote_term->name === $term['name']) {
                        return $remote_term->id;
                    }
                }
            }

            // Cria novo termo
            $response = wp_remote_post($api_url, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode(
                        $host_config['username'] . ':' . $host_config['app_password']
                    ),
                    'Content-Type' => 'application/json'
                ],
                'body' => wp_json_encode([
                    'name' => $term['name'],
                    'slug' => $term['slug'],
                    'description' => $term['description']
                ])
            ]);

            if (wp_remote_retrieve_response_code($response) === 201) {
                $body = json_decode(wp_remote_retrieve_body($response));
                return $body->id;
            }

            return null;
        }

        private function handle_error($post_id, $host_config, $job, $status_code, $body) {
            $attempt = $job['attempt'] + 1;
            
            if ($attempt < $this->max_retries) {
                $this->queue->retry_job($job, $this->retry_delay);
                $this->logger::log(
                    "Tentativa $attempt falhou. Nova tentativa agendada.",
                    'warning',
                    ['post_id' => $post_id, 'host' => $host_config['url']]
                );
            } else {
                $this->queue->delete_job($job);
                $this->logger::log(
                    "Sincronização falhou após $attempt tentativas",
                    'error',
                    [
                        'post_id' => $post_id,
                        'host' => $host_config['url'],
                        'status' => $status_code,
                        'response' => $body
                    ]
                );
            }
        }

        public function retry_failed_syncs() {
            $failed_jobs = $this->queue->get_failed_jobs();
            
            foreach ($failed_jobs as $job) {
                $this->queue->retry_job($job, $this->retry_delay * 2);
            }
        }

        private function get_remote_id($post_id, $host_url) {
            $meta = get_post_meta($post_id, "_spsv2_sync_$host_url", true);
            return $meta['remote_id'] ?? null;
        }

        private function get_remote_image_url($remote_image_id, $host_config) {
            $api_url = trailingslashit($host_config['url']) . "wp-json/wp/v2/media/$remote_image_id";
            $response = wp_remote_get($api_url, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode(
                        $host_config['username'] . ':' . $host_config['app_password']
                    )
                ]
            ]);

            if (wp_remote_retrieve_response_code($response) === 200) {
                $body = json_decode(wp_remote_retrieve_body($response));
                return $body->source_url;
            }

            return null;
        }
    }

    class SPSv2_Queue {
        private $table_name;

        public function __construct() {
            global $wpdb;
            $this->table_name = $wpdb->prefix . 'spsv2_queue';
            
            $this->create_table();
        }

        private function create_table() {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            
            $sql = "CREATE TABLE $this->table_name (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                job_data TEXT NOT NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                last_attempt DATETIME,
                next_attempt DATETIME,
                PRIMARY KEY (id)
            ) " . $this->get_charset_collate();
            
            dbDelta($sql);
        }

        public function add_job($data) {
            global $wpdb;
            
            $wpdb->insert(
                $this->table_name,
                [
                    'job_data' => json_encode($data),
                    'next_attempt' => current_time('mysql')
                ]
            );
        }

        public function get_batch($limit = 10) {
            global $wpdb;
            
            $now = current_time('mysql');
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $this->table_name
                    WHERE next_attempt <= %s
                    ORDER BY next_attempt ASC
                    LIMIT %d",
                    $now,
                    $limit
                ),
                ARRAY_A
            );
        }

        public function retry_job($job, $delay) {
            global $wpdb;
            
            $wpdb->update(
                $this->table_name,
                [
                    'attempts' => $job['attempts'] + 1,
                    'last_attempt' => current_time('mysql'),
                    'next_attempt' => date('Y-m-d H:i:s', time() + $delay)
                ],
                ['id' => $job['id']]
            );
        }

        public function delete_job($job) {
            global $wpdb;
            $wpdb->delete($this->table_name, ['id' => $job['id']]);
        }

        public function get_failed_jobs() {
            global $wpdb;
            
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $this->table_name
                    WHERE attempts >= %d",
                    $this->max_retries
                ),
                ARRAY_A
            );
        }

        private function get_charset_collate() {
            global $wpdb;
            return $wpdb->get_charset_collate();
        }
    }
}
