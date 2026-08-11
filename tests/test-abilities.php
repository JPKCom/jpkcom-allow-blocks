<?php
/**
 * Guards for the Abilities API registration.
 *
 * The load-bearing check is the last group: the effective answer must come from
 * the plugin's OWN filter, not from a re-derivation of the settings. Two rules
 * make a re-derivation wrong, and both are asserted here.
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

$root = dirname( __DIR__ );

require_once $root . '/includes/abilities.php';

$pass = 0;
$fail = 0;

function section( string $title ): void {
	echo "\n" . $title . "\n";
}

function chk( string $label, bool $ok, string $why = '' ): void {
	global $pass, $fail;

	if ( $ok ) {
		$pass++;
		echo "  PASS  {$label}\n";
		return;
	}

	$fail++;
	echo "  FAIL  {$label}\n";

	if ( $why !== '' ) {
		echo "        why:  {$why}\n";
	}
}

function body_of( string $source, string $needle ): ?string {
	$start = strpos( $source, $needle );

	if ( $start === false ) {
		return null;
	}

	$open  = strpos( $source, '{', $start );
	$depth = 0;

	for ( $i = $open, $len = strlen( $source ); $i < $len; $i++ ) {
		if ( $source[ $i ] === '{' ) {
			$depth++;
		} elseif ( $source[ $i ] === '}' ) {
			$depth--;
			if ( $depth === 0 ) {
				return substr( $source, $open, $i - $open );
			}
		}
	}

	return null;
}

$defs = jpkcom_allow_blocks_get_ability_definitions();
$name = 'jpkcom-allow-blocks/list-allowed-blocks';
$src  = (string) file_get_contents( $root . '/includes/abilities.php' );

// --- Registration shape -----------------------------------------------------

section( 'Registration shape' );

chk( 'exactly one ability is defined', count( $defs ) === 1, 'Got ' . count( $defs ) . '.' );

chk(
	'it is the documented name',
	array_key_first( $defs ) === $name,
	'Ability names are a public contract; renaming one breaks every caller that stored it.'
);

$args = $defs[ $name ] ?? [];
$ann  = $args['meta']['annotations'] ?? [];

chk(
	'all three annotations set explicitly',
	( $ann['readonly'] ?? null ) === true && ( $ann['destructive'] ?? null ) === false && ( $ann['idempotent'] ?? null ) === true,
	'They default to null, and the REST run controller derives the HTTP verb from them: without readonly the run route is POST-only.'
);

chk(
	'registered into the shared content category',
	( $args['category'] ?? null ) === 'jpkcom-content',
	'Categories are global and first-wins; the JPKCom content plugins share one.'
);

// --- Input schema -----------------------------------------------------------

section( 'Input schema' );

$schema = $args['input_schema'] ?? [];

chk(
	'carries a top-level default',
	array_key_exists( 'default', $schema ),
	'normalize_input() substitutes the TOP-LEVEL default when the input is exactly null, and nothing else does. Without it the most obvious call - no parameters at all - fails validation before the callback runs.'
);

chk(
	'the default encodes as {} and not []',
	json_encode( $schema['default'] ?? null ) === '{}',
	'The schema declares type: object. PHP serialises an empty array as a JSON array, and the MCP adapter publishes get_input_schema() verbatim.'
);

chk(
	'declares NO properties key at all',
	! array_key_exists( 'properties', $schema ),
	'An empty stdClass here is an anonymous fatal - core does an array offset on it - and an empty array is invalid JSON Schema.'
);

chk(
	'refuses unknown keys through the schema',
	( $schema['additionalProperties'] ?? null ) === false,
	'It declares no properties, so this is the only thing that can refuse a key there.'
);

$guarded = JPKCOM_ALLOWBLOCKS_ABILITY_INPUT_KEYS[ $name ] ?? null;

chk(
	'guarded input keys match the schema properties',
	$guarded === array_keys( (array) ( $schema['properties'] ?? [] ) ),
	'Two statements of one list. Got ' . var_export( $guarded, true ) . '.'
);

// --- Structural guards ------------------------------------------------------

section( 'Structural guards' );

chk(
	'the callback calls the unknown-key guard',
	str_contains( (string) body_of( $src, 'function jpkcom_allow_blocks_ability_list_inner(' ), 'jpkcom_allow_blocks_ability_validate_input_keys(' ),
	'A key the ability does not declare is never read, so without this the request is answered as though it had not been sent.'
);

chk(
	'the callback runs inside the Throwable boundary',
	str_contains( (string) body_of( $src, 'function jpkcom_allow_blocks_ability_list(' ), 'jpkcom_allow_blocks_ability_boundary(' ),
	'A Throwable escaping an ability callback is an error the client cannot act on.'
);

chk(
	'the permission callback resolves to a capability check',
	str_contains( (string) body_of( $src, 'function jpkcom_allow_blocks_ability_permission(' ), 'jpkcom_allow_blocks_ability_capability(' ),
	'The argument an ability permission callback receives is the validated input value, never a request object.'
);

chk(
	'the default capability is edit_posts, not read',
	str_contains( (string) body_of( $src, 'function jpkcom_allow_blocks_ability_capability(' ), "'edit_posts'" ),
	'The answer is only meaningful to someone who edits content, and it reports how every role is restricted.'
);

// --- The answer comes from the filter, not from a copy of it ----------------

section( 'The effective answer is produced by the plugin\'s own filter' );

$inner = (string) body_of( $src, 'function jpkcom_allow_blocks_ability_list_inner(' );

chk(
	'the effective list comes from jpkcom_allow_blocks_filter_allowed()',
	str_contains( $inner, 'jpkcom_allow_blocks_filter_allowed( true, null )' ),
	'This is the function registered on allowed_block_types_all, so its result is literally what the editor is handed. Re-deriving it from the option would produce a second rule that drifts the moment either side is touched.'
);

chk(
	'the exemption is read from the plugin, not re-derived',
	str_contains( $inner, 'jpkcom_allow_blocks_is_exempt()' ) && ! str_contains( $inner, "current_user_can( 'manage_options' )" ),
	'The exemption defaults to manage_options but is itself filterable, so a site can move it. Checking the capability directly here would report the wrong answer on such a site.'
);

chk(
	'the roles come from the plugin\'s own resolver',
	str_contains( $inner, 'jpkcom_allow_blocks_current_role_slugs()' ),
	'It handles the logged-out case and a malformed roles property; re-reading wp_get_current_user() here would duplicate both.'
);

chk(
	'the blocked list is a difference against the effective list, not a second lookup',
	str_contains( $inner, 'array_diff( $all, $allowed_for_you )' ),
	'Deriving it from the settings instead would restate the intersection rule, which is exactly the restatement this design avoids: the blocked set for a user is the INTERSECTION across their roles, so a union would report blocks the user may actually insert.'
);

chk(
	'the per-role list is labelled as configuration, not as an evaluated result',
	str_contains( $args['output_schema']['properties']['by_role']['description'] ?? '', 'CONFIGURED' )
		&& str_contains( $args['output_schema']['properties']['by_role']['description'] ?? '', 'intersection' ),
	'A caller reading by_role as "what this user gets" would be wrong twice over: a multi-role user gets the intersection, and an exempt user gets everything regardless.'
);

chk(
	'the description states the intersection rule',
	str_contains( strtolower( $args['description'] ?? '' ), 'intersection' ),
	'It is the one rule an agent cannot guess from the field names, and getting it backwards means telling someone a block is forbidden when it is not.'
);

section( 'Kill switch' );

/**
 * Run the kill-switch fixture in its own process and return what it printed.
 *
 * Asserting on the SOURCE TEXT here would be worthless: the bug being guarded
 * against was a documented constant that was never read, and a substring check
 * cannot tell a live `defined()` call from one inside a comment. So the gate is
 * actually called, in a fresh process per spelling, because a constant cannot
 * be undefined once set.
 */
function kill_switch_gate( string $constant = '' ): string {
	$command = escapeshellarg( PHP_BINARY )
		. ' ' . escapeshellarg( __DIR__ . '/fixture-kill-switch.php' )
		. ( $constant === '' ? '' : ' ' . escapeshellarg( $constant ) );

	return trim( (string) shell_exec( $command . ' 2>&1' ) );
}

chk(
	'the fixture can be run at all',
	function_exists( 'shell_exec' ) && is_readable( __DIR__ . '/fixture-kill-switch.php' ),
	'Without this the three checks below fail with a message about the kill switch, which would be the wrong diagnosis: shell_exec() disabled or a missing tests/fixture-kill-switch.php reddens them no matter what the gate does.'
);

chk(
	'baseline: the gate is open when no kill switch is defined',
	kill_switch_gate() === 'true',
	'If the baseline were already false the two checks below would pass without proving anything, because every answer would be false.'
);

chk(
	'the documented constant actually closes the gate',
	kill_switch_gate( 'JPKCOM_ALLOW_BLOCKS_ABILITIES' ) === 'false',
	'3.1.0 read only the run-together spelling while README.md and CLAUDE.md documented the separated one, so following the documentation silently did nothing and the ability stayed registered.'
);

chk(
	'the 3.1.0 spelling still closes the gate',
	kill_switch_gate( 'JPKCOM_ALLOWBLOCKS_ABILITIES' ) === 'false',
	'It was the only spelling that worked in 3.1.0, so a site that found the discrepancy and worked around it must not break on update.'
);

printf( "\n  %d passed, %d failed\n", $pass, $fail );

exit( $fail > 0 ? 1 : 0 );
