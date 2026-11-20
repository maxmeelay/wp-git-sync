<?php
/**
 * Not-found exception.
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown when a GitHub resource (for example a repo file) does not exist.
 *
 * Distinguishes 404 responses from other API failures so callers can handle
 * "not exported yet" gracefully.
 */
class WPGS_Not_Found_Exception extends RuntimeException {
}
