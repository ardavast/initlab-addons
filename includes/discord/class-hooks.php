<?php
namespace InitLab\Addons\Discord;

use WP_CLI;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Connects the sync to WordPress: when it runs, and where people see it.
 *
 * Discord is never called while an event is being saved. The changed events are noted, and when
 * the request is over a WP-Cron job is queued for each of them. That way Discord can neither slow
 * down nor break saving, and the job can't start before everything about the event is stored.
 *
 * The jobs are due at once. Since WordPress 6.9 looks for due jobs at the end of every request,
 * the sync normally starts right after the save. Older versions start it on the next request.
 */
class Hooks {

	// WP-Cron hooks: sync one event (argument: its post ID), delete a Discord event (argument: its ID).
	public const EVENT_SYNC_HOOK = 'initlab_discord_sync_event';
	public const DELETE_HOOK     = 'initlab_discord_delete_remote';

	private Synchronizer $synchronizer;

	// Post IDs of the events that changed during this request, as keys.
	private array $changed = array();

	public function __construct( Synchronizer $synchronizer ) {
		$this->synchronizer = $synchronizer;
	}

	public function register( string $plugin_file ): void {
		// Saving covers creating, editing, publishing, unpublishing, trashing and restoring.
		add_action( 'save_post', array( $this, 'note_change' ), 100 );
		add_action( 'added_post_meta', array( $this, 'note_thumbnail_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'note_thumbnail_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'note_thumbnail_change' ), 10, 3 );
		// Earlier than the default priority, at which WordPress looks for due WP-Cron jobs.
		add_action( 'shutdown', array( $this, 'queue_changed' ), 5 );
		add_action( 'before_delete_post', array( $this, 'queue_delete' ), 1 );
		add_filter( 'em_event_duplicate', array( $this, 'forget_on_duplicate' ) );

		add_action( 'init', array( $this, 'schedule_full_sync' ) );
		register_deactivation_hook( $plugin_file, array( $this, 'deactivate' ) );

		add_action( self::EVENT_SYNC_HOOK, array( $this, 'run_event_sync' ) );
		add_action( self::DELETE_HOOK, array( $this, 'run_delete' ) );
		add_action( Synchronizer::FULL_SYNC_HOOK, array( $this, 'run_full_sync' ), 10, 0 );

		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'initlab discord-sync', array( $this, 'cli_sync' ) );
		}
	}

	public function synchronizer(): Synchronizer {
		return $this->synchronizer;
	}

	public function note_change( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ! Settings::is_enabled() || EM_POST_TYPE_EVENT !== get_post_type( $post_id )
			|| 'auto-draft' === get_post_status( $post_id ) ) {
			return;
		}

		$this->changed[ $post_id ] = true;
	}

	/**
	 * The featured image is post meta, and setting, replacing or removing it does not save the event.
	 */
	public function note_thumbnail_change( $meta_id, $post_id, $meta_key ): void {
		if ( '_thumbnail_id' === $meta_key ) {
			$this->note_change( $post_id );
		}
	}

	/**
	 * Queues a background sync for each event that changed during the request.
	 *
	 * WP-Cron ignores a job that is already queued for the same event.
	 */
	public function queue_changed(): void {
		foreach ( array_keys( $this->changed ) as $post_id ) {
			wp_schedule_single_event( time(), self::EVENT_SYNC_HOOK, array( $post_id ) );
		}

		$this->changed = array();
	}

	/**
	 * Queues the removal of the Discord event of an event that is deleted for good.
	 *
	 * The post meta goes away with the event, so the job gets the Discord ID itself.
	 */
	public function queue_delete( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ! Settings::is_enabled() || EM_POST_TYPE_EVENT !== get_post_type( $post_id ) ) {
			return;
		}

		$discord_id = Event_Data::get_meta( $post_id, Event_Data::DISCORD_ID );
		if ( '' === $discord_id || ! $this->synchronizer->owns_upcoming( $post_id, $discord_id ) ) {
			return;
		}

		wp_schedule_single_event( time(), self::DELETE_HOOK, array( $discord_id ) );
	}

	/**
	 * Makes a duplicated event start without the Discord event of its original.
	 *
	 * Events Manager copies all post meta. With the copied ID, the duplicate would take over
	 * the original's Discord event instead of getting its own.
	 *
	 * @param \EM_Event|false $event The duplicate, or false if duplicating failed.
	 * @return \EM_Event|false
	 */
	public function forget_on_duplicate( $event ) {
		if ( $event && $event->post_id ) {
			Event_Data::forget( (int) $event->post_id );
			Event_Data::set_meta( (int) $event->post_id, Event_Data::ERROR, '' );
		}

		return $event;
	}

	/**
	 * Keeps the hourly pass scheduled while the sync is switched on, and unscheduled while it is off.
	 *
	 * The pass retries failed events and repairs whatever changed on the Discord side.
	 */
	public function schedule_full_sync(): void {
		$scheduled = wp_next_scheduled( Synchronizer::FULL_SYNC_HOOK );
		$enabled   = Settings::is_enabled();
		if ( $enabled && ! $scheduled ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', Synchronizer::FULL_SYNC_HOOK );
		} elseif ( ! $enabled && $scheduled ) {
			wp_clear_scheduled_hook( Synchronizer::FULL_SYNC_HOOK );
		}
	}

	/**
	 * Removes every queued job when the plugin is deactivated.
	 */
	public function deactivate(): void {
		wp_unschedule_hook( Synchronizer::FULL_SYNC_HOOK );
		wp_unschedule_hook( self::EVENT_SYNC_HOOK );
		wp_unschedule_hook( self::DELETE_HOOK );
	}

	public function run_event_sync( $post_id ): void {
		$this->synchronizer->sync_event( (int) $post_id );
	}

	public function run_delete( $discord_id ): void {
		$this->synchronizer->delete_remote( (string) $discord_id );
	}

	public function run_full_sync(): void {
		$this->synchronizer->sync_all();
	}

	/**
	 * Adds the "Discord" box to the edit screen of events, while the sync is switched on.
	 */
	public function add_meta_box(): void {
		if ( Settings::is_enabled() ) {
			add_meta_box( 'initlab-discord', 'Discord', array( $this, 'render_meta_box' ), EM_POST_TYPE_EVENT, 'side', 'low' );
		}
	}

	public function render_meta_box( WP_Post $post ): void {
		$discord_id = Event_Data::get_meta( $post->ID, Event_Data::DISCORD_ID );
		$error      = Event_Data::get_meta( $post->ID, Event_Data::ERROR );

		if ( '' !== $error ) {
			?>
			<p><strong><?php esc_html_e( 'The last sync with Discord failed:', 'initlab-addons' ); ?></strong><br><?php echo esc_html( $error ); ?></p>
			<?php
		}

		if ( '' === $discord_id ) {
			?>
			<p><?php esc_html_e( 'Not on Discord. Published upcoming events that are not tagged "private" are added within a few minutes.', 'initlab-addons' ); ?></p>
			<?php
			return;
		}

		// Through the invite link, if there is one, people who are not on the server yet can see the event too.
		$invite_url = Settings::invite_url();
		$event_url  = '' !== $invite_url
			? add_query_arg( 'event', $discord_id, $invite_url )
			: 'https://discord.com/events/' . Settings::guild_id() . '/' . $discord_id;
		?>
		<p><a href="<?php echo esc_url( $event_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View this event on Discord', 'initlab-addons' ); ?></a></p>
		<?php
	}

	/**
	 * Syncs all upcoming events to Discord.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Only show what would be done.
	 *
	 * ## EXAMPLES
	 *
	 *     wp initlab discord-sync --dry-run
	 */
	public function cli_sync( $args, $assoc_args ): void {
		if ( ! Settings::is_configured() ) {
			WP_CLI::error( 'Set the Discord guild ID and bot token in the init Lab settings first.' );
		}

		$dry_run = ! empty( $assoc_args['dry-run'] );
		$results = $this->synchronizer->sync_all( $dry_run );
		if ( is_wp_error( $results ) ) {
			WP_CLI::error( $results->get_error_message() );
		}

		$failed = 0;
		foreach ( $results as $post_id => $result ) {
			if ( is_wp_error( $result ) ) {
				++$failed;
				$result = 'FAILED: ' . $result->get_error_message();
			}
			WP_CLI::log( sprintf( '%d %s: %s', $post_id, get_the_title( $post_id ), $result ) );
		}

		$summary = sprintf( '%d upcoming events checked%s.', count( $results ), $dry_run ? ' (dry run)' : '' );
		if ( $failed ) {
			WP_CLI::error( $summary . ' ' . $failed . ' failed.' );
		}
		WP_CLI::success( $summary );
	}
}
