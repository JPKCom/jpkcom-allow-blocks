<?php
/**
 * Fixture for the kill-switch guards in test-abilities.php.
 *
 * A constant cannot be undefined once set, so each spelling has to be exercised
 * in its own process. This file is that process: it stubs everything
 * jpkcom_allow_blocks_abilities_enabled() consults, optionally defines ONE
 * kill-switch constant as false, and prints the boolean the function returns.
 *
 * The three wp_* / filter stubs matter. Without them the gate returns false for
 * a completely unrelated reason and a kill-switch test would pass while proving
 * nothing, so the no-constant baseline must print `true`.
 *
 * Not named test-*.php on purpose: CI runs tests/test-*.php and this is not a
 * suite, it is the subject of one.
 *
 * @package   JPKCom_Allow_Blocks
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	return $value;
}

function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted = 1 ): bool {
	return true;
}

function wp_register_ability( mixed ...$args ): bool {
	return true;
}

function wp_register_ability_category( mixed ...$args ): bool {
	return true;
}

function jpkcom_allow_blocks_filter_allowed( mixed ...$args ): mixed {
	return null;
}

$constant = $argv[1] ?? '';

if ( $constant !== '' ) {
	define( $constant, false );
}

require_once dirname( __DIR__ ) . '/includes/abilities.php';

echo jpkcom_allow_blocks_abilities_enabled() ? 'true' : 'false';
