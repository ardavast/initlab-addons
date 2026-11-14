<?php
/**
 * Keeps the Discord server's scheduled events in sync with the Events Manager events.
 *
 * - Hooks decides when a sync runs, and holds the edit screen box and the WP-CLI command.
 * - Synchronizer works out what Discord should show for an event and makes it so.
 * - Event_Data turns an event into Discord's fields and remembers what was sent.
 * - Client is the Discord API, Settings is the configuration.
 */

namespace InitLab\Addons\Discord;

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/class-settings.php';
require __DIR__ . '/class-client.php';
require __DIR__ . '/class-event-data.php';
require __DIR__ . '/class-synchronizer.php';
require __DIR__ . '/class-hooks.php';

/**
 * The one set of hooks of this request.
 */
function hooks(): Hooks {
	static $hooks = null;
	if ( null === $hooks ) {
		$hooks = new Hooks( new Synchronizer( new Client() ) );
	}

	return $hooks;
}

hooks()->register( dirname( __DIR__, 2 ) . '/initlab-addons.php' );
