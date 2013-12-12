<?php 

class CFTP_GRF_Command extends WP_CLI_Command {

	public function __construct() {

	}

	/**
	 * Iterate all attachments, downloading the file from a remote server and loading it into the current site.
	 *
	 * ## OPTIONS
	 *
	 * --remote-url-base=<string>
	 * : The URL to the uploads directory, not including any date based folder structure
	 *
	 * [--generate-thumbs]
	 * : Set this optional parameter if you want to (re)generate all the different image sizes. Defaults to not generating thumbnails.
	 *
	 * ## EXAMPLES
	 *
	 * wp remote-attachments get --remote-url-base=http://www.example.com/wp-content/uploads/
	 * 
	 */
	public function get( $args, $assoc_args ) {
		require_once( ABSPATH . 'wp-admin/includes/media.php' );
		require_once( ABSPATH . 'wp-admin/includes/file.php' );
		require_once( ABSPATH . 'wp-admin/includes/image.php' );

		$defaults = array(
			'generate-thumbs' => false,
		);
		$assoc_args = wp_parse_args( $assoc_args, $defaults );

		try {

			$results = array(
				'files'              => 0,
				'already_downloaded' => 0,
				'failed'             => 0,
				'downloaded'         => 0,
			);
			if ( $generate_thumbs ) {
				$results[ 'downloaded_images' ] = 0;
				$results[ 'processed_images' ]  = 0;
			}

			$attachment_ids = $this->get_attachment_ids();
			$remote_url_base = trailingslashit( $assoc_args[ 'remote-url-base' ] );
			$dirs = wp_upload_dir();
			$base_dir = trailingslashit( $dirs[ 'basedir' ] );
			$generate_thumbs = $assoc_args[ 'generate-thumbs' ];

			$results[ 'files' ] = count( $attachment_ids );

			foreach ( $attachment_ids as $a_id ) {
				$meta = get_post_meta( $a_id, '_wp_attachment_metadata', true );
				if ( ! $meta )
					continue;
				$remote_url = $remote_url_base . $meta[ 'file' ];
				$local_path = $base_dir   . $meta[ 'file' ];

				// Check if the file already exists
				if ( file_exists( $local_path ) ) {
					$results[ 'already_downloaded' ]++;
					continue;
				}

				// Download the file
				// \WP_CLI::log( sprintf( 'Now getting %s', $remote_url ) );
				$tmp = download_url( $remote_url );
				if ( is_wp_error( $tmp ) ) {
					\WP_CLI::warning( sprintf( 'Could not download %s, got error: %s', $remote_url, $tmp->get_error_message() ) );
					$results[ 'failed' ]++;
					continue;
				}
				// \WP_CLI::log( sprintf( 'Rename %s to %s', $tmp, $local_path ) );
				// Make sure the directory exists
				$dir = dirname( $local_path );
				// \WP_CLI::log( sprintf( 'Creating directory %s', $dir ) );
				wp_mkdir_p( $dir );
				rename( $tmp, $local_path );

                $image = false;
				if ( $generate_thumbs )
	                $image = get_post( $a_id );

				// \WP_CLI::log( sprintf( 'Facts: %s %s ', $image->post_type, substr( $image->post_mime_type, 0, 6 ) ) );
                // var_dump( $image );
				if ( 
						$image 
						&& 'attachment' == $image->post_type 
						&& 'image/' == substr( $image->post_mime_type, 0, 6 ) 
					) {
					// \WP_CLI::log( sprintf( 'ID is an image', $a_id ) );

					@set_time_limit( 900 ); // 5 minutes per image should be PLENTY
					$metadata = wp_generate_attachment_metadata( $a_id, $local_path );
					$results[ 'downloaded_images' ]++;
					
					if ( is_wp_error( $metadata ) )
						\WP_CLI::warning( sprintf( 'Error generating image thumbnails for attachment ID %d: %s', $metadata->get_error_message() ) );
					else if ( empty( $metadata ) )
						\WP_CLI::warning( sprintf( 'Unknown error generating image thumbnails for attachment ID %d' ) );
					else
						$results[ 'processed_images' ]++;
				}

				$results[ 'downloaded' ]++;
			}
			
		} catch ( Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
		}

		$lines = array();
		foreach ( $results as $name => $count )
			$lines[] = (object) array( 'Item' => $name, 'Count' => $count );
		$fields = array( 'Item', 'Count' );
		\WP_CLI\Utils\format_items( 'table', $lines, $fields );

	}

	/**
	 * 
	 *
	 *
	 * @return array An array of attachment IDs
	 * @author Simon Wheatley
	 **/
	protected function get_attachment_ids() {
		$query = new WP_Query( array(
			'post_type'   => 'attachment',
			'post_status' => 'any',
			'nopaging'    => true,
			'fields'      => 'ids',
			'meta_query'  => array(
				array(
					'key'     => '_wp_attachment_metadata',
					'compare' => 'EXISTS',
				),
			),
			'order'       => 'DESC',
			'orderby'     => 'date'
		) );
		// var_dump( $query ); exit;
		
		return $query->posts;
	}

}

WP_CLI::add_command( 'remote-attachments', 'CFTP_GRF_Command' );

