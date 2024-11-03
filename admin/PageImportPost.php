<?php
namespace GpcDev\Admin;

use GpcDev\Includes\PostImporter;

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

            <div class="card mb3 max-w-lg">
				<div class="mb3">
					<h4>Hướng dẫn</h4>
					<ul class="ul-disc">
						<li>File import phải là file <code>excel (xlsx)</code> hoặc <code>csv</code></li>
                        <li>Nếu edit file <code>csv</code> thì nên dùng <b>Libre Office</b> thay cho <b>Excel</b> để tránh bị mất dấu tiếng Việt khi save csv.</li>
                        <li>Tải file mẫu <a href="https://drive.google.com/drive/folders/1cuZlRh-e8ZwmVQG8LeG9QUg2x9JJ8-Qu?usp=sharing" target="_blank">tại đây</a>
						<li><p>Cấu trúc file import (không bắt buộc phải có đủ các cột trong danh sách này):</p>
							<ul class="ul-square">
								<li><mark>post_id</mark>: Nếu có ID thì cập nhật bài viết theo ID, bỏ trống thì thêm mới</li>
								<li><mark>post_type</mark>: Loại bài viết, mặc định là 'post'</li>
								<li><mark>post_title</mark>: Tiêu đề bài viết</li>
								<li><mark>post_name</mark>: Slug bài viết</li>
                                <li><mark>post_excerpt</mark>: Mô tả ngắn cho bài viết</li>
                                <li><mark>post_content</mark>: Nội dung bài viết</li>
                                <li><mark>post_thumbnail</mark>: Url hình đại diện</li>
								<li><mark>post_author</mark>: ID hoặc tên đăng nhập của tác giả</li>
								<li><mark>post_status</mark>: Trạng thái bài viết (<code>draft, publish</code>)</li>
								<li>
									<span><mark>post_date</mark>: Ngày đăng. Nếu để năm ở cuối thì khi phân cách bằng dấu <code>/</code> là định dạng <code>m/d/Y</code>, phân cách bằng dấu <code>-</code> là định dạng <code>d-m-Y</code>. Nếu định dạng năm/tháng/ngày <code>Y/m/d</code> thì không quan trọng dấu phân cách. Ví dụ:</span>
									<ul class="ul-disc">
										<li><b>11/2/2024</b> &rarr; ngày 2 tháng 11</li>
										<li><b>11-2-2024</b> &rarr; ngày 11 tháng 2</li>
										<li><b>2024/11/2</b> &rarr; ngày 2 tháng 11</li>
										<li><b>2024-11-2</b> &rarr; ngày 2 tháng 11</li>
									</ul>
								</li>
								<li><mark>post_category</mark>: Mảng slug categories gán cho bài viết, phân cách bởi dấu phẩy, ví dụ <code>cat-1, cat-2, cat-3</code></li>
								<li><mark>post_tags</mark>: Mảng slug tags gán cho bài viết, phân cách bởi dấu phẩy, ví dụ <code>tag-1, tag-2, tag-3</code></li>
								<li>Các cột <mark>tax_abc</mark>: Các cột custom taxonmies (<code>tax_</code> + taxonomy)</li>
								<li>Các cột <mark>meta_abc</mark>: Các cột custom fields (<code>meta_</code> + tên field)</li>
								<li>Các cột <mark>acf_abc</mark>: Các cột custom fields dùng Advanced Custom Fields (<code>acf_</code> + tên field)</li>
							</ul>
						</li>
					</ul>
				</div>
				<hr class="mb3">
                <div class="gpc-form is-horizontal">
                    <div class="form-group">
                        <label>File (xlsx, csv)</label>
                        <input type="file" x-ref="file" accept=".csv,.xlsx,.ods" />
						<span class="error-text" x-cloak x-show="error.file" x-text="error.file"></span>
                    </div>
					<div class="form-group">
						<label></label>
                        <label><input type="checkbox" x-model="downloadImageInContent" /> Tải xuống hình ảnh trong nội dung</label>
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

			<div class="mt3 gpc-notice is-dismissible is-error" x-show="error.import" x-cloak>
				<template x-for="err in error.import">
					<p x-text="err"></p>
				</template>
            </div>
        </div>

        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('app', () => ({
                    error: {},
                    loading: false,
                    message: '',
					downloadImageInContent: true,

                    submit() {
                        const that = this;

						if (!this.$refs.file.files.length) {
							this.error.file = 'Vui lòng chọn file để import';
							return;
						}

						let allowedTypes = [
							'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
							'application/vnd.oasis.opendocument.spreadsheet',
							'text/csv',
						];

						if (!allowedTypes.includes(this.$refs.file.files[0].type)) {
							this.error.file = 'File không hợp lệ';
							return;
						}

                        this.loading = true;
                        var form_data = new FormData();
                        form_data.append('import', this.$refs.file.files[0]);
                        form_data.append('action', 'gpc_import_posts');
						form_data.append('download_image_in_content', this.downloadImageInContent);

                        jQuery.ajax({
                            url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                            method: 'POST',
                            data: form_data,
                            contentType: false,
                            processData: false,
                        }).then(response => {
                            that.message = response.data.message;
							if (response.data.errors.length) {
								that.error.import = response.data.errors;
							}
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
		$shouldDownloadImageInContent = $_POST['download_image_in_content'] ?? false;
        $uploadfile = wp_import_handle_upload();

        $id = (int) $uploadfile['id'];
		$file = get_attached_file($id);

		$importer = PostImporter::make()
			->fromFile($file)
			->setDownloadImageInContent($shouldDownloadImageInContent);

		$importer->import();

		$imported = $importer->get_imported();

		wp_import_cleanup($id);
        wp_send_json_success([
			'message' => count($imported) ? 'Các bài viết đã được tạo: ' . implode(',', $importer->get_imported()) : '',
			'errors' => $importer->get_error_messages()
		]);
    }
}