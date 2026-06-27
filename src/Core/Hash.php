<?php
/**
 * Content hashing for staleness detection.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a stable fingerprint of post content.
 *
 * A translation stores the hash of its source's `post_content` at translation
 * time; when the source is later edited the hashes diverge and the translation
 * is flagged stale. Centralised so every subsystem hashes content the same way.
 */
class Hash {

	/**
	 * Hash of post content.
	 *
	 * Whitespace at the edges is trimmed so that cosmetic-only saves do not
	 * register as content changes.
	 */
	public static function of( string $content ): string {
		return md5( trim( $content ) );
	}
}
