<?php
/**
 * Plugin Name: Website Cache Refresh
 * Author: Kevin Borchers
 * Author URI: https://wordandpicture.com
 * Description: Manually clear the configured cPanel account's ea-nginx cache.
 * Version: 1.1.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: website-cache-refresh
 * Domain Path: /languages
 */

namespace WebsiteCacheRefresh;

defined( 'ABSPATH' ) || exit;

const SLUG = 'website-cache-refresh';
const ACTION = 'website_cache_refresh_clear';

function capability() {
	$value = apply_filters( 'website_cache_refresh_capability', 'manage_options' );
	return is_string( $value ) && '' !== trim( $value ) ? $value : 'manage_options';
}

function page_url() {
	return admin_url( 'admin.php?page=' . SLUG );
}

function configuration() {
	$names = array( 'WEBSITE_CACHE_REFRESH_CPANEL_HOST', 'WEBSITE_CACHE_REFRESH_CPANEL_USERNAME', 'WEBSITE_CACHE_REFRESH_CPANEL_API_TOKEN' );
	$values = array();
	foreach ( $names as $name ) {
		if ( ! defined( $name ) || ! is_string( constant( $name ) ) || '' === trim( constant( $name ) ) ) {
			return new \WP_Error( 'configuration_missing' );
		}
		$values[] = constant( $name );
	}
	list( $host, $username, $token ) = $values;
	// Only a DNS hostname: no scheme, port, path, credentials, or header characters.
	if ( strlen( $host ) > 253 || ! preg_match( '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/i', $host )
		|| ! preg_match( '/\A[a-z0-9_]+\z/i', $username )
		|| ! preg_match( '/\A[\x21-\x7e]+\z/', $token ) ) {
		return new \WP_Error( 'configuration_invalid' );
	}
	return array( 'host' => $host, 'username' => $username, 'token' => $token );
}

/** Returns only fixed diagnostic identifiers; remote text is never displayed or stored. */
function clear_cache() {
	$config = configuration();
	if ( is_wp_error( $config ) ) {
		return $config->get_error_code();
	}
	$response = wp_remote_get(
		'https://' . $config['host'] . ':2083/execute/NginxCaching/clear_cache',
		array(
			'timeout'             => 20,
			'redirection'         => 0,
			'sslverify'           => true,
			'limit_response_size' => 65536,
			'headers'            => array(
				'Authorization' => 'cpanel ' . $config['username'] . ':' . $config['token'],
				'Accept'        => 'application/json',
			),
		)
	);
	if ( is_wp_error( $response ) ) {
		return 'transport_error';
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		return $status >= 100 && $status <= 599 ? 'http_' . $status : 'http_error';
	}
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) || ! array_key_exists( 'status', $body )
		|| ! in_array( $body['status'], array( 0, 1, '0', '1' ), true ) ) {
		return 'malformed_response';
	}
	if ( 1 !== (int) $body['status'] || ! empty( $body['errors'] ) ) {
		return 'uapi_failure';
	}
	return 'success';
}

function notice_text( $code ) {
	$messages = array(
		'success'               => __( 'Website cache cleared. Reload your website to see recent changes. Your content and settings have not been deleted.', 'website-cache-refresh' ),
		'configuration_missing' => __( 'Cache refresh is not configured. Ask your site manager to set the three cPanel constants in wp-config.php.', 'website-cache-refresh' ),
		'configuration_invalid' => __( 'The cPanel configuration is invalid. Use a hostname without https://, a port, or a path, and check the username and token for spaces or line breaks.', 'website-cache-refresh' ),
		'transport_error'       => __( 'Unable to complete the secure cPanel request. Ask your host to check DNS, the TLS certificate, outbound port 2083, and timeouts. If the request timed out, the cache may already have been cleared.', 'website-cache-refresh' ),
		'http_error'            => __( 'cPanel returned an unexpected HTTP response. Ask your hosting provider to check cPanel connectivity.', 'website-cache-refresh' ),
		'malformed_response'    => __( 'cPanel returned an unreadable or unexpected API response. Ask your host to check the cPanel hostname, proxy, and UAPI endpoint.', 'website-cache-refresh' ),
		'uapi_failure'          => __( 'cPanel reported that the cache clear failed. Ask your host to confirm ea-nginx caching is available for this account and that the API token can call NginxCaching/clear_cache.', 'website-cache-refresh' ),
	);
	if ( isset( $messages[ $code ] ) ) {
		return $messages[ $code ];
	}
	if ( preg_match( '/\Ahttp_([1-5][0-9]{2})\z/', $code, $matches ) ) {
		/* translators: %s: HTTP status code. */
		return sprintf( __( 'cPanel returned HTTP %s. For 401/403, check the username, token expiry/revocation, and account access. For redirects or 404, check the cPanel hostname. For 429 or 5xx, wait and contact your host if it persists.', 'website-cache-refresh' ), $matches[1] );
	}
	return __( 'An unexpected cache refresh error occurred. Contact your site manager.', 'website-cache-refresh' );
}

function render_page() {
	if ( ! current_user_can( capability() ) ) {
		wp_die( esc_html__( 'You do not have permission to clear the website cache.', 'website-cache-refresh' ), '', array( 'response' => 403 ) );
	}
	echo '<div class="wrap"><h1>' . esc_html__( 'Website Cache Refresh', 'website-cache-refresh' ) . '</h1>';
	// Results are server-generated, short-lived, user-specific, and consumed once.
	$id = isset( $_GET['wcr_result'] ) && is_string( $_GET['wcr_result'] ) ? sanitize_key( wp_unslash( $_GET['wcr_result'] ) ) : '';
	if ( preg_match( '/\A[a-f0-9-]{36}\z/', $id ) ) {
		$key = 'wcr_' . get_current_user_id() . '_' . $id;
		$code = get_transient( $key );
		delete_transient( $key );
		if ( is_string( $code ) ) {
			echo '<div role="status" class="notice ' . ( 'success' === $code ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( notice_text( $code ) ) . ' <code>' . esc_html( $code ) . '</code></p></div>';
		}
	}
	echo '<p>' . esc_html__( 'Not seeing your changes? Clearing the website cache can make recent changes visible. It does not delete site content or settings.', 'website-cache-refresh' ) . '</p>';
	echo '<p>' . esc_html__( 'This clears the NGINX cache for all websites under the configured cPanel account. It does not clear browser, CDN, or other plugin caches.', 'website-cache-refresh' ) . '</p>';
	$config = configuration();
	if ( is_wp_error( $config ) ) {
		echo '<div class="notice notice-warning"><p>' . esc_html( notice_text( $config->get_error_code() ) ) . '</p></div>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( ACTION ) . '">';
	wp_nonce_field( ACTION );
	submit_button( __( 'Clear Website Cache', 'website-cache-refresh' ), 'primary', 'submit', true, is_wp_error( $config ) ? array( 'disabled' => 'disabled' ) : array() );
	echo '</form></div>';
}

function handle_clear() {
	if ( ! current_user_can( capability() ) ) {
		wp_die( esc_html__( 'You do not have permission to clear the website cache.', 'website-cache-refresh' ), '', array( 'response' => 403 ) );
	}
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
		wp_die( esc_html__( 'Use the Clear Website Cache button to submit this action.', 'website-cache-refresh' ), '', array( 'response' => 405 ) );
	}
	check_admin_referer( ACTION );
	$code = clear_cache();
	$id   = wp_generate_uuid4();
	set_transient( 'wcr_' . get_current_user_id() . '_' . $id, $code, 120 );
	wp_safe_redirect( add_query_arg( 'wcr_result', $id, page_url() ), 303 );
	exit;
}

add_action( 'init', function () {
	load_plugin_textdomain( 'website-cache-refresh', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );
add_action( 'admin_menu', function () {
	add_menu_page( __( 'Website Cache Refresh', 'website-cache-refresh' ), __( 'Website Cache', 'website-cache-refresh' ), capability(), SLUG, __NAMESPACE__ . '\\render_page', 'dashicons-update', 81 );
} );
add_action( 'admin_bar_menu', function ( $bar ) {
	if ( current_user_can( capability() ) ) {
		$bar->add_node( array( 'id' => SLUG, 'title' => __( 'Clear Website Cache', 'website-cache-refresh' ), 'href' => page_url(), 'meta' => array( 'title' => __( 'Not seeing your changes?', 'website-cache-refresh' ) ) ) );
	}
}, 100 );
add_action( 'admin_post_' . ACTION, __NAMESPACE__ . '\\handle_clear' );
