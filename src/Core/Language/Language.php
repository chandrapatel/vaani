<?php
/**
 * Language value object.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Core\Language;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable description of one language Vaani knows about.
 *
 * Bundles the four representations a single language needs across the plugin so
 * no subsystem has to re-derive them: the internal code (meta, URL prefix),
 * the human label, the Sarvam API parameter, and the hreflang value.
 *
 * No `readonly` properties: Vaani targets PHP 8.0 (CLAUDE.md §4), where
 * `readonly` is unavailable. The object is treated as immutable by convention.
 */
class Language {

	/**
	 * @param string $code        Internal code (e.g. `or`); used in meta and `/<lang>/` URLs.
	 * @param string $label       Human-readable, translated label.
	 * @param string $sarvam_code Sarvam API language code (e.g. `od-IN`).
	 * @param string $hreflang    Value emitted in `hreflang` alternate links.
	 */
	public function __construct(
		private string $code,
		private string $label,
		private string $sarvam_code,
		private string $hreflang
	) {}

	/**
	 * Internal language code.
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Human-readable label.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Sarvam API language code.
	 */
	public function sarvam_code(): string {
		return $this->sarvam_code;
	}

	/**
	 * hreflang value.
	 */
	public function hreflang(): string {
		return $this->hreflang;
	}
}
