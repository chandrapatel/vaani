<?php
/**
 * Vaani uninstall cleanup.
 *
 * @package Vaani
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'vaani_settings' );
