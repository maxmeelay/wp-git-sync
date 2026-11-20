<?php
/**
 * WP-CLI commands for WP Git Sync.
 *
 * Registers the `wp git-sync` command with `export`, `export-all`, `import`
 * and `roundtrip` subcommands so items can be synced from shell scripts.
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI command handler.
 *
 * Side effects:
 * - Exports posts to GitHub (outbound HTTPS + writes to repo).
 * - Imports remote GitHub files into local posts.
 */
final class WPGS_CLI {
	/**
	 * Export a single post to GitHub.
	 *
	 * Writes the post's content/data/meta files plus mapping.json and the
	 * repo-root README.md in one commit.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : ID of the post to export.
	 *
	 * ## EXAMPLES
	 *
	 *     wp git-sync export 42
	 *
	 * @param array<int,string>     $args       Positional args.
	 * @param array<string,mixed>   $assoc_args Associative args.
	 * @return void
	 */
	public function export( array $args, array $assoc_args = [] ): void {
		$post = self::get_post_or_fail( (int) $args[0] );

		try {
			$exporter = new WPGS_Exporter( WPGS_Settings::get() );
			$exporter->export_post( (int) $post->ID );
		} catch ( Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success(
			sprintf(
				/* translators: 1: Post type, 2: Post ID. */
				__( 'Exported %1$s %2$d to GitHub.', 'wp-git-sync' ),
				(string) $post->post_type,
				(int) $post->ID
			)
		);
	}

	/**
	 * Export all posts for the given post types to GitHub in one commit.
	 *
	 * ## OPTIONS
	 *
	 * [--post_types=<types>]
	 * : Comma-separated list of post types. Default: post,page
	 *
	 * ## EXAMPLES
	 *
	 *     wp git-sync export-all
	 *     wp git-sync export-all --post_types=post,page,press
	 *
	 * @param array<int,string>     $args       Positional args.
	 * @param array<string,mixed>   $assoc_args Associative args.
	 * @return void
	 */
	public function export_all( array $args, array $assoc_args = [] ): void {
		unset( $args );

		$post_types = [ 'post', 'page' ];
		if ( isset( $assoc_args['post_types'] ) ) {
			$post_types = array_values(
				array_filter(
					array_map( 'sanitize_key', explode( ',', (string) $assoc_args['post_types'] ) )
				)
			);
		}

		if ( empty( $post_types ) ) {
			WP_CLI::error( __( 'No valid post types given.', 'wp-git-sync' ) );
		}

		try {
			$exporter = new WPGS_Exporter( WPGS_Settings::get() );
			$exporter->export_all( $post_types );
		} catch ( Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success(
			sprintf(
				/* translators: %s: Comma-separated post type list. */
				__( 'Exported all posts for types: %s', 'wp-git-sync' ),
				implode( ', ', $post_types )
			)
		);
	}

	/**
	 * Import remote files from GitHub into a single post.
	 *
	 * By default imports content, post data and meta. Use --content, --data
	 * or --meta to limit which parts are imported.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : ID of the post to import into.
	 *
	 * [--content]
	 * : Import remote content.md into post_content.
	 *
	 * [--data]
	 * : Import remote post data JSON into the post table row.
	 *
	 * [--meta]
	 * : Import remote meta JSON into post meta.
	 *
	 * ## EXAMPLES
	 *
	 *     wp git-sync import 42
	 *     wp git-sync import 42 --content
	 *
	 * @param array<int,string>     $args       Positional args.
	 * @param array<string,mixed>   $assoc_args Associative args.
	 * @return void
	 */
	public function import( array $args, array $assoc_args = [] ): void {
		$post = self::get_post_or_fail( (int) $args[0] );
		$parts = self::requested_parts( $assoc_args );

		try {
			self::pull_parts( $post, $parts );
		} catch ( Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success(
			sprintf(
				/* translators: 1: Post type, 2: Post ID, 3: Comma-separated part list. */
				__( 'Imported %3$s into %1$s %2$d from GitHub.', 'wp-git-sync' ),
				(string) $post->post_type,
				(int) $post->ID,
				implode( ', ', $parts )
			)
		);
	}

	/**
	 * Round-trip a single post through GitHub.
	 *
	 * First imports the requested remote parts from GitHub into WordPress
	 * (default: content, data and meta), then exports the post back to
	 * GitHub so the repo files, mapping.json and README.md all match
	 * WordPress. GitHub is authoritative for the imported parts.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : ID of the post to round-trip.
	 *
	 * [--content]
	 * : Limit the import step to remote content.md.
	 *
	 * [--data]
	 * : Limit the import step to remote post data JSON.
	 *
	 * [--meta]
	 * : Limit the import step to remote meta JSON.
	 *
	 * ## EXAMPLES
	 *
	 *     # Round-trip every page's content in a shell loop:
	 *     for ID in $(wp post list --post_type=page --format=ids); do
	 *       echo "Processing page $ID...";
	 *       wp git-sync roundtrip "$ID" --content;
	 *     done
	 *
	 * @param array<int,string>     $args       Positional args.
	 * @param array<string,mixed>   $assoc_args Associative args.
	 * @return void
	 */
	public function roundtrip( array $args, array $assoc_args = [] ): void {
		$post = self::get_post_or_fail( (int) $args[0] );
		$parts = self::requested_parts( $assoc_args );

		try {
			self::pull_parts( $post, $parts );

			$exporter = new WPGS_Exporter( WPGS_Settings::get() );
			$exporter->export_post( (int) $post->ID );
		} catch ( Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success(
			sprintf(
				/* translators: 1: Post type, 2: Post ID, 3: Comma-separated part list. */
				__( 'Round-tripped %1$s %2$d (imported %3$s, exported back to GitHub).', 'wp-git-sync' ),
				(string) $post->post_type,
				(int) $post->ID,
				implode( ', ', $parts )
			)
		);
	}

	/**
	 * Fetch a post or fail with a WP-CLI error.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post
	 */
	private static function get_post_or_fail( int $post_id ): WP_Post {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			WP_CLI::error(
				sprintf(
					/* translators: %d: Post ID. */
					__( 'Invalid post ID: %d', 'wp-git-sync' ),
					$post_id
				)
			);
		}
		return $post;
	}

	/**
	 * Determine which parts to import from the CLI flags.
	 *
	 * When no part flag is given, all parts are imported.
	 *
	 * @param array<string,mixed> $assoc_args Associative args.
	 * @return string[] List of parts: content, data, meta.
	 */
	private static function requested_parts( array $assoc_args ): array {
		$parts = [];
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'content', false ) ) {
			$parts[] = 'content';
		}
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'data', false ) ) {
			$parts[] = 'data';
		}
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'meta', false ) ) {
			$parts[] = 'meta';
		}

		if ( empty( $parts ) ) {
			return [ 'content', 'data', 'meta' ];
		}

		return $parts;
	}

	/**
	 * Pull the requested parts from GitHub into a post.
	 *
	 * @param WP_Post  $post  Post.
	 * @param string[] $parts Parts to pull: content, data, meta.
	 * @return void
	 */
	private static function pull_parts( WP_Post $post, array $parts ): void {
		foreach ( $parts as $part ) {
			if ( 'content' === $part ) {
				WPGS_Importer::pull_content( $post );
			} elseif ( 'data' === $part ) {
				WPGS_Importer::pull_post_data( $post );
			} elseif ( 'meta' === $part ) {
				WPGS_Importer::pull_post_meta( $post );
			}
		}
	}
}
