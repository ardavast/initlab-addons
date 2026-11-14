<?php
namespace InitLab\Addons\Discord;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the scheduled events API of the configured Discord server.
 *
 * Every method returns a WP_Error when Discord can't be reached or refuses the request.
 */
class Client {

	private const API_BASE   = 'https://discord.com/api/v10';
	private const USER_AGENT = 'DiscordBot (https://github.com/initLab/initlab-addons, 1)';

	// Seconds to wait for Discord to answer.
	private const TIMEOUT = 30;

	// The longest pause, in seconds, that a background run takes to stay within the rate limit.
	private const LONGEST_PAUSE = 10;

	/**
	 * @return array[]|WP_Error The server's events, keyed by their ID.
	 */
	public function list_events() {
		$events = $this->request( 'GET' );

		return is_wp_error( $events ) ? $events : array_column( $events, null, 'id' );
	}

	/**
	 * @return array|null|WP_Error The event, or null if Discord doesn't have it.
	 */
	public function get_event( string $id ) {
		$event = $this->request( 'GET', $id );

		return $this->is_not_found( $event ) ? null : $event;
	}

	/**
	 * @param array $fields Fields of the event, as Discord names them.
	 * @return array|WP_Error The created event.
	 */
	public function create_event( array $fields ) {
		return $this->request( 'POST', '', $fields );
	}

	/**
	 * @param string $id     ID of the Discord event.
	 * @param array  $fields The fields to change, as Discord names them.
	 * @return array|WP_Error The changed event.
	 */
	public function update_event( string $id, array $fields ) {
		return $this->request( 'PATCH', $id, $fields );
	}

	/**
	 * An event that is already gone counts as deleted.
	 *
	 * @return true|WP_Error
	 */
	public function delete_event( string $id ) {
		$response = $this->request( 'DELETE', $id );

		return is_wp_error( $response ) && ! $this->is_not_found( $response ) ? $response : true;
	}

	private function is_not_found( $response ): bool {
		return is_wp_error( $response ) && 404 === ( $response->get_error_data()['status'] ?? 0 );
	}

	/**
	 * @param string     $method   HTTP method.
	 * @param string     $event_id ID of the Discord event the request is about, if any.
	 * @param array|null $body     Data to send as JSON.
	 * @return array|WP_Error The decoded response.
	 */
	private function request( string $method, string $event_id = '', ?array $body = null ) {
		/**
		 * Filters the base URL of the Discord API.
		 *
		 * @param string $base URL without a trailing slash.
		 */
		$base = apply_filters( 'initlab_discord_api_base', self::API_BASE );
		$url  = $base . '/guilds/' . rawurlencode( Settings::guild_id() ) . '/scheduled-events';
		if ( '' !== $event_id ) {
			$url .= '/' . rawurlencode( $event_id );
		}

		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bot ' . Settings::bot_token(),
				'User-Agent'    => self::USER_AGENT,
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->respect_rate_limit( $response );

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );
		if ( $status >= 200 && $status < 300 ) {
			return is_array( $data ) ? $data : array();
		}

		$detail = is_array( $data ) ? wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) : wp_strip_all_tags( $raw );

		return new WP_Error(
			429 === $status ? 'discord_rate_limited' : 'discord_http_error',
			sprintf( 'Discord: %s -> HTTP %d %s', $method, $status, mb_substr( trim( $detail ), 0, 300 ) ),
			array(
				'status'      => $status,
				'retry_after' => is_array( $data ) ? (float) ( $data['retry_after'] ?? 0 ) : 0,
			)
		);
	}

	/**
	 * Pauses a background run when Discord says that the next request would be refused.
	 *
	 * The length of the pause is the one Discord asks for. Requests made while somebody is waiting
	 * for a page are never held up.
	 */
	private function respect_rate_limit( array $response ): void {
		$in_background = wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
		if ( ! $in_background || '0' !== wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) ) {
			return;
		}

		$reset_after = (float) wp_remote_retrieve_header( $response, 'x-ratelimit-reset-after' );
		sleep( (int) min( self::LONGEST_PAUSE, ceil( $reset_after ) ) );
	}
}
