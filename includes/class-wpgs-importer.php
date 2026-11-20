<?php
/**
 * GitHub importer.
 *
 * Applies remote files (content.md, post data JSON, meta JSON) from the
 * configured GitHub repo/branch onto a local WordPress post.
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports remote WordPress content/meta from GitHub into local posts.
 *
 * Side effects:
 * - Performs outbound HTTPS requests to api.github.com.
 * - Updates local posts and post meta.
 *
 * Security notes:
 * - Must be invoked from trusted contexts (nonce-protected admin actions or WP-CLI).
 * - Uses OAuth/PAT tokens; do not log tokens.
 */
final class WPGS_Importer {
	/**
	 * Resolve GitHub target.
	 *
	 * @return array{provider:WPGS_GitHub_Provider,branch:string,repo:string} Provider, branch and owner/repo slug.
	 * @throws RuntimeException When GitHub owner/repo is not configured.
	 */
	public static function provider_context(): array {
		$settings = WPGS_Settings::get();
		$owner    = isset( $settings['github_owner'] ) ? trim( (string) $settings['github_owner'] ) : '';
		$repo     = isset( $settings['github_repo'] ) ? trim( (string) $settings['github_repo'] ) : '';
		$branch   = isset( $settings['branch'] ) ? trim( (string) $settings['branch'] ) : 'main';
		$branch   = '' !== $branch ? $branch : 'main';
		$token    = WPGS_Auth::get_token( $settings );

		if ( '' === $owner || '' === $repo ) {
			throw new RuntimeException( esc_html__( 'GitHub owner/repo not configured.', 'wp-git-sync' ) );
		}

		return [
			'provider' => new WPGS_GitHub_Provider( new WPGS_GitHub_Client( $token ), $owner . '/' . $repo ),
			'branch'   => $branch,
			'repo'     => $owner . '/' . $repo,
		];
	}

	/**
	 * Import remote content from GitHub and overwrite local post content.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 * @throws RuntimeException On fetch/update errors.
	 */
	public static function pull_content( WP_Post $post ): void {
		$context  = self::provider_context();
		$provider = $context['provider'];
		$branch   = (string) $context['branch'];
		$paths    = WPGS_Diff::paths_for_post( $post );

		try {
			$remote_content = $provider->get_file_contents( $branch, $paths['content_path'] );
		} catch ( Throwable $e ) {
			/* translators: %s: Error message from GitHub fetch. */
			throw new RuntimeException( sprintf( esc_html__( 'Unable to fetch remote files for import: %s', 'wp-git-sync' ), esc_html( $e->getMessage() ) ) );
		}

		$res = wp_update_post(
			[
				'ID'           => (int) $post->ID,
				'post_content' => (string) $remote_content,
			],
			true
		);

		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( esc_html( $res->get_error_message() ) );
		}
	}

	/**
	 * Import remote post-table JSON and apply it to the local post.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 * @throws RuntimeException On fetch/update errors.
	 */
	public static function pull_post_data( WP_Post $post ): void {
		$context  = self::provider_context();
		$provider = $context['provider'];
		$branch   = (string) $context['branch'];
		$paths    = WPGS_Diff::paths_for_post( $post );

		try {
			$remote_post_json = $provider->get_file_contents( $branch, $paths['post_path'] );
		} catch ( Throwable $e ) {
			/* translators: %s: Error message from GitHub fetch. */
			throw new RuntimeException( sprintf( esc_html__( 'Unable to fetch remote files for import: %s', 'wp-git-sync' ), esc_html( $e->getMessage() ) ) );
		}

		$post_payload = self::decode_json_array_payload( $remote_post_json, __( 'post data', 'wp-git-sync' ) );
		self::apply_remote_post_data( (int) $post->ID, $post_payload );
	}

	/**
	 * Import remote post-meta JSON and apply it to local post meta.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 * @throws RuntimeException On fetch/update errors.
	 */
	public static function pull_post_meta( WP_Post $post ): void {
		$context  = self::provider_context();
		$provider = $context['provider'];
		$branch   = (string) $context['branch'];
		$paths    = WPGS_Diff::paths_for_post( $post );

		try {
			$remote_meta_json = $provider->get_file_contents( $branch, $paths['meta_path'] );
		} catch ( Throwable $e ) {
			/* translators: %s: Error message from GitHub fetch. */
			throw new RuntimeException( sprintf( esc_html__( 'Unable to fetch remote files for import: %s', 'wp-git-sync' ), esc_html( $e->getMessage() ) ) );
		}

		$meta_payload = self::decode_json_array_payload( $remote_meta_json, __( 'meta data', 'wp-git-sync' ) );
		self::apply_remote_post_meta( (int) $post->ID, $meta_payload );
	}

	/**
	 * Decode a JSON payload and ensure it is an array.
	 *
	 * @param string $json  Raw JSON.
	 * @param string $label Payload label for errors.
	 * @return array<mixed>
	 * @throws RuntimeException On invalid JSON.
	 */
	private static function decode_json_array_payload( string $json, string $label ): array {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			/* translators: %s: Payload label (for example "post data" or "meta data"). */
			throw new RuntimeException( sprintf( esc_html__( 'Unable to decode remote %s JSON.', 'wp-git-sync' ), esc_html( $label ) ) );
		}
		return $decoded;
	}

	/**
	 * Apply imported post-table data to a local post.
	 *
	 * @param int                 $post_id   Post ID.
	 * @param array<string,mixed> $post_data Remote post-data payload.
	 * @return void
	 * @throws RuntimeException On update errors.
	 */
	private static function apply_remote_post_data( int $post_id, array $post_data ): void {
		unset( $post_data['ID'], $post_data['post_content'], $post_data['filter'] );

		$allowed_keys = [
			'post_author',
			'post_date',
			'post_date_gmt',
			'post_title',
			'post_excerpt',
			'post_status',
			'comment_status',
			'ping_status',
			'post_password',
			'post_name',
			'to_ping',
			'pinged',
			'post_content_filtered',
			'post_parent',
			'guid',
			'menu_order',
			'post_type',
			'post_mime_type',
		];

		$update = [ 'ID' => $post_id ];
		foreach ( $allowed_keys as $key ) {
			if ( array_key_exists( $key, $post_data ) ) {
				$update[ $key ] = $post_data[ $key ];
			}
		}

		if ( 1 === count( $update ) ) {
			return;
		}

		$res = wp_update_post( $update, true );
		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( esc_html( $res->get_error_message() ) );
		}
	}

	/**
	 * Apply imported post meta payload to a local post.
	 *
	 * @param int                 $post_id     Post ID.
	 * @param array<string,mixed> $meta_payload Remote meta payload.
	 * @return void
	 */
	private static function apply_remote_post_meta( int $post_id, array $meta_payload ): void {
		$internal_protected = array_fill_keys( WPGS_Sync_Meta::internal_keys(), true );
		$meta_blacklist     = WPGS_Diff::meta_blacklist();

		$existing = get_post_meta( $post_id );
		if ( is_array( $existing ) ) {
			foreach ( array_keys( $existing ) as $meta_key ) {
				$meta_key = (string) $meta_key;
				if (
					'' === $meta_key
					|| isset( $internal_protected[ $meta_key ] )
					|| WPGS_Diff::meta_key_matches_blacklist( $meta_key, $meta_blacklist )
				) {
					continue;
				}
				delete_post_meta( $post_id, $meta_key );
			}
		}

		foreach ( $meta_payload as $meta_key => $values ) {
			$meta_key = (string) $meta_key;
			if (
				'' === $meta_key
				|| isset( $internal_protected[ $meta_key ] )
				|| WPGS_Diff::meta_key_matches_blacklist( $meta_key, $meta_blacklist )
			) {
				continue;
			}

			$list = self::normalize_meta_values_for_import( $values );
			foreach ( $list as $value ) {
				add_post_meta( $post_id, $meta_key, self::prepare_meta_value_for_storage( $value ), false );
			}
		}
	}

	/**
	 * Normalize imported meta values to a list of meta rows.
	 *
	 * Meta export payload is typically key => list-of-values. However, when a
	 * key stores a single value that is itself an associative array/object-like
	 * structure, treat it as one value rather than splitting by keys.
	 *
	 * @param mixed $values Raw payload value for one meta key.
	 * @return array<int,mixed>
	 */
	private static function normalize_meta_values_for_import( $values ): array {
		if ( ! is_array( $values ) ) {
			return [ $values ];
		}

		$keys = array_keys( $values );
		$is_list = ( $keys === range( 0, count( $values ) - 1 ) );
		if ( $is_list ) {
			return $values;
		}

		return [ $values ];
	}

	/**
	 * Prepare one meta value for storage without double-serializing payloads.
	 *
	 * WordPress metadata APIs will serialize arrays/objects and also re-serialize
	 * strings that already look serialized. To preserve existing serialized
	 * payloads from the repo, decode once before writing so core serializes once.
	 *
	 * @param mixed $value Meta value from repo payload.
	 * @return mixed
	 */
	private static function prepare_meta_value_for_storage( $value ) {
		if ( is_string( $value ) && is_serialized( $value ) ) {
			return maybe_unserialize( $value );
		}

		return $value;
	}
}
