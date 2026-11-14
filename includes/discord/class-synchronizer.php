<?php
namespace InitLab\Addons\Discord;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, updates and deletes Discord events so that they match the Events Manager events.
 *
 * A sync of one event has two halves. plan() only reads: it compares the event with what Discord
 * has and returns the steps that would make them match. apply() carries those steps out. A dry run
 * is a plan that is never applied.
 */
class Synchronizer {

	// The WP-Cron hook of the pass over all upcoming events.
	public const FULL_SYNC_HOOK = 'initlab_discord_sync_all';

	// The option that holds the outcome of the last pass, for the settings page.
	private const LAST_SYNC_OPTION = 'initlab_discord_last_sync';

	// Discord's status of an event that has not started yet, and of one that is taking place.
	private const STATUS_SCHEDULED = 1;
	private const STATUS_ACTIVE    = 2;

	// The shortest wait, in seconds, before a rate limited pass is continued.
	private const SHORTEST_RETRY_WAIT = MINUTE_IN_SECONDS;

	private Client $client;

	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Syncs one event and remembers the outcome, so that it can be shown on the event's edit screen.
	 *
	 * @param int          $post_id       Post ID of the event.
	 * @param array[]|null $remote_events Discord's events keyed by ID, if the caller already listed them.
	 * @param bool         $dry_run       Whether to only report what would be done.
	 * @return string|WP_Error What was done, or would be done on a dry run.
	 */
	public function sync_event( int $post_id, ?array $remote_events = null, bool $dry_run = false ) {
		if ( ! Settings::is_configured() ) {
			return 'skip (Discord is not configured)';
		}

		$plan = $this->plan( $post_id, $remote_events );
		if ( $dry_run ) {
			return is_wp_error( $plan ) ? $plan : $plan['summary'];
		}

		$outcome = is_wp_error( $plan ) ? $plan : $this->apply( $post_id, $plan['steps'] );
		$result  = is_wp_error( $outcome ) ? $outcome : $plan['summary'];
		$this->remember_outcome( $post_id, $result );

		return $result;
	}

	/**
	 * Syncs every upcoming event.
	 *
	 * @return array|WP_Error The outcome of sync_event() per post ID, or an error if Discord can't be reached at all.
	 */
	public function sync_all( bool $dry_run = false ) {
		if ( ! Settings::is_configured() ) {
			return array();
		}

		$results = $this->sync_upcoming( $dry_run );
		if ( ! $dry_run ) {
			$this->finish_pass( $results );
		}

		return $results;
	}

	/**
	 * Deletes a Discord event whose event no longer exists in WordPress.
	 */
	public function delete_remote( string $discord_id ): void {
		if ( ! Settings::is_configured() ) {
			return;
		}

		$deleted = $this->client->delete_event( $discord_id );
		if ( is_wp_error( $deleted ) ) {
			$this->log( 'could not delete Discord event ' . $discord_id . ': ' . $deleted->get_error_message() );
		}
	}

	/**
	 * Whether a Discord event belongs to this event, and the event has not started yet.
	 */
	public function owns_upcoming( int $post_id, string $discord_id ): bool {
		$times = Event_Data::times( $post_id );

		return $times && $times['start'] > time() && ! Event_Data::is_copied( $post_id, $discord_id );
	}

	/**
	 * Works out what has to be done for one event, without changing anything.
	 *
	 * @param int          $post_id       Post ID of the event.
	 * @param array[]|null $remote_events Discord's events keyed by ID, if the caller already listed them.
	 * @return array|WP_Error The "steps" for apply() and a "summary" of them for people.
	 */
	private function plan( int $post_id, ?array $remote_events ) {
		$times = Event_Data::times( $post_id );
		if ( ! $times ) {
			return $this->skip( 'not an event' );
		}
		if ( $times['start'] <= time() ) {
			// Discord starts and completes events on its own.
			return $this->skip( 'already started' );
		}

		$steps      = array();
		$discord_id = Event_Data::get_meta( $post_id, Event_Data::DISCORD_ID );
		$remote     = null;
		if ( '' !== $discord_id ) {
			if ( null !== $remote_events ) {
				$remote = $remote_events[ $discord_id ] ?? null;
			} else {
				$remote = $this->client->get_event( $discord_id );
			}
			if ( is_wp_error( $remote ) ) {
				return $remote;
			}
			if ( $remote && self::STATUS_ACTIVE === (int) $remote['status'] ) {
				return $this->skip( 'active on Discord' );
			}

			$is_ours = $remote && self::STATUS_SCHEDULED === (int) $remote['status']
				&& ! Event_Data::is_copied( $post_id, $discord_id );
			if ( ! $is_ours ) {
				// Gone or finished on Discord, or an ID copied from another event: start over.
				$steps[]    = array( 'action' => 'forget' );
				$discord_id = '';
				$remote     = null;
			}
		}

		$fields = Event_Data::fields( $post_id, $times );
		if ( ! $fields ) {
			if ( '' === $discord_id ) {
				return $this->skip( 'not for Discord', $steps );
			}

			$steps[] = array(
				'action'     => 'delete',
				'label'      => 'delete',
				'discord_id' => $discord_id,
			);
			return $this->summarize( $steps );
		}

		$text_hash = hash( 'sha256', wp_json_encode( $fields ) );
		$image     = Event_Data::image( $post_id );
		if ( '' === $discord_id ) {
			$steps[] = array(
				'action'    => 'create',
				'label'     => 'create',
				'fields'    => $fields,
				'text_hash' => $text_hash,
				'image'     => $image,
			);
			return $this->summarize( $steps );
		}

		if ( Event_Data::get_meta( $post_id, Event_Data::TEXT_HASH ) !== $text_hash ) {
			$steps[] = array(
				'action'     => 'update',
				'label'      => 'update',
				'discord_id' => $discord_id,
				'fields'     => $fields,
				'text_hash'  => $text_hash,
			);
		}

		// If the image disappeared on the Discord side, whatever we uploaded no longer counts.
		$image_hash    = $image ? $image['hash'] : '';
		$stored_hash   = Event_Data::get_meta( $post_id, Event_Data::IMAGE_HASH );
		$uploaded_hash = empty( $remote['image'] ) ? '' : $stored_hash;
		if ( $uploaded_hash !== $image_hash ) {
			$steps[] = array(
				'action'     => 'image',
				'label'      => $image ? 'upload image' : 'remove image',
				'discord_id' => $discord_id,
				'image'      => $image,
			);
		} elseif ( $stored_hash !== $image_hash ) {
			$steps[] = array(
				'action'     => 'remember_image',
				'image_hash' => $image_hash,
			);
		}

		return $this->summarize( $steps );
	}

	/**
	 * A plan for an event that is left alone. Its steps only tidy up what is remembered about the event.
	 */
	private function skip( string $reason, array $steps = array() ): array {
		return array(
			'steps'   => $steps,
			'summary' => 'skip (' . $reason . ')',
		);
	}

	/**
	 * A plan made of the given steps. The steps that have a label are the ones people get to see.
	 */
	private function summarize( array $steps ): array {
		$labels = array_column( $steps, 'label' );

		return array(
			'steps'   => $steps,
			'summary' => $labels ? implode( ', ', $labels ) : 'unchanged',
		);
	}

	/**
	 * Carries out the steps of a plan, up to the first one that fails.
	 *
	 * @return true|WP_Error
	 */
	private function apply( int $post_id, array $steps ) {
		foreach ( $steps as $step ) {
			$done = $this->apply_step( $post_id, $step );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	private function apply_step( int $post_id, array $step ) {
		switch ( $step['action'] ) {
			case 'forget':
				Event_Data::forget( $post_id );
				return true;

			case 'delete':
				$deleted = $this->client->delete_event( $step['discord_id'] );
				if ( is_wp_error( $deleted ) ) {
					return $deleted;
				}
				Event_Data::forget( $post_id );
				return true;

			case 'create':
				$image  = $step['image'];
				$fields = $step['fields'];
				if ( $image ) {
					$fields['image'] = Event_Data::image_data( $image );
				}

				$created = $this->client->create_event( $fields );
				if ( is_wp_error( $created ) ) {
					return $created;
				}
				if ( empty( $created['id'] ) ) {
					return new WP_Error( 'discord_bad_response', 'Discord: the created event has no ID' );
				}
				Event_Data::set_meta( $post_id, Event_Data::DISCORD_ID, (string) $created['id'] );
				Event_Data::set_meta( $post_id, Event_Data::TEXT_HASH, $step['text_hash'] );
				Event_Data::set_meta( $post_id, Event_Data::IMAGE_HASH, $image ? $image['hash'] : '' );
				return true;

			case 'update':
				$updated = $this->client->update_event( $step['discord_id'], $step['fields'] );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
				Event_Data::set_meta( $post_id, Event_Data::TEXT_HASH, $step['text_hash'] );
				return true;

			case 'image':
				$image   = $step['image'];
				$updated = $this->client->update_event(
					$step['discord_id'],
					array( 'image' => $image ? Event_Data::image_data( $image ) : null )
				);
				if ( is_wp_error( $updated ) ) {
					if ( $image ) {
						return $updated;
					}
					// Discord may not allow removing a cover image: keep the old one rather than retrying forever.
					$this->log( 'could not remove the Discord image of event ' . $post_id . ': ' . $updated->get_error_message() );
				}
				Event_Data::set_meta( $post_id, Event_Data::IMAGE_HASH, $image ? $image['hash'] : '' );
				return true;

			case 'remember_image':
				Event_Data::set_meta( $post_id, Event_Data::IMAGE_HASH, $step['image_hash'] );
				return true;
		}

		return new WP_Error( 'discord_unknown_step', 'Discord: unknown step ' . $step['action'] );
	}

	/**
	 * Syncs the upcoming events one by one, stopping early if Discord's rate limit is reached.
	 *
	 * @return array|WP_Error The outcome of sync_event() per post ID, or an error if Discord can't be reached at all.
	 */
	private function sync_upcoming( bool $dry_run ) {
		$remote_events = $this->client->list_events();
		if ( is_wp_error( $remote_events ) ) {
			return $remote_events;
		}

		$results = array();
		foreach ( $this->upcoming_events() as $post_id ) {
			$results[ $post_id ] = $this->sync_event( $post_id, $remote_events, $dry_run );
			if ( null !== $this->retry_after( $results[ $post_id ] ) ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Stores the outcome of a pass for the settings page, and plans the rest of a pass that was cut short.
	 *
	 * @param array|WP_Error $results What sync_upcoming() returned.
	 */
	private function finish_pass( $results ): void {
		if ( is_wp_error( $results ) ) {
			$this->remember_pass( array( 'error' => $results->get_error_message() ) );
			return;
		}

		// A pass stops at the event that ran into the rate limit: come back for the rest once Discord lets us.
		$retry_after = $this->retry_after( end( $results ) );
		if ( null !== $retry_after ) {
			wp_schedule_single_event( time() + max( self::SHORTEST_RETRY_WAIT, $retry_after ), self::FULL_SYNC_HOOK );
		}

		$this->remember_pass(
			array(
				'events' => count( $results ),
				'failed' => count( array_filter( $results, 'is_wp_error' ) ),
			)
		);
	}

	/**
	 * @return int[] Post IDs of the events that have not started yet, whatever their status.
	 */
	private function upcoming_events(): array {
		return get_posts(
			array(
				'post_type'      => EM_POST_TYPE_EVENT,
				'post_status'    => array( 'publish', 'future', 'pending', 'draft', 'private', 'trash' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				// The start is stored in the event's own timezone, so a day of slack is allowed here:
				// the exact check happens per event.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Runs in the background, on the few upcoming events.
				'meta_query'     => array(
					array(
						'key'     => '_event_start_local',
						'value'   => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
						'compare' => '>',
						'type'    => 'DATETIME',
					),
				),
			)
		);
	}

	/**
	 * @param string|WP_Error|false $result What sync_event() returned, or false if no event was synced.
	 * @return int|null The seconds Discord wants us to wait, or null if the sync was not rate limited.
	 */
	private function retry_after( $result ): ?int {
		if ( ! is_wp_error( $result ) || 'discord_rate_limited' !== $result->get_error_code() ) {
			return null;
		}

		return (int) ceil( $result->get_error_data()['retry_after'] ?? 0 );
	}

	/**
	 * Stores the outcome of a sync on the event: its error, or nothing if it went well.
	 *
	 * @param int             $post_id Post ID of the event.
	 * @param string|WP_Error $result  The outcome.
	 */
	private function remember_outcome( int $post_id, $result ): void {
		if ( ! get_post( $post_id ) ) {
			return;
		}

		Event_Data::set_meta( $post_id, Event_Data::ERROR, is_wp_error( $result ) ? $result->get_error_message() : '' );
		if ( is_wp_error( $result ) ) {
			$this->log( 'Discord sync of event ' . $post_id . ' failed: ' . $result->get_error_message() );
		}
	}

	/**
	 * @param array $outcome The "error" of a pass, or the number of "events" and how many of them "failed".
	 */
	private function remember_pass( array $outcome ): void {
		update_option( self::LAST_SYNC_OPTION, array( 'time' => time() ) + $outcome, false );
	}

	/**
	 * Writes to the PHP error log. Used for failures that have no event to be shown on.
	 */
	private function log( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Failures of a background job have nowhere else to go.
		error_log( 'init Lab Addons: ' . $message );
	}
}
