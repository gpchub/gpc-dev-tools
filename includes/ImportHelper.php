<?php
namespace GpcDev\Includes;

use DOMElement;
use WP_Error;
use WP_Post;

/**
 * A helper class for insert or update post data.
 *
 * @package Really Simple CSV Importer
 */
class ImportHelper
{
    const CFS_PREFIX = 'cfs_';
    const SCF_PREFIX = 'scf_';

    /**
     * @var $post WP_Post object
     */
    private $post;

    /**
     * @var $error WP_Error object
     */
    private $error;

    /**
     * Add an error or append additional message to this object.
     *
     * @param string|int $code Error code.
     * @param string $message Error message.
     * @param mixed $data Optional. Error data.
     */
    public function addError($code, $message, $data = '')
    {
        if (!$this->isError()) {
            $e = new WP_Error();
            $this->error = $e;
        }
        $this->error->add($code, $message, $data);
    }

    /**
     * Get the error of this object
     *
     * @return (WP_Error)
     */
    public function getError()
    {
        if (!$this->isError()) {
            $e = new WP_Error();
            return $e;
        }
        return $this->error;
    }

    /**
     * Check the object has some Errors.
     *
     * @return (bool)
     */
    public function isError()
    {
        return is_wp_error($this->error);
    }

    /**
     * Set WP_Post object
     *
     * @param (int) $post_id Post ID
     */
    protected function setPost($post_id)
    {
        $post = get_post($post_id);
        if (is_object($post)) {
            $this->post = $post;
        } else {
            $this->addError('post_id_not_found', __('Provided Post ID not found.', 'really-simple-csv-importer'));
        }
    }

    /**
     * Get WP_Post object
     *
     * @return (WP_Post|null)
     */
    public function getPost()
    {
        return $this->post;
    }

    /**
     * Get object by post id.
     *
     * @param (int) $post_id Post ID
     * @return (ImportHelper)
     */
    public static function getByID($post_id)
    {
        $object = new ImportHelper();
        $object->setPost($post_id);
        return $object;
    }

    /**
     * Add a post
     *
     * @param (array) $data An associative array of the post data
     * @return (ImportHelper)
     */
    public static function add($data)
    {
        $object = new ImportHelper();

        if ($data['post_type'] == 'attachment') {
            $post_id = $object->addMediaFile($data['media_file'], $data);
        } else {
            $post_id = wp_insert_post($data, true);
        }
        if (is_wp_error($post_id)) {
            $object->addError($post_id->get_error_code(), $post_id->get_error_message());
        } else {
            $object->setPost($post_id);
        }
        return $object;
    }

    /**
     * Update post
     *
     * @param (array) $data An associative array of the post data
     */
    public function update($data)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            $data['ID'] = $post->ID;
        }
        if ($data['post_type'] == 'attachment' && !empty($data['media_file'])) {
            $this->updateAttachment($data['media_file']);
            unset($data['media_file']);
        }
        $post_id = wp_update_post($data, true);
        if (is_wp_error($post_id)) {
            $this->addError($post_id->get_error_code(), $post_id->get_error_message());
        } else {
            $this->setPost($post_id);
        }
    }

    /**
     * Set meta fields by array
     *
     * @param (array) $data An associative array of metadata
     */
    public function setMeta($data)
    {
        foreach ($data as $key => $value) {
            $is_acf = function_exists('get_field_object') && strpos($key, 'field_') === 0;

            if ($is_acf) {
                $fobj = get_field_object($key);
                if (is_array($fobj) && isset($fobj['key']) && $fobj['key'] == $key) {
                    $this->acfUpdateField($key, $value);
                }
            } else {
                $this->updateMeta($key, $value);
            }
        }
    }

    /**
     * A wrapper of update_post_meta
     *
     * @param (string) $key
     * @param (string/array) $value
     */
    protected function updateMeta($key, $value)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            update_post_meta($post->ID, $key, $value);
        } else {
            $this->addError('post_is_not_set', __('WP_Post object is not set.', 'really-simple-csv-importer'));
        }
    }

    /**
     * A wrapper of update_field of Advanced Custom Fields
     *
     * @param (string) $key
     * @param (string/array) $value
     */
    protected function acfUpdateField($key, $value)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            if (function_exists('update_field')) {
                update_field($key, $value, $post->ID);
            } else {
                $this->updateMeta($key, $value);
            }
        } else {
            $this->addError('post_is_not_set', __('WP_Post object is not set.', 'really-simple-csv-importer'));
        }
    }

    /**
     * A wrapper of wp_set_post_tags
     *
     * @param (array) $tags
     */
    public function setPostTags($tags)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            wp_set_post_tags($post->ID, $tags);
        } else {
            $this->addError('post_is_not_set', __('WP_Post object is not set.', 'really-simple-csv-importer'));
        }
    }

    /**
     * A wrapper of wp_set_object_terms
     *
     * @param (array/string) $taxonomy The context in which to relate the term to the object
     * @param (array/int/string) $terms The slug or id of the term
     */
    public function setObjectTerms($taxonomy, $terms)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            wp_set_object_terms($post->ID, $terms, $taxonomy);
        } else {
            $this->addError('post_is_not_set', __('WP_Post object is not set.', 'really-simple-csv-importer'));
        }
    }

    /**
     * Add attachment file. Automatically get remote file
     *
     * @param (string) $file
     * @param (array) $data
     * @return (boolean) True on success, false on failure.
     */
    public function addMediaFile($file, $data = null)
    {
        if (parse_url($file, PHP_URL_SCHEME)) {
            $file = $this->remoteGet($file);
        }
        $id = $this->setAttachment($file, $data);
        if ($id) {
            return $id;
        }

        return false;
    }

    /**
     * Add attachment file and set as thumbnail. Automatically get remote file
     *
     * @param (string) $file
     * @return (boolean) True on success, false on failure.
     */
    public function addThumbnail($file)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            if (parse_url($file, PHP_URL_SCHEME)) {
                $file = $this->remoteGet($file);
            }
            $thumbnail_id = $this->setAttachment($file);
            if ($thumbnail_id) {
                $meta_id = set_post_thumbnail($post, $thumbnail_id);
                if ($meta_id) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A wrapper of wp_insert_attachment and wp_update_attachment_metadata
     *
     * @param (string) $file
     * @param (array) $data
     * @return (int) Return the attachment id on success, 0 on failure.
     */
    public function setAttachment($file, $data = array())
    {
        $post = $this->getPost();
        if ( $file && file_exists($file) ) {
            $filename       = basename($file);
            $wp_filetype    = wp_check_filetype_and_ext($file, $filename);
            $ext            = empty( $wp_filetype['ext'] ) ? '' : $wp_filetype['ext'];
            $type           = empty( $wp_filetype['type'] ) ? '' : $wp_filetype['type'];
            $proper_filename= empty( $wp_filetype['proper_filename'] ) ? '' : $wp_filetype['proper_filename'];
            $filename       = ($proper_filename) ? $proper_filename : $filename;
            $filename       = sanitize_file_name($filename);

            $upload_dir     = wp_upload_dir();
            $guid           = $upload_dir['baseurl'] . '/' . _wp_relative_upload_path($file);

            $attachment = array_merge(array(
                'post_mime_type'    => $type,
                'guid'              => $guid,
                'post_title'        => $filename,
                'post_content'      => '',
                'post_status'       => 'inherit'
            ), $data);
            $attachment_id          = wp_insert_attachment($attachment, $file, ($post instanceof WP_Post) ? $post->ID : null);
            $attachment_metadata    = wp_generate_attachment_metadata( $attachment_id, $file );
            wp_update_attachment_metadata($attachment_id, $attachment_metadata);
            return $attachment_id;
        }
        // On failure
        return 0;
    }

    /**
     * A wrapper of update_attached_file
     *
     * @param (string) $value
     */
    protected function updateAttachment($value)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            update_attached_file($post->ID, $value);
        } else {
            $this->addError('post_is_not_set', __('WP_Post object is not set.', 'really-simple-csv-importer'));
        }
    }

    /**
     * A wrapper of wp_safe_remote_get
     *
     * @param (string) $url
     * @param (array) $args
     * @return (string) file path
     */
    public function remoteGet($url, $args = array())
    {
        global $wp_filesystem;
        if (!is_object($wp_filesystem)) {
            WP_Filesystem();
        }

        if ($url && is_object($wp_filesystem)) {
            $response = wp_safe_remote_get($url, $args);
            if (!is_wp_error($response) && $response['response']['code'] === 200) {
                $destination = wp_upload_dir();
                $filename = basename($url);
                $filepath = $destination['path'] . '/' . wp_unique_filename($destination['path'], $filename);

                $body = wp_remote_retrieve_body($response);

                if ( $body && $wp_filesystem->put_contents($filepath , $body, FS_CHMOD_FILE) ) {
                    return $filepath;
                } else {
                    $this->addError('remote_get_failed', __('Could not get remote file.', 'really-simple-csv-importer'));
                }
            } elseif (is_wp_error($response)) {
                $this->addError($response->get_error_code(), $response->get_error_message());
            }
        }

        return '';
    }

    public static function processContent($content)
    {
        if (empty($content)) return $content;

        $doc = new \DOMDocument();
        // Thêm meta để hiển thị đúng utf8
        $html = '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"> </head><body>' . $content . '</body>';
        $doc->loadHTML($html);

        $images = $doc->getElementsByTagName('img');

        foreach ($images as $img) {
            $src = $img->getAttribute('src');
            if (empty($src)) {
                continue;
            }

            $attachment_id = AttachmentHelper::uploadFromUrl($src);
            if ( is_wp_error( $attachment_id ) ) {
                continue;
            }

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

        return $html;
    }

    /**
     * Unset WP_Post object
     */
    public function __destruct()
    {
        unset($this->post);
    }
}