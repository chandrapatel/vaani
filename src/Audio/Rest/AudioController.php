<?php
/**
 * REST endpoints for the editor sidebar's Audio section.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Audio\Rest;

use Vaani\Audio\AudioService;
use Vaani\Audio\AudioStatusPresenter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `vaani/v1/audio`: read per-language audio status, queue a generation.
 *
 * apiFetch sends the REST nonce automatically; authorization is the
 * `edit_post` capability check in {@see self::can_edit()}. The queue + guard
 * mirror the former meta box, returning data instead of HTML.
 */
class AudioController {

	/**
	 * REST namespace.
	 */
	private const REST_NAMESPACE = 'vaani/v1';

	/**
	 * Route, relative to the namespace.
	 */
	private const ROUTE = '/audio';

	/**
	 * @param AudioService         $service   Audio orchestration.
	 * @param AudioStatusPresenter $presenter Status data shaper.
	 */
	public function __construct(
		private AudioService $service,
		private AudioStatusPresenter $presenter
	) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the read + queue routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'post' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'queue' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'post' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'lang' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);
	}

	/**
	 * Whether the current user may edit the target post.
	 */
	public function can_edit( WP_REST_Request $request ): bool {
		return current_user_can( 'edit_post', (int) $request->get_param( 'post' ) );
	}

	/**
	 * GET: audio status of every eligible language for the post.
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( $this->presenter->for_source( (int) $request->get_param( 'post' ) ) );
	}

	/**
	 * POST: queue audio generation and return that language's updated row.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function queue( WP_REST_Request $request ) {
		$source_id = (int) $request->get_param( 'post' );
		$lang      = (string) $request->get_param( 'lang' );

		$queued = $this->service->queue( $source_id, $lang );
		$row    = $this->presenter->language( $source_id, $lang );

		if ( null === $row || ( ! $queued && 'pending' !== $row['status'] ) ) {
			return new WP_Error(
				'vaani_audio_failed',
				__( 'Audio cannot be generated for that language yet.', 'vaani' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $row );
	}
}
