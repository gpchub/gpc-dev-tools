<?php
namespace GpcDev\Includes;

class LoremPicsum
{
    public static function generate_image_ids($count)
    {
        $arr = range(100, 200);
        shuffle($arr);
        return array_slice($arr, 0, $count);
    }

    public static function download_images($count, $width = 1000, $height = 750)
    {
        $range = range(100, 200);
        shuffle($range);
        $ids = array_slice($range, 0, $count);

        $images = [];
        foreach ( $ids as $id ) {
            $images[] = self::download_image($id, $width, $height);
        }

        return $images;
    }

    public static function download_image($id, $width = 1000, $height = 700)
    {
        $url = "https://picsum.photos/id/{$id}/{$width}/{$height}";
        $timeout = 60; //seconds

        // Download temp file
		$temporary_file = download_url( $url, $timeout );

		// Check for download errors if there are error unlink the temp file name
		if ( is_wp_error( $temporary_file ) ) {
			return $temporary_file;
		}

		$mime_type = wp_get_image_mime( $temporary_file );
		if ( ! $mime_type ) {
			return new \WP_Error( 'invalid-image-mimetype', __( 'Invalid image MimeType', 'fakerpress' ) );
		}

		$allowed_mime_types = get_allowed_mime_types();

		$extension = array_search( $mime_type, $allowed_mime_types );
		if ( $extension ) {
			$extension = explode( '|', $extension );
		}

		if ( ! $extension ) {
			return new \WP_Error( 'invalid-image-mimetype', __( 'Invalid image MimeType', 'fakerpress' ) );
		}

		// Build file name with Extension.
		$filename = implode( '.', [ wp_generate_uuid4(), reset( $extension ) ] );

		$file = [
			'name' => $filename,
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
			unlink( $temporary_file );
		}

		/**
		* We don't want to pass something to $id
		* if there were upload errors.
		* So this checks for errors
		*/
		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $temporary_file ) ) {
				 unlink( $temporary_file );
			}
			return $attachment_id;
		}

		// Return Attachment ID
		return $attachment_id;
    }
}