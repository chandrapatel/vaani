<?php
/**
 * Normalized Sarvam API response.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Core\Sarvam;

defined( 'ABSPATH' ) || exit;

/**
 * A small value object normalising the outcome of a Sarvam API call so callers
 * never inspect raw HTTP responses or `WP_Error` objects.
 *
 * No `readonly` properties: Vaani targets PHP 8.0 (CLAUDE.md §4).
 */
class Response {

	/**
	 * @param bool   $ok      Whether the call succeeded.
	 * @param string $text    Result text (e.g. translated_text) on success.
	 * @param string $error   Human-readable error message on failure.
	 * @param int    $units   Billable units consumed (characters), for usage logging.
	 */
	public function __construct(
		private bool $ok,
		private string $text = '',
		private string $error = '',
		private int $units = 0
	) {}

	/**
	 * Build a success response.
	 */
	public static function success( string $text, int $units = 0 ): self {
		return new self( true, $text, '', $units );
	}

	/**
	 * Build a failure response.
	 */
	public static function failure( string $error ): self {
		return new self( false, '', $error );
	}

	/**
	 * Whether the call succeeded.
	 */
	public function ok(): bool {
		return $this->ok;
	}

	/**
	 * Result text.
	 */
	public function text(): string {
		return $this->text;
	}

	/**
	 * Error message.
	 */
	public function error(): string {
		return $this->error;
	}

	/**
	 * Billable units consumed.
	 */
	public function units(): int {
		return $this->units;
	}
}
