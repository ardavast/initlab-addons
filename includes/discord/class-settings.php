<?php
namespace InitLab\Addons\Discord;

defined( 'ABSPATH' ) || exit;

/**
 * The Discord part of the init Lab settings.
 *
 * Nothing is cached here, so a change of the settings applies to the next call.
 */
class Settings {

	public static function guild_id(): string {
		return self::get( 'discord_guild_id' );
	}

	public static function bot_token(): string {
		return self::get( 'discord_bot_token' );
	}

	public static function invite_url(): string {
		return self::get( 'discord_invite_url' );
	}

	/**
	 * Whether a sync can run at all. This is enough to run one by hand.
	 */
	public static function is_configured(): bool {
		return '' !== self::guild_id() && '' !== self::bot_token() && defined( 'EM_POST_TYPE_EVENT' );
	}

	/**
	 * Whether the automatic sync is switched on.
	 */
	public static function is_enabled(): bool {
		return self::is_configured() && ! in_array( self::get( 'discord_sync_enabled' ), array( '', '0' ), true );
	}

	private static function get( string $key ): string {
		$options = get_option( 'initlab_options', array() );

		return isset( $options[ $key ] ) && is_scalar( $options[ $key ] ) ? trim( (string) $options[ $key ] ) : '';
	}
}
