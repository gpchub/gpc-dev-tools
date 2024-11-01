<?php
namespace GpcDev\Admin;

use DOMDocument;
use GpcDev\Includes\CsvHelper;
use GpcDev\Includes\ImportHelper;
use stdClass;
use WP_Error;

class PageImportPost
{
    public function __construct()
    {
        add_action( 'admin_menu', array($this, 'register_menu') );

        add_action( 'wp_ajax_gpc_import_posts', [$this, 'gpc_import_posts'] );
    }

    public function register_menu()
    {
        // create top level submenu page which point to main menu page
        $hook = add_submenu_page(
            'gpc-dev-general', // parent slug
            'Import posts', // Page title
            'Import posts', // Menu title
            'manage_options', // capability
            'gpc-dev-import-posts', // menu slug
            array($this, 'settings_page') // callback
        );

        add_filter("gpc_dev_admin_pages", function($pages) use ($hook) {
            $pages[] = $hook;
            return $pages;
        });
    }

	public function settings_page()
    {
    ?>
        <div class="wrap" x-data="app">
            <h1>Import posts</h1>

            <!-- Thêm nhóm -->
            <div class="card mb3 max-w-lg">
                <div class="gpc-form is-horizontal">
                    <div class="form-group">
                        <label for="content">File</label>
                        <input type="file" x-ref="file" />
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
                    content: '',
                    error: {},
                    loading: false,
                    message: '',
					jsonResponse: '',
					lastResponseLen: false,

                    submit() {
                        const that = this;

                        this.loading = true;
                        var form_data = new FormData();
                        form_data.append('import', this.$refs.file.files[0]);
                        form_data.append('action', 'gpc_import_posts');

                        jQuery.ajax({
                            url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                            method: 'POST',
                            data: form_data,
                            contentType: false,
                            processData: false,
                        }).then(response => {
                            that.message = response.data.message;
                        }).always(function () {
							that.loading = false
						});
                    }

                }))
            })
        </script>
    <?php
    }

    public function gpc_import_posts()
    {
        $uploadfile = wp_import_handle_upload();

        $id = (int) $uploadfile['id'];
		$file = get_attached_file($id);

        $h = new CsvHelper;

		$handle = $h->fopen($file, 'r');
        if ( $handle == false ) {
			wp_import_cleanup($id);
			wp_send_json_error([
                'message' => 'Failed to open file.',
            ]);
		}

        $is_first = true;
		$post_statuses = get_post_stati();
		$columns = new stdClass;
		$count = 0;
		$results = [];
		$errors = [];

		while (($row = $h->fgetcsv($handle)) !== FALSE) {
			if ($is_first) {
				$h->parse_columns( $columns, $row );
				$is_first = false;
			} else {
				$post = array();
				$is_update = false;
				$error = new WP_Error();
				$count++;

				// (string) post type
				$post_type = $h->get_data($columns, $row, 'post_type');
				$post['post_type'] = 'post';
				if ($post_type && post_type_exists($post_type)) {
					$post['post_type'] = $post_type;
				}

				// (int) post id
				$post_id = $h->get_data($columns, $row, 'ID');
				$post_id = ($post_id) ? $post_id : $h->get_data($columns, $row, 'post_id');
				if ($post_id) {
					$post_exist = get_post($post_id);
					if ( is_null( $post_exist ) ) { // if the post id is not exists
						$post['import_id'] = $post_id;
					} else {
						if ( $post_exist->post_type == $post_type ) {
							$post['ID'] = $post_id;
							$is_update = true;
						}
					}
				}

				// (login or ID) post_author
				$post_author = $h->get_data($columns, $row, 'post_author');
				if ($post_author) {
					if (is_numeric($post_author)) {
						$user = get_user_by('id', $post_author);
					} else {
						$user = get_user_by('login', $post_author);
					}
					if (isset($user) && is_object($user)) {
						$post['post_author'] = $user->ID;
						unset($user);
					}
				}

				// (string) publish date
				$post_date = $h->get_data($columns, $row, 'post_date');
				if ($post_date) {
					$post['post_date'] = date("Y-m-d H:i:s", strtotime($post_date));
				}
				$post_date_gmt = $h->get_data($columns, $row, 'post_date_gmt');
				if ($post_date_gmt) {
					$post['post_date_gmt'] = date("Y-m-d H:i:s", strtotime($post_date_gmt));
				}

				// (string) post status
				$post_status = $h->get_data($columns, $row, 'post_status');
				if ($post_status && in_array($post_status, $post_statuses)) {
    				$post['post_status'] = $post_status;
				}

				// (string) post password
				$post_password = $h->get_data($columns, $row, 'post_password');
				if ($post_password) {
    				$post['post_password'] = $post_password;
				}

				// (string) post title
				$post_title = $h->get_data($columns, $row, 'post_title');
				if ($post_title) {
					$post['post_title'] = $post_title;
				}

				// (string) post slug
				$post_name = $h->get_data($columns, $row, 'post_name');
				if ($post_name) {
					$post['post_name'] = $post_name;
				}

				// (string) post content
				$post_content = $h->get_data($columns, $row, 'post_content');
				if ($post_content) {
					$post['post_content'] = ImportHelper::processContent($post_content);
				}

				// (string) post excerpt
				$post_excerpt = $h->get_data($columns, $row, 'post_excerpt');
				if ($post_excerpt) {
					$post['post_excerpt'] = $post_excerpt;
				}

				// (int) post parent
				$post_parent = $h->get_data($columns, $row, 'post_parent');
				if ($post_parent) {
					$post['post_parent'] = $post_parent;
				}

				// (int) menu order
				$menu_order = $h->get_data($columns, $row, 'menu_order');
				if ($menu_order) {
					$post['menu_order'] = $menu_order;
				}

				// (string) comment status
				$comment_status = $h->get_data($columns, $row, 'comment_status');
				if ($comment_status) {
					$post['comment_status'] = $comment_status;
				}

				// (string, comma separated) slug of post categories
				$post_category = $h->get_data($columns, $row, 'post_category');
				if ($post_category) {
					$categories = preg_split("/,+/", $post_category);
					if ($categories) {
						$post['post_category'] = wp_create_categories($categories);
					}
				}

				// (string, comma separated) name of post tags
				$post_tags = $h->get_data($columns, $row, 'post_tags');
				if ($post_tags) {
					$post['post_tags'] = $post_tags;
				}

				// (string) post thumbnail image uri
				$post_thumbnail = $h->get_data($columns, $row, 'post_thumbnail');

				$meta = array();
				$tax = array();

				// add any other data to post meta
				foreach ($row as $key => $value) {
					if ($value !== false && isset($columns->column_keys[$key])) {
						// check if meta is custom taxonomy
						if (substr($columns->column_keys[$key], 0, 4) == 'tax_') {
							// (string, comma divided) name of custom taxonomies
							$customtaxes = preg_split("/,+/", $value);
							$taxname = substr($columns->column_keys[$key], 4);
							$tax[$taxname] = array();
							foreach($customtaxes as $key => $value ) {
								$tax[$taxname][] = $value;
							}
						}
						else {
							$meta[$columns->column_keys[$key]] = $value;
						}
					}
				}

				/**
				 * Chạy mà không import thực sự
				 * @param bool false
				 */
				$dry_run = apply_filters( 'gpc_dev_tools_csv_importer_dry_run', false );

				if (!$error->get_error_codes() && $dry_run == false) {

					$result = $this->save_post($post, $meta, $tax, $post_thumbnail, $is_update);

					if ($result->isError()) {
						$error = $result->getError();
					} else {
						$post_object = $result->getPost();
						$results[] = $post_object->ID;
					}
				}

				$errors = $error->get_error_messages();
			} // end else $is_first
		} // end while

        $h->fclose($handle);

		wp_import_cleanup($id);
        wp_send_json_success([
			'message' => 'Các bài viết đã được tạo: ' . implode(',', $results),
			'errors' => $errors
		]);
    }

	/**
	* Insert post and postmeta using `ImportHelper` class.
	*
	* @param array $post
	* @param array $meta
	* @param array $terms
	* @param string $thumbnail The uri or path of thumbnail image.
	* @param bool $is_update
	* @return ImportHelper
	*/
	public function save_post($post, $meta, $terms, $thumbnail, $is_update) {

		// Separate the post tags from $post array
		if (isset($post['post_tags']) && !empty($post['post_tags'])) {
			$post_tags = $post['post_tags'];
			unset($post['post_tags']);
		}

		// Special handling of attachments
		if (!empty($thumbnail) && $post['post_type'] == 'attachment') {
			$post['media_file'] = $thumbnail;
			$thumbnail = null;
		}

		// Add or update the post
		if ($is_update) {
			$h = ImportHelper::getByID($post['ID']);
			$h->update($post);
		} else {
			$h = ImportHelper::add($post);
		}

		// Set post tags
		if (isset($post_tags)) {
			$h->setPostTags($post_tags);
		}

		// Set meta data
		$h->setMeta($meta);

		// Set terms
		foreach ($terms as $key => $value) {
			$h->setObjectTerms($key, $value);
		}

		// Add thumbnail
		if ($thumbnail) {
			$h->addThumbnail($thumbnail);
		}

		return $h;
	}


}