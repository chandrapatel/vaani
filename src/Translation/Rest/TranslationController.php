<?php
/**
 * REST endpoints for the editor sidebar's Translations section.
 *
 * @package Vaani
 */

declare( strict_types=1 );

namespace Vaani\Translation\Rest;

use Vaani\Translation\TranslationService;
use Vaani\Translation\TranslationStatusPresenter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `vaani/v1/translations`: read per-language status, queue a translation.
 *
 * apiFetch sends the REST nonce automatically; authorization is the
 * `edit_post` capability check in {@see self::can_edit()}. The queue + guard
 * mirror the former meta box, returning data instead of HTML.
 */
class TranslationController {

	/**
	 * REST namespace.
	 */
	private const REST_NAMESPACE = 'vaani/v1';

	/**
	 * Route, relative to the namespace.
	 */
	private const ROUTE = '/translations';

	/**
	 * @param TranslationService          $service   Translation orchestration.
	 * @param TranslationStatusPresenter  $presenter Status data shaper.
	 */
	public function __construct(
		private TranslationService $service,
		private TranslationStatusPresenter $presenter
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
	 * GET: status of every enabled language for the post, plus the cost line.
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( $this->presenter->for_source( (int) $request->get_param( 'post' ) ) );
	}

	/**
	 * POST: queue a translation and return that language's updated row.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function queue( WP_REST_Request $request ) {
		$source_id = (int) $request->get_param( 'post' );
		$lang      = (string) $request->get_param( 'lang' );

		$queued = $this->service->queue( $source_id, $lang );
		$row    = $this->presenter->language( $source_id, $lang );

		if ( null === $row || ( ! $queued && 'none' === $row['status'] ) ) {
			return new WP_Error(
				'vaani_translation_failed',
				__( 'That language cannot be translated.', 'vaani' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $row );
	}
}
