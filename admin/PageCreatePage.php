<?php
namespace GpcDev\Admin;

class PageCreatePage
{
    public function __construct()
    {
        add_action( 'admin_menu', array($this, 'register_menu') );

        add_action( 'wp_ajax_gpc_create_pages', [$this, 'gpc_create_pages'] );
    }

    public function register_menu()
    {
        // create top level submenu page which point to main menu page
        $hook = add_submenu_page(
            'gpc-dev-general', // parent slug
            'Tạo trang', // Page title
            'Tạo trang', // Menu title
            'manage_options', // capability
            'gpc-dev-create-pages', // menu slug
            array($this, 'settings_page') // callback
        );

        add_filter("gpc_dev_admin_pages", function($pages) use ($hook) {
            $pages[] = $hook;
            return $pages;
        });
    }

    public function gpc_create_pages()
    {
        $list = $_POST['list'];
        $results = [];

        foreach ($list as $item) {
            $page = wp_insert_post([
                'post_title' => $item,
                'post_content' => '',
                'post_status' => 'publish',
                'post_type' => 'page'
            ]);

            if ($page) {
                $results[] = $page;
            }
        }

        wp_send_json_success(['message' => 'Các trang đã được tạo: ' . implode(', ', $results)]);
    }

    public function settings_page()
    {
    ?>
        <div class="wrap" x-data="app">
            <h1>Tạo trang</h1>

            <!-- Thêm nhóm -->
            <div class="card mb3 max-w-lg">
                <div class="gpc-form is-horizontal">
                    <div class="form-group">
                        <label for="terms">Danh sách trang</label>
                        <textarea name="terms" rows="20" x-model="pages"></textarea>
                        <span class="help">Mỗi trang một hàng</span>
                        <span class="error-text" x-cloak x-show="error.pages" x-text="error.pages"></span>
                    </div>
                </div>
            </div>

            <p class="gpc-submit">
                <button class="button button-primary" @click="submit" :disabled="loading">Lưu lại</button>
                <span class="spinner" :style="loading && { visibility: 'visible' }"></span>
            </p>

            <div class="mt3 gpc-notice is-dismissible is-success" x-show="message" x-cloak>
                <p x-text="message"></p>
            </div>

            <!-- <pre x-html="JSON.stringify(taxonomies)"></pre>
            <pre x-html="JSON.stringify(groups)"></pre> -->
        </div>

        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('app', () => ({
                    pages: '',
                    error: {},
                    loading: false,
                    message: '',

                    arrayFromString(str) {
                        let arr = [];
                        if (str.includes("\n")) {
                            arr = str.split("\n");
                        } else {
                            arr = str.split(",");
                        }

                        return arr.map(x => x.trim());
                    },

                    submit() {
                        if (!this.pages) {
                            this.error.pages = 'Vui lòng nhập danh sách trang';
                            return;
                        }

                        let list = this.arrayFromString(this.pages).filter(x => x);

                        if (!list.length) {
                            this.error.pages = 'Vui lòng nhập danh sách trang';
                            return;
                        }

                        const that = this;

                        this.loading = true;
                        jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'gpc_create_pages',
                            list: list
                        }, function(response) {
                            that.loading = false;
                            that.message = response.data.message;
                            that.groups = [];

                            setTimeout(() => that.message = '', 5000)
                        })
                    }

                }))
            })
        </script>
    <?php
    }
}