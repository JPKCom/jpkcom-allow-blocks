<?php
/**
 * WordPress Abilities API integration.
 *
 * Registers one read-only ability: which blocks the calling user may actually
 * insert, and how the restriction is configured per role.
 *
 * The effective answer is produced BY the filter this plugin registers, not by
 * a re-derivation of it. Two rules make a re-derivation get it wrong:
 *
 * - A user's blocked set is the INTERSECTION across their roles, not the union.
 *   A user holding two roles is blocked only from what both roles block, and a
 *   single role with an empty list lifts the restriction entirely.
 * - `manage_options` exempts a user completely, and that exemption is itself
 *   filterable, so a site can move it.
 *
 * @package   JPKCom_Allow_Blocks
 * @author    Jean Pierre Kolb <jpk@jpkc.com>
 * @license   GPL-2.0-or-later
 * @link      https://github.com/JPKCom/jpkcom-allow-blocks
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {

	exit;

}


if ( ! defined( constant_name: 'JPKCOM_ALLOWBLOCKS_ABILITY_CATEGORY' ) ) {

	/**
	 * Ability category.
	 *
	 * Categories are global and registration is FIRST-WINS, so this goes through
	 * wp_has_ability_category() rather than assuming.
	 *
	 * @since 3.1.0
	 */
	define( 'JPKCOM_ALLOWBLOCKS_ABILITY_CATEGORY', 'jpkcom-content' );

}

if ( ! defined( constant_name: 'JPKCOM_ALLOWBLOCKS_ABILITY_INPUT_KEYS' ) ) {

	/**
	 * Top-level input keys the ability declares.
	 *
	 * Cross-checked against the registered schema by tests/test-abilities.php.
	 *
	 * @since 3.1.0
	 */
	define(
		'JPKCOM_ALLOWBLOCKS_ABILITY_INPUT_KEYS',
		[
			'jpkcom-allow-blocks/list-allowed-blocks' => [],
		]
	);

}


if ( ! function_exists( function: 'jpkcom_allow_blocks_abilities_enabled' ) ) {

	/**
	 * Decide whether the ability should be registered at all.
	 *
	 * Both spellings of the kill switch are honoured. README.md and CLAUDE.md
	 * have always documented the separated JPKCOM_ALLOW_BLOCKS_ABILITIES, but
	 * 3.1.0 shipped a check for the run-together JPKCOM_ALLOWBLOCKS_ABILITIES,
	 * so the documented constant did nothing and only the undocumented one
	 * worked. Accepting both keeps a 3.1.0 workaround working while making the
	 * documented spelling authoritative.
	 *
	 * The plugin's constant naming is genuinely split, which is how the slip
	 * survived review: the main file uses JPKCOM_ALLOW_BLOCKS_* (VERSION, PATH,
	 * IMPORT_MAX_BYTES), while the two other constants in THIS file run the
	 * words together (JPKCOM_ALLOWBLOCKS_ABILITY_CATEGORY, _ABILITY_INPUT_KEYS).
	 * Writing the local spelling here was the natural mistake to make.
	 *
	 * @since 3.1.0
	 * @since 3.1.1 Honours the documented JPKCOM_ALLOW_BLOCKS_ABILITIES.
	 *
	 * @return bool True when registration should proceed.
	 */
	function jpkcom_allow_blocks_abilities_enabled(): bool {

		if ( defined( constant_name: 'JPKCOM_ALLOW_BLOCKS_ABILITIES' ) && ! JPKCOM_ALLOW_BLOCKS_ABILITIES ) {

			return false;

		}

		if ( defined( constant_name: 'JPKCOM_ALLOWBLOCKS_ABILITIES' ) && ! JPKCOM_ALLOWBLOCKS_ABILITIES ) {

			return false;

		}

		return function_exists( function: 'wp_register_ability' )
			&& function_exists( function: 'wp_register_ability_category' )
			&& function_exists( function: 'jpkcom_allow_blocks_filter_allowed' );

	}

}


if ( ! function_exists( function: 'jpkcom_allow_blocks_ability_log' ) ) {

	/**
	 * Write a debug line, and only with WP_DEBUG.
	 *
	 * @since 3.1.0
	 *
	 * @param string $message Message.
	 * @return void
	 */
	function jpkcom_allow_blocks_ability_log( string $message ): void {

		if ( defined( constant_name: 'WP_DEBUG' ) && WP_DEBUG ) {

			error_log( message: '[jpkcom-allow-blocks] ' . $message );

		}

	}

}


if ( ! function_exists( function: 'jpkcom_allow_blocks_ability_error' ) ) {

	/**
	 * Build a WP_Error carrying an HTTP status.
	 *
	 * Without data['status'] rest_ensure_response() defaults to 500, and a 5xx
	 * tells an agent "transient fault, retry unchanged" - the opposite of what a
	 * caller mistake needs to hear.
	 *
	 * @since 3.1.0
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error Error.
	 */
	function jpkcom_allow_blocks_ability_error( string $code, string $message, int $status = 400 ): WP_Error {

		return new WP_Error( $code, $message, [ 'status' => $status ] );

	}

}


if ( ! function_exists( function: 'jpkcom_allow_blocks_ability_boundary' ) ) {

	/**
	 * Turn a Throwable out of the callback into a WP_Error.
	 *
	 * @since 3.1.0
	 *
	 * @param callable $body    Callback.
	 * @param string   $ability Ability name.
	 * @return array<string, mixed>|WP_Error Result or error.
	 */
	function jpkcom_allow_blocks_ability_boundary( callable $body, string $ability ): array|WP_Error {

		try {

			return $body();

		} catch ( \Throwable $e ) {

			jpkcom_allow_blocks_ability_log( $ability . ' failed: ' . $e->getMessage() );

			return jpkcom_allow_blocks_ability_error(
				'jpkcom_allow_blocks_read_failed',
				__( 'The block restriction settings on this site could not be read. This is a condition on the site, not a problem with the request, so repeating the call unchanged will not help; the details are in the site error log.', 'jpkcom-allow-blocks' ),
				500
			);

		}

	}

}


if ( ! function_exists( function: 'jpkcom_allow_blocks_ability_capability' ) ) {

	/**
	 * Check the capability required to run the ability.
	 *
	 * `edit_posts`: the answer is only meaningful to someone who edits content,
	 * and it reports how the site restricts every role, which is configuration
	 * rather than visitor-facing content.
	 *
	 * @since 3.1.0
	 *
	 * @param string $ability Ability name.
	 * @return bool True when the current user may run it.
	 */
	function jpkcom_allow_blocks_ability_capability( string $ability ): bool {

		/**
		 * Filter the capability required to run the ability.
		 *
		 * @since 3.1.0
		 *
		 * @param string $capability Capability name.
		 * @param string $ability    Ability name.
		 */
		$capability = apply_filters( 'jpkcom_allow_blocks_ability_capability', 'edit_posts', $ability );

		return current_user_can( is_string( value: $capability ) ? $capability : 'edit_posts' );

	}

}


if ( ! function_exists( function: 'jpkcom_allow_blocks_ability_meta' ) ) {

	/**
	 * Build the meta array for the ability.
	 *
	 * All three annotations explicit: they default to null and the REST run
	 * controller derives the HTTP verb from them, so without readonly the run
	 * route would be POST-only.
	 *
	 * @since 3.1.0
	 *
	 * @param string $ability Ability name.
	 * @return array<string, mixed> Meta array.
	 */
	function jpkcom_allow_blocks_ability_meta( string $ability ): array {

		$meta = [
			'show_in_rest' => true,
			'public'       => true,
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
		];

		/**
		 * Filter the meta array of the ability.
		 *
		 * @since 3.1.0
		 *
		 * @param array<string, mixed> $meta    Meta array.
		 * @param string               $ability Ability name.
		 */
		$filtered = apply_filters( 'jpkcom_allow_blocks_ability_meta', $meta, $ability );

		return is_array( value: $filtered ) ? $filtered : $meta;

	}

}


if ( ! function_exists( function: 'jpkcom_allow_blocks_ability_normalise_input' ) ) {

	/**
	 * Bring the value core hands the callback into array form.
	 *
	 * normalize_input() substitutes the schema's top-level default when the input
	 * is exactly null, and that default is a stdClass - so the callback receives
	 * an object and must read it.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>|null Array form, or null when unusable.
	 */
	function jpkcom_allow_blocks_ability_normalise_input( mixed $input ): ?array {

		if ( $input === null ) {

			return [];

		}

		if ( is_object( value: $input ) ) {

			return get_object_vars( object: $input );

		}

		return is_array( value: $input ) ? $input : null;

	}

}


if ( ! function_exists( function: 'jpkcom_allow_blocks_ability_validate_input_keys' ) ) {

	/**
	 * Refuse a top-level input key the ability does not declare.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param string[]             $allowed Declared keys.
	 * @return true|WP_Error True when every key is declared.
	 */
	function jpkcom_allow_blocks_ability_validate_input_keys( array $input, array $allowed ): true|WP_Error {

		$unknown = [];

		foreach ( array_keys( $input ) as $key ) {

			if ( ! in_array( needle: (string) $key, haystack: $allowed, strict: true ) ) {

				$unknown[] = (string) $key;

			}

		}

		if ( $unknown === [] ) {

			return true;

		}

		return jpkcom_allow_blocks_ability_error(
			'jpkcom_allow_blocks_unknown_input_key',
			sprintf(
				/* translators: 1: comma-separated rejected keys, 2: comma-separated accepted keys. */
				__( 'Unknown input key: %1$s. This ability accepts: %2$s. A key it does not declare is never read, so the request would be answered as though that key had not been sent.', 'jpkcom-allow-blocks' ),
				implode( ', ', $unknown ),
				$allowed === [] ? __( 'no input at all', 'jpkcom-allow-blocks' ) : implode( ', ', $allowed )
			),
			400
		);

	}

}


if ( ! function_exists( function: 'jpkcom_allow_blocks_ability_list_inner' ) ) {

	/**
	 * Report which blocks the calling user may insert, and how roles are configured.
	 *
	 * The effective list comes from running the plugin's OWN filter with `true`
	 * as the incoming value - "everything is allowed so far" - so the answer is
	 * whatever the editor would actually be handed. It is not re-derived from the
	 * option, and that matters twice over:
	 *
	 * - The blocked set for a user is the INTERSECTION across their roles. A user
	 *   with two roles is blocked only from what both block, and one role with an
	 *   empty list lifts the restriction entirely. A union would be the opposite
	 *   answer.
	 * - The exemption is `manage_options` and is itself filterable, so a site can
	 *   move it somewhere this ability has no way to predict.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>|WP_Error Result.
	 */
	function jpkcom_allow_blocks_ability_list_inner( mixed $input = null ): array|WP_Error {

		$normalised = jpkcom_allow_blocks_ability_normalise_input( $input );

		if ( $normalised === null ) {

			return jpkcom_allow_blocks_ability_error(
				'jpkcom_allow_blocks_invalid_input',
				__( 'This ability takes no parameters. Call it with no input at all.', 'jpkcom-allow-blocks' )
			);

		}

		$keys_valid = jpkcom_allow_blocks_ability_validate_input_keys(
			$normalised,
			JPKCOM_ALLOWBLOCKS_ABILITY_INPUT_KEYS['jpkcom-allow-blocks/list-allowed-blocks']
		);

		if ( $keys_valid instanceof WP_Error ) {

			return $keys_valid;

		}

		$settings = jpkcom_allow_blocks_get_settings();
		$all      = jpkcom_allow_blocks_all_block_names( $settings );
		$exempt   = jpkcom_allow_blocks_is_exempt();
		$roles    = jpkcom_allow_blocks_current_role_slugs();

		// The plugin's own filter, invoked exactly as WordPress invokes it. `true`
		// is what core passes when nothing has restricted the editor yet, and the
		// second argument is the block editor context, which this filter does not
		// read but which the signature requires.
		$effective = jpkcom_allow_blocks_filter_allowed( true, null );

		$allowed_for_you = is_array( value: $effective ) ? array_values( $effective ) : $all;
		$blocked_for_you = array_values( array_diff( $all, $allowed_for_you ) );

		// Configuration per role, straight from the store. Reported separately from
		// the effective answer above and labelled as configuration, because the two
		// are different questions: what is configured for a role, and what a
		// particular user ends up with.
		$by_role = [];

		foreach ( (array) ( $settings['roles'] ?? [] ) as $slug => $blocked ) {

			if ( ! is_string( value: $slug ) || ! is_array( value: $blocked ) ) {

				continue;

			}

			$blocked = array_values( array_filter( $blocked, 'is_string' ) );

			$by_role[] = [
				'role'          => $slug,
				'blocked'       => $blocked,
				'blocked_count' => count( $blocked ),
				'allowed_count' => max( 0, count( $all ) - count( array_intersect( $all, $blocked ) ) ),
			];

		}

		return [
			'you_are_exempt'    => $exempt,
			'your_roles'        => $roles,
			'restricted'        => ! $exempt && $blocked_for_you !== [],
			'blocks_total'      => count( $all ),
			'allowed_for_you'   => $allowed_for_you,
			'blocked_for_you'   => $blocked_for_you,
			'by_role'           => $by_role,
			'language'          => determine_locale(),
		];

	}

}


if ( ! function_exists( function: 'jpkcom_allow_blocks_ability_permission' ) ) {

	/**
	 * Permission callback.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $input Validated input, unused.
	 * @return bool True when the current user may run the ability.
	 */
	function jpkcom_allow_blocks_ability_permission( mixed $input = null ): bool {

		return jpkcom_allow_blocks_ability_capability( 'jpkcom-allow-blocks/list-allowed-blocks' );

	}

}


if ( ! function_exists( function: 'jpkcom_allow_blocks_ability_list' ) ) {

	/**
	 * Execute callback.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>|WP_Error Result.
	 */
	function jpkcom_allow_blocks_ability_list( mixed $input = null ): array|WP_Error {

		return jpkcom_allow_blocks_ability_boundary(
			static fn(): array|WP_Error => jpkcom_allow_blocks_ability_list_inner( $input ),
			'jpkcom-allow-blocks/list-allowed-blocks'
		);

	}

}


if ( ! function_exists( function: 'jpkcom_allow_blocks_get_ability_definitions' ) ) {

	/**
	 * Build the registration arguments.
	 *
	 * Reads no WordPress state and touches no registry, which is what lets the CI
	 * harness assert the shape without a WordPress installation.
	 *
	 * @since 3.1.0
	 *
	 * @return array<string, array<string, mixed>> Ability name => registration args.
	 */
	function jpkcom_allow_blocks_get_ability_definitions(): array {

		return [

			'jpkcom-allow-blocks/list-allowed-blocks' => [
				'label'       => __( 'List the blocks you may insert', 'jpkcom-allow-blocks' ),
				'description' => __( 'Returns which blocks the calling user may actually insert in the block editor on this site, and how the restriction is configured for each role. The answer is produced by the same filter the editor is handed, so it cannot disagree with what the editor will accept. Note that a user\'s blocked set is the INTERSECTION across their roles - someone holding two roles is blocked only from what both roles block, and a single role with an empty list lifts the restriction entirely.', 'jpkcom-allow-blocks' ),
				'category'    => JPKCOM_ALLOWBLOCKS_ABILITY_CATEGORY,

				'input_schema' => [
					'type'    => 'object',
					// Top level, deliberately: normalize_input() substitutes this value
					// when the input is exactly null and nothing else does. An object
					// rather than [], because the MCP adapter publishes the schema
					// verbatim and an array violates type: object.
					'default' => (object) array(),
					// NO `properties` key, and additionalProperties => false. An empty
					// stdClass here is an anonymous fatal - core does an array offset on
					// it - and an empty array is invalid JSON Schema. Omitting the key is
					// the only combination that yields a clean WP_Error.
					'additionalProperties' => false,
				],

				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'you_are_exempt'  => [ 'type' => 'boolean', 'description' => __( 'True when the calling user is exempt from the restriction entirely. The default exemption is the manage_options capability, and a site can move it with a filter, so do not infer this from the role list.', 'jpkcom-allow-blocks' ) ],
						'your_roles'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => __( 'Role slugs of the calling user, empty when there is no user.', 'jpkcom-allow-blocks' ) ],
						'restricted'      => [ 'type' => 'boolean', 'description' => __( 'True when the calling user is actually restricted right now. False either because they are exempt or because nothing is blocked for their role combination.', 'jpkcom-allow-blocks' ) ],
						'blocks_total'    => [ 'type' => 'integer', 'description' => __( 'How many block types this site knows altogether, so the allowed list can be read as a proportion.', 'jpkcom-allow-blocks' ) ],
						'allowed_for_you' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => __( 'The block names the calling user may insert. This is what the editor is handed, not a re-derivation of the settings.', 'jpkcom-allow-blocks' ) ],
						'blocked_for_you' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => __( 'The block names the calling user may not insert. Inserting one of these produces content the editor will refuse.', 'jpkcom-allow-blocks' ) ],
						'by_role'         => [
							'type'        => 'array',
							'description' => __( 'How the restriction is CONFIGURED per role. This is the stored configuration, not an evaluated result for any user: a user holding several roles gets the intersection, and an exempt user gets everything regardless of what is listed here.', 'jpkcom-allow-blocks' ),
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'role'          => [ 'type' => 'string', 'description' => __( 'Role slug.', 'jpkcom-allow-blocks' ) ],
									'blocked'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => __( 'Block names blocked for this role.', 'jpkcom-allow-blocks' ) ],
									'blocked_count' => [ 'type' => 'integer', 'description' => __( 'How many entries the blocked list holds, including any that no longer exist on this site.', 'jpkcom-allow-blocks' ) ],
									'allowed_count' => [ 'type' => 'integer', 'description' => __( 'How many of the block types this site knows would remain for a user holding only this role.', 'jpkcom-allow-blocks' ) ],
								],
							],
						],
						'language'        => [ 'type' => 'string', 'description' => __( 'Locale this answer was read in.', 'jpkcom-allow-blocks' ) ],
					],
				],

				'execute_callback'    => 'jpkcom_allow_blocks_ability_list',
				'permission_callback' => 'jpkcom_allow_blocks_ability_permission',
				'meta'                => jpkcom_allow_blocks_ability_meta( 'jpkcom-allow-blocks/list-allowed-blocks' ),
			],

		];

	}

}


if ( ! function_exists( function: 'jpkcom_allow_blocks_register_ability_category' ) ) {

	/**
	 * Register the shared category, unless a sibling plugin already did.
	 *
	 * @since 3.1.0
	 *
	 * @return void
	 */
	function jpkcom_allow_blocks_register_ability_category(): void {

		if ( ! jpkcom_allow_blocks_abilities_enabled() ) {

			return;

		}

		if ( function_exists( function: 'wp_has_ability_category' )
			&& wp_has_ability_category( JPKCOM_ALLOWBLOCKS_ABILITY_CATEGORY ) ) {

			return;

		}

		$category = wp_register_ability_category(
			JPKCOM_ALLOWBLOCKS_ABILITY_CATEGORY,
			[
				'label'       => __( 'JPKCom Content', 'jpkcom-allow-blocks' ),
				'description' => __( 'Read-only access to content managed by the JPKCom content plugins.', 'jpkcom-allow-blocks' ),
			]
		);

		if ( $category === null ) {

			jpkcom_allow_blocks_ability_log( 'ability category registration returned null' );

		}

	}

}


if ( ! function_exists( function: 'jpkcom_allow_blocks_register_abilities' ) ) {

	/**
	 * Register the ability.
	 *
	 * wp_register_ability() returns null on EVERY failure path and reports only
	 * through _doing_it_wrong(), which is silent in production - and so is the
	 * debug log without WP_DEBUG.
	 *
	 * @since 3.1.0
	 *
	 * @return void
	 */
	function jpkcom_allow_blocks_register_abilities(): void {

		if ( ! jpkcom_allow_blocks_abilities_enabled() ) {

			return;

		}

		foreach ( jpkcom_allow_blocks_get_ability_definitions() as $name => $args ) {

			if ( wp_register_ability( $name, $args ) === null ) {

				jpkcom_allow_blocks_ability_log( 'registration returned null for ' . $name );

			}

		}

	}

}


add_action( 'wp_abilities_api_categories_init', 'jpkcom_allow_blocks_register_ability_category' );
add_action( 'wp_abilities_api_init', 'jpkcom_allow_blocks_register_abilities' );
