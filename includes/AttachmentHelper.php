<?php
namespace GpcDev\Includes;

class AttachmentHelper
{
    /**
     * Upload file hình ảnh từ url
     *
     * @param string $url
     * @param boolean $keepFileName
     * @return WP_Error|int Attachment Id
     */
    public static function uploadFromUrl(string $url, bool $keepFileName = true)
    {
        $timeout = 60; //seconds

        // Download temp file
		$temporary_file = download_url( $url, $timeout );

		// Check for download errors if there are error unlink the temp file name
		if ( is_wp_error( $temporary_file ) ) {
			return $temporary_file;
		}

		$mime_type = wp_get_image_mime( $temporary_file );
		if ( ! $mime_type ) {
			return new \WP_Error( 'invalid-image-mimetype', __( 'Invalid image MimeType', 'gpc-dev' ) );
		}

		$allowed_mime_types = get_allowed_mime_types();
		$extension = array_search( $mime_type, $allowed_mime_types );
        if ( ! $extension ) {
			return new \WP_Error( 'invalid-image-mimetype', __( 'Invalid image MimeType', 'gpc-dev' ) );
		}

        $extension = explode( '|', $extension )[0];
        $filename = $keepFileName ? pathinfo($url, PATHINFO_FILENAME) : wp_generate_uuid4();

		$file = [
			'name' => "$filename.$extension",
			'tmp_name' => $temporary_file,
		];

        /**
         * uploads as an attachment to WP
         * $post_id can be set to '0' to not attach it to any particular post
         */
        $post_id = 0;
		$attachment_id = media_handle_sideload( $file, $post_id );

		// deleting the temporary file
		if ( file_exists( $temporary_file ) ) {
			wp_delete_file( $temporary_file );
		}

		/**
		* We don't want to pass something to $id
		* if there were upload errors.
		* So this checks for errors
		*/
		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $temporary_file ) ) {
                wp_delete_file( $temporary_file );
			}
			return $attachment_id;
		}

        wp_delete_file( $temporary_file );

		// Return Attachment ID
		return $attachment_id;
    }
}