<?php
namespace InitLab\Addons\Discord;

defined( 'ABSPATH' ) || exit;

/**
 * Everything about one event: what Discord should show for it, and what is remembered about it.
 */
class Event_Data {

	// Post meta. The Discord ID is visible as a custom field and shared with the older discord2.sh script.
	public const DISCORD_ID = 'discord_event_id';
	public const TEXT_HASH  = '_discord_sync_hash';
	public const IMAGE_HASH = '_discord_image_hash';
	public const ERROR      = '_discord_sync_error';

	// The longest texts Discord accepts.
	private const NAME_LIMIT        = 100;
	private const DESCRIPTION_LIMIT = 1000;
	private const LOCATION_LIMIT    = 100;

	// How much of a cut text may be given up to end it at a word boundary.
	private const WORD_BOUNDARY_SLACK = 0.25;

	// The images Discord accepts.
	private const IMAGE_SIZE_LIMIT = 8 * MB_IN_BYTES;
	private const IMAGE_TYPES      = array( 'image/jpeg', 'image/png', 'image/gif' );

	// Discord's values for an event that only members of the server see, and that takes place outside of Discord.
	private const PRIVACY_GUILD_ONLY = 2;
	private const TYPE_EXTERNAL      = 3;

	/**
	 * @return string The stored value, or an empty string if there is none.
	 */
	public static function get_meta( int $post_id, string $key ): string {
		return (string) get_post_meta( $post_id, $key, true );
	}

	/**
	 * An empty value removes the meta.
	 */
	public static function set_meta( int $post_id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * Forgets the Discord event of an event, so that the next sync starts from nothing.
	 */
	public static function forget( int $post_id ): void {
		delete_post_meta( $post_id, self::DISCORD_ID );
		delete_post_meta( $post_id, self::TEXT_HASH );
		delete_post_meta( $post_id, self::IMAGE_HASH );
	}

	/**
	 * Whether an older event already uses the Discord event, which makes ours a copied ID.
	 */
	public static function is_copied( int $post_id, string $discord_id ): bool {
		$owners = get_posts(
			array(
				'post_type'      => EM_POST_TYPE_EVENT,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Looking an event up by its Discord ID is the point.
				'meta_key'       => self::DISCORD_ID,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Same lookup, limited to one row.
				'meta_value'     => $discord_id,
			)
		);

		return $owners && (int) $owners[0] < $post_id;
	}

	/**
	 * @return int[]|null UNIX timestamps under "start" and "end", or null if the post is not a usable event.
	 */
	public static function times( int $post_id ): ?array {
		if ( EM_POST_TYPE_EVENT !== get_post_type( $post_id ) ) {
			return null;
		}

		$event = em_get_event( $post_id, 'post_id' );
		if ( ! $event || ! $event->event_id || ! $event->event_start_date ) {
			return null;
		}

		$start = $event->start()->getTimestamp();
		$end   = $event->end()->getTimestamp();

		// Discord requires an end time after the start for external events.
		return array(
			'start' => $start,
			'end'   => $end > $start ? $end : $start + HOUR_IN_SECONDS,
		);
	}

	/**
	 * @param int   $post_id Post ID of the event.
	 * @param int[] $times   What times() returned for it.
	 * @return array|null The text fields as Discord names them, or null if the event should not be on Discord.
	 */
	public static function fields( int $post_id, array $times ): ?array {
		$post = get_post( $post_id );
		if ( 'publish' !== $post->post_status || has_term( 'private', EM_TAXONOMY_TAG, $post ) ) {
			return null;
		}

		$event = em_get_event( $post_id, 'post_id' );
		if ( null !== $event->event_active_status && 0 === (int) $event->event_active_status ) {
			// Cancelled.
			return null;
		}

		$site     = get_bloginfo( 'name' );
		$name     = self::plain_text( $post->post_title );
		$location = $event->has_location() ? self::plain_text( $event->get_location()->location_name ) : '';

		// The link to the event always fits: the text in front of it gets what is left.
		$link = get_permalink( $post );
		$text = self::truncate(
			self::plain_text( $post->post_content ),
			self::DESCRIPTION_LIMIT - self::length( "\n\n" . $link )
		);

		return array(
			'name'                 => self::truncate( '' !== $name ? $name : $site, self::NAME_LIMIT ),
			'description'          => '' !== $text ? $text . "\n\n" . $link : $link,
			'scheduled_start_time' => gmdate( 'Y-m-d\TH:i:s\Z', $times['start'] ),
			'scheduled_end_time'   => gmdate( 'Y-m-d\TH:i:s\Z', $times['end'] ),
			'privacy_level'        => self::PRIVACY_GUILD_ONLY,
			'entity_type'          => self::TYPE_EXTERNAL,
			'entity_metadata'      => array(
				'location' => self::truncate( '' !== $location ? $location : $site, self::LOCATION_LIMIT ),
			),
		);
	}

	/**
	 * @return string[]|null The "file", "mime" type and "hash" of the featured image, or null if there is none that Discord can take.
	 */
	public static function image( int $post_id ): ?array {
		$attachment_id = get_post_thumbnail_id( $post_id );
		if ( ! $attachment_id ) {
			return null;
		}

		$file = get_attached_file( $attachment_id );
		$mime = get_post_mime_type( $attachment_id );
		if ( ! $file || ! is_readable( $file ) || filesize( $file ) > self::IMAGE_SIZE_LIMIT
			|| ! in_array( $mime, self::IMAGE_TYPES, true ) ) {
			return null;
		}

		return array(
			'file' => $file,
			'mime' => $mime,
			'hash' => hash_file( 'sha256', $file ),
		);
	}

	/**
	 * @param string[] $image What image() returned.
	 * @return string The image in the form Discord expects it in a request: a data URI.
	 */
	public static function image_data( array $image ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of the media library.
		$contents = file_get_contents( $image['file'] );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Discord takes images as base64 data URIs.
		return 'data:' . $image['mime'] . ';base64,' . base64_encode( $contents );
	}

	/**
	 * Turns HTML into plain text, keeping the paragraphs.
	 */
	public static function plain_text( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( strip_shortcodes( $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\r\n", "\r", "\xC2\xA0" ), array( "\n", "\n", ' ' ), $text );
		$text = preg_replace( '/[ \t]+\n/', "\n", $text );

		return trim( preg_replace( '/\n{3,}/', "\n\n", $text ) );
	}

	/**
	 * Length of a text the way Discord counts it: characters outside the BMP count twice.
	 */
	public static function length( string $text ): int {
		return mb_strlen( $text ) + (int) preg_match_all( '/[\x{10000}-\x{10FFFF}]/u', $text );
	}

	/**
	 * Shortens a text to a length that Discord accepts.
	 *
	 * A text that is too long ends in an ellipsis. It is cut between two words, unless
	 * its last word is so long that too much of the text would be lost.
	 */
	public static function truncate( string $text, int $limit ): string {
		$text = trim( $text );
		if ( self::length( $text ) <= $limit ) {
			return $text;
		}

		// One character is kept free for the ellipsis.
		$cut = mb_substr( $text, 0, $limit - 1 );
		while ( self::length( $cut ) > $limit - 1 ) {
			$cut = mb_substr( $cut, 0, -1 );
		}

		$next_character = mb_substr( $text, mb_strlen( $cut ), 1 );
		if ( ! preg_match( '/^\s$/u', $next_character ) ) {
			// The cut is inside a word: drop the part of the word that is left.
			$whole_words = preg_replace( '/\s+\S*$/u', '', $cut );
			if ( mb_strlen( $whole_words ) >= ( 1 - self::WORD_BOUNDARY_SLACK ) * mb_strlen( $cut ) ) {
				$cut = $whole_words;
			}
		}

		// Punctuation and brackets that would be left hanging in front of the ellipsis.
		return preg_replace( '/[\s.,:;!?\-–—(\[„“"«]+$/u', '', $cut ) . '…';
	}
}
