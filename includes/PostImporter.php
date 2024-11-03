<?php
namespace GpcDev\Includes;

use DateTimeImmutable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader;
use WP_Error;

class PostImporter
{
    /** input */
    private string $file = '';
    private bool $downloadImageInContent = false;

    /** processing */
    private array $headers = [];
    private array $post_statuses = ['draft', 'pending', 'private', 'publish'];

    /** output */
    private array $imported = [];
    private array $errors = [];

    public static function make()
    {
        return new static();
    }

    public function fromFile(string $file)
    {
        $this->file = $file;
        return $this;
    }

    public function setDownloadImageInContent(bool $value)
    {
        $this->downloadImageInContent = $value;
        return $this;
    }

    public function get_imported()
    {
        return $this->imported;
    }

    public function get_errors()
    {
        return $this->errors;
    }

    public function get_error_messages()
    {
        return array_map(fn ($error) => $error->get_error_message(), $this->errors);
    }

    public function import(): void
    {
        /**
         * File khi upload được WP gắn ext ".txt", nếu trùng tên thì gắn thêm số, ví dụ "sample.csv.txt", "sample.csv-1.text"...
         * do đó cần xử lý để lấy đúng extension
         */
        $filename = pathinfo($this->file, PATHINFO_FILENAME);
		$extension = pathinfo($filename, PATHINFO_EXTENSION);
		if (strpos($extension, '-')) {
			$extension = substr($extension, 0, strpos($extension, '-'));
		}

        $reader = match($extension) {
            'csv' => new \OpenSpout\Reader\CSV\Reader(),
            'xlsx' => new \OpenSpout\Reader\XLSX\Reader(),
            'ods' => new \OpenSpout\Reader\ODS\Reader(), // Libre Office
            default => null,
        };

        if (!$reader) {
            $this->errors[] = new WP_Error('invalid-file', 'File không hợp lệ');
            return;
        };

        $reader->open($this->file);

        foreach ($reader->getSheetIterator() as $sheet) {
            // chỉ xử lý 1 sheet
            if ($sheet->getIndex() === 0) {
                foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                    if ($rowNumber === 1) {
                        $this->headers = $row->toArray();
                        continue;
                    }

                    $result = $this->process_row($row);

                    if (is_wp_error($result)) {
                        $code = $result->get_error_code();
                        $message = $result->get_error_message();
                        $this->errors[] = new WP_Error($code, "Row {$rowNumber}: {$message}");
                    } else {
                        $this->imported[] = $result;
                    }
                }

                break;
            }
        }

        $reader->close();
    }

    private function process_row(Row $row): int|WP_Error
    {
        $cells = array_combine( $this->headers, $row->toArray() );
        $is_update = false;
        $post_args = [];

        if ( empty( $cells['post_id'] )
             && empty( $cells['post_title'] )
             && empty( $cells['post_content'] )
             && empty( $cells['post_excerpt'] )
        ) {
            return new WP_Error( 'empty_content', __( 'Content, title, and excerpt are empty.' ) );
        }

        // (int) post id
        if ( ! empty( $cells['post_id'] ) ) {
            $post_id = $cells['post_id'];
            $post_before = get_post( $post_id );

            if ( is_null( $post_before ) ) {
                return new WP_Error( 'invalid_post', __( 'Invalid post ID.' ) );
            }

            $post_args['ID'] = $post_id;
            $is_update = true;
            unset( $post_before );
        }

        // (string) post title
        if ( ! empty( $cells['post_title'] ) ) {
            $post_args['post_title'] = wp_strip_all_tags($cells['post_title']);
        }

        // (string) post type
        if ( ! empty( $cells['post_type'] ) && post_type_exists( $cells['post_type'] ) ) {
            $post_args['post_type'] = $cells['post_type'] ;
        }

        // (login or ID) post_author
        if ( ! empty( $cells['post_author'] ) ) {
            $post_author = $cells['post_author'];

            if (is_numeric($post_author)) {
                $user = get_user_by('id', $post_author);
            } else {
                $user = get_user_by('login', $post_author);
            }

            if (isset($user) && is_object($user)) {
                $post_args['post_author'] = $user->ID;
                unset($user);
            }
        }

        // (string) publish date
        if ( ! empty( $cells['post_date'] ) ) {
            if ($cells['post_date'] instanceof DateTimeImmutable) {
                $post_args['post_date'] = $cells['post_date']->format("Y-m-d H:i:s");
            } else {
                $post_args['post_date'] = date("Y-m-d H:i:s", strtotime($cells['post_date']));
            }
        }

        // (string) post status
        if ( ! empty( $cells['post_status'] ) && in_array($cells['post_status'], $this->post_statuses)) {
            $post_args['post_status'] = $cells['post_status'];
        }

        // (string) post slug
        if ( ! empty( $cells['post_name'] ) ) {
            $post_args['post_name'] = $cells['post_name'];
        }

        // (string) post content
        if ( ! empty ( $cells['post_content'] ) ) {
            $post_args['post_content'] = $cells['post_content'];
        }

        // (string) post excerpt
        if ( ! empty ( $cells['post_excerpt'] ) ) {
            $post_args['post_excerpt'] = $cells['post_excerpt'];
        }

        // (string, comma separated) slug of post categories
        if ( ! empty ( $cells['post_category'] ) ) {
            $categories = preg_split( "/,+/", $cells['post_category'] );
            if ( $categories ) {
                /** wp_insert_post $postarr['post_category'] int[] Array of category IDs. */
                $post_args['post_category'] = wp_create_categories($categories);
            }
        }

        // (string, comma separated) name of post tags
        if ( ! empty( $cells['post_tags'] ) ) {
            /** wp_insert_post $postarr['tags_input'] array Array of tag names, slugs, or IDs. Default empty. */
            $post_args['tags_input'] = $cells['post_tags'];
        }

        // (string) post thumbnail image uri
        $post_thumbnail = $cells['post_thumbnail'] ?? '';

        $meta = array();
        $tax = array();

        foreach ($this->headers as $header) {
            if ( empty ( $cells[$header] ) ) {
                continue;
            }

            if ( str_starts_with( $header, 'tax_' ) ) {
                // (string, comma divided) term names of custom taxonomy
                $terms = preg_split("/,+/", $cells[$header]);
                $taxonomy = substr($header, 4);
                $tax[$taxonomy] = array_map('trim', $terms);
            }

            if ( ( str_starts_with($header, 'meta_') || str_starts_with($header, 'acf_') ) ) {
                $meta[$header] = $cells[$header];
            }
        }

        /**
         * Chạy mà không import thực sự, dùng để test
         * @param bool false
         */
        $dry_run = apply_filters( 'gpc_dev_tools_csv_importer_dry_run', false );

        $result = 0;
        if ($dry_run == false) {
            $result = $this->save_post($post_args, $meta, $tax, $post_thumbnail, $is_update);
        }

        return $result;
    }

    /**
	* Insert or update post
	*
	* @param array $post_args
	* @param array $meta
	* @param array $tax
	* @param string $thumbnail The uri or path of thumbnail image.
	* @param bool $is_update
	* @return mixed
	*/
    private function save_post($post_args, $meta, $tax, $post_thumbnail, $is_update)
    {
        $downloaded_images = []; // lưu ids hình đã attach để xoá nếu import lỗi
        $featured_image_id = 0;

        //featured image
        if ( ! empty( $post_thumbnail ) ) {
            $attachment_id = AttachmentHelper::uploadFromUrl( $post_thumbnail );

            if ( ! is_wp_error( $attachment_id ) ) {
                $downloaded_images[] = $attachment_id;
                $featured_image_id = $attachment_id;
            }
        }

        // Tải hình trong content và upload vào media library
        if ( ! empty( $post_args['post_content'] ) && $this->downloadImageInContent ) {
            $processed_content = $this->process_images_in_content( $post_args['post_content'], $post_thumbnail, $featured_image_id );

            $post_args['post_content'] = $processed_content['content'];
            $downloaded_images = array_merge( $downloaded_images, $processed_content['downloaded_images'] );
        }

        if ( $is_update ) {
            $post_id = wp_update_post( $post_args, true );
        } else {
            $post_id = wp_insert_post( $post_args, true );
        }

        // Xoá hết hình đã download nếu save post bị lỗi
        if ( is_wp_error( $post_id ) ) {
            $downloaded_images = array_unique( $downloaded_images );
            foreach ( $downloaded_images as $id ) {
                wp_delete_attachment( $id );
            }
        }

        // set featured image
        if ( ! is_wp_error( $featured_image_id ) && $featured_image_id > 0 ) {
            set_post_thumbnail( $post_id, $featured_image_id );
        }

        // set meta data
        $this->save_meta($post_id, $meta);

        // Set terms
		foreach ( $tax as $taxonomy => $value ) {
			wp_set_object_terms( $post_id, $value, $taxonomy );
		}

        return $post_id;
    }

    /**
     * Tải hình trong content và upload vào media library
     *
     * @param string $content
     * @param string $featured_image_url
     * @param int $featured_image_id
     * @return array{content: string, downloaded_images: array}
     */
    private function process_images_in_content($content, $featured_image_url, $featured_image_id)
    {
        // Thêm meta để hiển thị đúng utf8
        $html = '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"> </head><body>' . $content . '</body>';
        $doc = new \DOMDocument();
        $doc->loadHTML($html);

        $images = $doc->getElementsByTagName('img');
        $downloaded_images = [];

        foreach ($images as $img) {
            /** @var \DOMElement $img */

            $src = $img->getAttribute('src');
            if ( empty( $src ) ) {
                continue;
            }

            // nếu hình giống với post thumbnail thì không cần download
            if ($src == $featured_image_url) {
                $attachment_id = $featured_image_id;
            } else {
                $attachment_id = AttachmentHelper::uploadFromUrl($src);
            }

            if ( is_wp_error( $attachment_id ) ) {
                continue;
            }

            $downloaded_images[] = $attachment_id;

            $newSrc = wp_get_attachment_image_url($attachment_id, 'full');
            $img->setAttribute('src',  $newSrc);
            $img->removeAttribute('srcset');

            $class = $img->getAttribute('class');
            $class = preg_replace('/wp-image-\d+/', '', $class);
            $class .= ' wp-image-'.$attachment_id;
            $img->setAttribute('class', $class);
        }

        $body = $doc->getElementsByTagName('body')->item(0);

        $html = '';

        foreach ($body->childNodes as $node) {
            $html .= $doc->saveHTML($node);
        }

        return [
            'content' => $html,
            'downloaded_images' => $downloaded_images
        ];
    }

    private function save_meta($post_id, $meta)
    {
        if (!count($meta)) return;

        foreach ($meta as $key => $value) {
            $is_acf = function_exists('get_field_object') && strpos($key, 'acf_') === 0;

            if ($is_acf) {
                $key = substr($key, 4); // key: 'acf_dummy'
                /** @disregard */
                $fobj = get_field_object($key);
                if (is_array($fobj) && isset($fobj['key']) && $fobj['key'] == $key) {
                    /** @disregard */
                    update_field($key, $value, $post_id);
                }
            } else {
                $key = substr($key, 5); // key: 'meta_dummy'
                update_post_meta($post_id, $key, $value);
            }
        }
    }

}