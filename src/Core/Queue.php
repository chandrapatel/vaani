<?php
/**
 * Background job queue.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over Action Scheduler so callers never reference the global
 * `as_*` functions directly.
 *
 * Long-running work (translation, later TTS) must run in the background, never
 * blocking an admin request on a multi-second API call (CLAUDE.md §4).
 */
class Queue {

	/**
	 * Action group used for all Vaani jobs (groups them in the AS admin UI).
	 */
	public const GROUP = 'vaani';

	/**
	 * Enqueue a one-off async job, unless an identical one is already pending.
	 *
	 * @param string       $hook Action hook the job runs.
	 * @param array<mixed> $args Positional args passed to the hook callback.
	 * @return bool True if a job was enqueued, false if one was already queued
	 *              or Action Scheduler is unavailable.
	 */
	public function enqueue( string $hook, array $args = array() ): bool {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		if ( $this->is_scheduled( $hook, $args ) ) {
			return false;
		}

		as_enqueue_async_action( $hook, $args, self::GROUP );

		return true;
	}

	/**
	 * Whether an identical job is already scheduled/pending.
	 *
	 * @param string       $hook Action hook.
	 * @param array<mixed> $args Positional args.
	 */
	public function is_scheduled( string $hook, array $args = array() ): bool {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		return as_has_scheduled_action( $hook, $args, self::GROUP );
	}
}
