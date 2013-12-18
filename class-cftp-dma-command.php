<?php 

class CFTP_DMA_Command extends WP_CLI_Command {

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
			$warnings = array();
			if ( $generate_thumbs ) {
				$results[ 'downloaded_images' ] = 0;
				$results[ 'processed_images' ]  = 0;
			}

			$attachment_ids = $this->get_attachment_ids();
			// $attachment_ids = array( 307449, 344018 );
			$remote_url_base = trailingslashit( $assoc_args[ 'remote-url-base' ] );
			$dirs = wp_upload_dir();
			$base_dir = trailingslashit( $dirs[ 'basedir' ] );
			$generate_thumbs = $assoc_args[ 'generate-thumbs' ];

			$results[ 'files' ] = count( $attachment_ids );
			$line_msg = 'Downloading ' . $results[ 'files' ] . ' files';
			if ( $generate_thumbs )
				$line_msg .= ', and generating thumbs';
			WP_CLI::line( $line_msg );

			$progress = new \cli\progress\Bar( 'Progress',  $results[ 'files' ] );

			foreach ( $attachment_ids as $a_id ) {

				$progress->tick();

				$image = get_post( $a_id );
				$attached_file = get_post_meta( $a_id, '_wp_attached_file', true );
				// The GUID appears to contain reference to a file, but it's not stored normally.
				// Attempt to grab it. Warning: the following code may be highly specific to
				// the situation it was specifically written for. So. Consider yourself warned.
				if ( ! $attached_file && '/wp-content/uploads/' == substr( $image->guid, 0, 20 ) ) {
					// Try to work out the file URL from elsewhere
					$guid = $image->guid;
					$scheme = parse_url( $remote_url_base, PHP_URL_SCHEME );
					$domain = $scheme . '://' . parse_url( $remote_url_base, PHP_URL_HOST );
					$remote_url = $domain . $guid;
					// Is the file web accessible?
					$result = wp_remote_head( $remote_url );
					if ( is_wp_error( $result ) ) {
						$warnings[] = sprintf( 'Could not retrieve remote file for attachment ID %d, HTTP error "%s"', $a_id, $result->get_error_message() );
					} elseif ( 200 != wp_remote_retrieve_response_code( $result ) ) {
						$warnings[] = sprintf( 'Could not retrieve remote file for attachment ID %d, HTTP response code %d', $a_id, wp_remote_retrieve_response_code( $result ) );
						continue;
					}
					$local_path = str_replace( '/wp-content/uploads/', '', $base_dir ) . $attached_file;
					$attached_file = str_replace( $remote_url_base, '', $remote_url );
					update_post_meta( $a_id, '_wp_attached_file', $attached_file );
				} else {
					$remote_url = $remote_url_base . $attached_file;
					$local_path = $base_dir        . $attached_file;
				}

				// Check if the file already exists
				if ( file_exists( $local_path ) ) {
					$results[ 'already_downloaded' ]++;
					continue;
				}

				// Download the file
				// \WP_CLI::log( sprintf( 'Now getting %s', $remote_url ) );
				$tmp = download_url( $remote_url );
				if ( is_wp_error( $tmp ) ) {
					$warnings[] = sprintf( 'Could not download %s, got error: %s', $remote_url, $tmp->get_error_message() );
					$results[ 'failed' ]++;
					continue;
				}
				// \WP_CLI::log( sprintf( 'Rename %s to %s', $tmp, $local_path ) );
				// Make sure the directory exists
				$dir = dirname( $local_path );
				// \WP_CLI::log( sprintf( 'Creating directory %s', $dir ) );
				wp_mkdir_p( $dir );
				rename( $tmp, $local_path );

				// \WP_CLI::log( sprintf( 'Facts: %s %s ', $image->post_type, substr( $image->post_mime_type, 0, 6 ) ) );
				if ( 
						$image 
						&& 'attachment' == $image->post_type 
						&& 'image/' == substr( $image->post_mime_type, 0, 6 ) 
					) {
					// \WP_CLI::log( sprintf( 'ID is an image', $a_id ) );

					@set_time_limit( 900 ); // 5 minutes per image should be PLENTY
					
					$metadata = wp_generate_attachment_metadata( $a_id, $local_path );
					update_post_meta( $a_id, '_wp_attachment_metadata', $metadata );
					
					$results[ 'downloaded_images' ]++;
					
					if ( is_wp_error( $metadata ) )
						$warnings[] = sprintf( 'Error generating image thumbnails for attachment ID %d: %s', $a_id, $metadata->get_error_message() );
					else if ( empty( $metadata ) )
						$warnings[] = sprintf( 'Unknown error generating image thumbnails for attachment ID %d', $a_id );
					else
						$results[ 'processed_images' ]++;
				}

				$results[ 'downloaded' ]++;
			}
			
		} catch ( Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
		}

		$progress->finish();

		foreach ( $warnings as $warning )
			\WP_CLI::warning( $warning );

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
			'order'       => 'DESC',
			'orderby'     => 'date'
		) );
		// var_dump( $query ); exit;
		
		return $query->posts;
	}

}

WP_CLI::add_command( 'remote-attachments', 'CFTP_DMA_Command' );

