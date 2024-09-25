<?php
namespace GpcDev\Admin;

use GpcDev\Includes\LoremIpsum;
use GpcDev\Includes\LoremPicsum;

class PageRandomPost
{
    public function __construct()
    {
        add_action( 'admin_menu', array($this, 'register_menu') );

        add_action( 'wp_ajax_gpc_create_random_posts', [$this, 'gpc_create_random_posts'] );
    }

    public function register_menu()
    {
        // create top level submenu page which point to main menu page
        $hook = add_submenu_page(
            'gpc-dev-general', // parent slug
            'Bài viết ngẫu nhiên', // Page title
            'Bài viết ngẫu nhiên', // Menu title
            'manage_options', // capability
            'gpc-dev-random-posts', // menu slug
            array($this, 'settings_page') // callback
        );

        add_filter("gpc_dev_admin_pages", function($pages) use ($hook) {
            $pages[] = $hook;
            return $pages;
        });
    }

    public function gpc_create_random_posts()
    {
        $count = $_POST['count'];
        $image_width = $_POST['image_width'];
        $image_height = $_POST['image_height'];
        $post_type = 'post';

        $lipsum = new LoremIpsum();
        $picsum_ids = LoremPicsum::generate_image_ids($count);
        $results = [];

        for ($i = 0; $i < $count; $i++) {
            $title = ucfirst($lipsum->words(rand(5, 8)));
            $content = $this->generate_content($lipsum);
            $attachment_id = LoremPicsum::download_image($picsum_ids[$i], $image_width, $image_height);

            $args = [
                'post_title' => $title,
                'post_type' => $post_type,
                'post_content' => $content,
                'post_status' => 'publish',
            ];

            $post_id = wp_insert_post($args);

            if(!is_wp_error($post_id) && !is_wp_error($attachment_id)) {
                set_post_thumbnail($post_id, $attachment_id);
            }

            $results[] = $post_id;
        }

        wp_send_json_success(['message' => 'Các bài viết đã được tạo: ' . implode(', ', $results)]);
    }

    private function generate_content($lipsum)
    {
        $html = '';
        $html .= '<h2>'.ucfirst($lipsum->words(rand(5, 10))).'</h2>';
        $html .= $lipsum->paragraphs(rand(3, 5), ['p']);
        $html .= '<img src="https://picsum.photos/id/' . rand(100, 200) . '/800/600' . '" />';
        $html .= '<h2>'.ucfirst($lipsum->words(rand(5, 10))).'</h2>';
        $html .= $lipsum->paragraphs(rand(3, 5), ['p']);

        return $html;
    }

    public function settings_page()
    {
        ?>
        <div class="wrap" x-data="app">
            <h1>Tạo bài viết ngẫu nhiên</h1>

            <div class="card mb3 max-w-full">
                <div class="gpc-form gap-4">
                    <div class="form-group">
                        <label>Số lượng</label>
                        <input type="number" x-model="count" class="w-25" />
                        <span class="help">Số lượng bài viết sẽ được tạo ra.</span>
                    </div>

                    <div class="form-group">
                        <label>Kích thước hình đại diện (width x height)</label>
                        <div class="flex items-center gap-2">
                            <input type="number" x-model="image_width" class="w-25" />
                            <span>x</span>
                            <input type="number" x-model="image_height" class="w-25" />
                        </div>
                    </div>

                </div> <!-- .gpc-form -->
            </div> <!-- .card -->

            <p class="gpc-submit">
                <button class="button button-primary" @click="submit" :disabled="loading">Thực hiện</button>
                <span class="spinner" :style="loading && { visibility: 'visible' }"></span>
            </p>

            <div class="mt3 gpc-notice is-dismissible is-success" x-show="message" x-cloak>
                <p x-text="message"></p>
            </div>
        </div>

        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('app', () => ({
                    count: 15,
                    loading: false,
                    message: '',
                    image_width: 1000,
                    image_height: 750,

                    submit() {
                        if (this.count <= 0) {
                            return;
                        }

                        const that = this;

                        this.loading = true;
                        jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'gpc_create_random_posts',
                            count: this.count,
                            image_width: this.image_width,
                            image_height: this.image_height,
                        }, function(response) {
                            that.loading = false;
                            that.message = response.data.message;
                        })
                    },
                }))
            })
        </script>
        <?php
    }
}