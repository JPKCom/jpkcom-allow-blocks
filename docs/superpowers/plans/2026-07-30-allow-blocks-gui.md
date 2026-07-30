# Per-role block permissions — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the plugin's inert hard-coded allow-list with a settings screen under Appearance that enables or disables each registered Gutenberg block per user role, with export and import.

**Architecture:** Settings store what is **blocked** per role, never what is allowed. At runtime `allowed_block_types_all` subtracts the blocked names from the incoming value; with nothing blocked the filter returns its input untouched and the plugin is inert. Storing the deny list is what makes new blocks default to allowed and makes settings survive a temporarily deactivated plugin with no pruning logic. Five include files, one responsibility each; `settings-store.php` is the only module that touches the option.

**Tech Stack:** PHP 8.3, WordPress 6.9+, no build step, no Composer dependencies. Vanilla JavaScript for the table filters. Tests are standalone PHP with stubbed WordPress functions, run by CI with plain `php`.

**Spec:** `docs/superpowers/specs/2026-07-30-allow-blocks-gui-design.md`

## Global Constraints

- Target version **3.0.0**. Bump five places: header `Version:`, header `Stable tag:`, `JPKCOM_ALLOW_BLOCKS_VERSION`, `phpdoc.xml` `<version number="…">`, `README.md` `**Version:**` and `**Stable tag:**`.
- `Requires at least: 6.9`, `Requires PHP: 8.3`, `Tested up to: 7.1` — unchanged.
- Every PHP file: `declare(strict_types=1);` and a direct-access guard. Main file uses `if ( ! defined( constant_name: 'WPINC' ) ) { die; }`; include files use `if ( ! defined( constant_name: 'ABSPATH' ) ) { exit; }`.
- Every function **in the plugin's own code** is `jpkcom_allow_blocks_`-prefixed and wrapped in `if ( ! function_exists( function: '…' ) )`. No unprefixed global names. Test files are the exception: they follow the fleet's existing harness convention and declare a bare `jpkcom_check()` helper, matching every other `tests/test-*.php` in the JPKCom plugins.
- **Repository language is English** — comments, variable names, commit messages, docs. German only inside translation catalogues.
- Commits carry **no** `Co-Authored-By` trailer.
- CI runs on every pull request and push to `main`: `php -l`, a named-argument guard, YAML validation, action SHA pinning, and `tests/test-*.php`. The named-argument guard rejects named arguments that do not match an internal function's real parameter names — only use ones you have verified (`constant_name:`, `path:`, `haystack:`, `needle:`, `string:`, `flags:`, `encoding:`, `function:`, `class:`).
- Option name: `jpkcom_allow_blocks_settings`, stored with autoload `false`.
- Release is triggered by pushing a `v*` tag; the workflow creates the GitHub release itself.

---

### Task 1: Settings store

The single source of truth for the option. Every other module reads and writes through here, so validation cannot be bypassed by a later caller.

**Files:**
- Create: `includes/settings-store.php`
- Create: `tests/test-settings-store.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `jpkcom_allow_blocks_option_name(): string`
  - `jpkcom_allow_blocks_is_valid_block_name( string $name ): bool`
  - `jpkcom_allow_blocks_empty_settings(): array` — `[ 'schema' => 1, 'updated' => '', 'roles' => [], 'labels' => [] ]`
  - `jpkcom_allow_blocks_sanitize_settings( mixed $raw ): array` — always returns a valid structure
  - `jpkcom_allow_blocks_get_settings(): array`
  - `jpkcom_allow_blocks_save_settings( array $settings ): bool`
  - `jpkcom_allow_blocks_blocked_for_roles( array $role_slugs, ?array $settings = null ): array` — intersection across roles

- [ ] **Step 1: Write the failing test**

Create `tests/test-settings-store.php`:

```php
<?php
/**
 * Regression tests for the settings store of jpkcom-allow-blocks.
 *
 * Runs standalone (no WordPress): the functions the module touches are stubbed,
 * the module is required, and its functions are called directly.
 *
 * @package JPKCom_Allow_Blocks
 * @since 3.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    define( constant_name: 'ABSPATH', value: __DIR__ . '/' );
}

/** Option store used by the stubs. */
$GLOBALS['jpkcom_options'] = array();

if ( ! function_exists( function: 'get_option' ) ) {
    function get_option( string $option, mixed $default_value = false ): mixed {
        return $GLOBALS['jpkcom_options'][ $option ] ?? $default_value;
    }
}

if ( ! function_exists( function: 'update_option' ) ) {
    function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
        $GLOBALS['jpkcom_options'][ $option ] = $value;
        return true;
    }
}

if ( ! function_exists( function: 'sanitize_key' ) ) {
    function sanitize_key( string $key ): string {
        return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) );
    }
}

if ( ! function_exists( function: 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $str ): string {
        return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', strip_tags( $str ) ) );
    }
}

if ( ! function_exists( function: 'current_time' ) ) {
    function current_time( string $type, int|bool $gmt = 0 ): string {
        return '2026-07-30T12:00:00+00:00';
    }
}

require_once dirname( path: __DIR__ ) . '/includes/settings-store.php';

$failed = 0;
$passed = 0;

/**
 * Assert a condition and report it.
 *
 * @param string $name Case name.
 * @param bool   $ok   Whether the case passed.
 * @param string $note Extra detail printed on failure.
 * @return void
 */
function jpkcom_check( string $name, bool $ok, string $note = '' ): void {
    global $failed, $passed;

    if ( $ok ) {
        ++$passed;
        printf( "  ok   %s\n", $name );
        return;
    }

    ++$failed;
    printf( "  FAIL %s%s\n", $name, $note !== '' ? ' -- ' . $note : '' );
}

echo "jpkcom-allow-blocks: settings store\n";

/* Block name grammar. */
foreach ( array( 'core/paragraph', 'areoi/accordion-item', 'my-plugin/block-1' ) as $name ) {
    jpkcom_check( sprintf( '%s is a valid block name', $name ), jpkcom_allow_blocks_is_valid_block_name( $name ) );
}

foreach ( array( 'core', 'Core/Paragraph', 'core/', '/paragraph', 'core/para graph', '', 'core/para/graph' ) as $name ) {
    jpkcom_check( sprintf( '%s is rejected', var_export( $name, true ) ), ! jpkcom_allow_blocks_is_valid_block_name( $name ) );
}

/* Sanitising never throws and always returns the full structure. */
foreach ( array( 'garbage', 42, null, array(), array( 'roles' => 'nope' ) ) as $raw ) {
    $out = jpkcom_allow_blocks_sanitize_settings( $raw );
    jpkcom_check(
        sprintf( 'sanitize keeps the structure for %s', gettype( $raw ) ),
        isset( $out['schema'], $out['roles'], $out['labels'] ) && is_array( $out['roles'] ) && is_array( $out['labels'] )
    );
}

/* Invalid entries are dropped, valid ones survive. */
$out = jpkcom_allow_blocks_sanitize_settings(
    array(
        'schema' => 1,
        'roles'  => array(
            'editor'   => array( 'core/code', 'NOT A BLOCK', 'core/html' ),
            'Bad Role' => array( 'core/code' ),
            'author'   => 'not-an-array',
        ),
        'labels' => array( 'core/code' => '<b>Code</b>', 'bad name' => 'x' ),
    )
);

jpkcom_check( 'valid role kept', isset( $out['roles']['editor'] ) );
jpkcom_check( 'invalid block name dropped', $out['roles']['editor'] === array( 'core/code', 'core/html' ), json_encode( $out['roles']['editor'] ?? null ) );
jpkcom_check( 'role slug is sanitised', ! isset( $out['roles']['Bad Role'] ) );
jpkcom_check( 'non-array role value dropped', ! isset( $out['roles']['author'] ) );
jpkcom_check( 'label is stripped of markup', ( $out['labels']['core/code'] ?? '' ) === 'Code' );
jpkcom_check( 'label for invalid block name dropped', ! isset( $out['labels']['bad name'] ) );

/* Round trip through the option. */
jpkcom_check( 'unset option reads as empty', jpkcom_allow_blocks_get_settings()['roles'] === array() );

jpkcom_allow_blocks_save_settings(
    array( 'roles' => array( 'editor' => array( 'core/code' ) ), 'labels' => array( 'core/code' => 'Code' ) )
);

$stored = jpkcom_allow_blocks_get_settings();
jpkcom_check( 'save then read returns the same names', $stored['roles']['editor'] === array( 'core/code' ) );
jpkcom_check( 'save stamps updated', $stored['updated'] !== '' );

/* A corrupt option must not be fatal. */
$GLOBALS['jpkcom_options'][ jpkcom_allow_blocks_option_name() ] = 'corrupt';
jpkcom_check( 'corrupt option reads as empty', jpkcom_allow_blocks_get_settings()['roles'] === array() );

/* Intersection across roles: blocked only when every role blocks it. */
$settings = jpkcom_allow_blocks_sanitize_settings(
    array(
        'roles' => array(
            'editor'      => array( 'core/code', 'core/html' ),
            'contributor' => array( 'core/code', 'core/table' ),
            'shop'        => array(),
        ),
    )
);

jpkcom_check( 'single role returns its own list', jpkcom_allow_blocks_blocked_for_roles( array( 'editor' ), $settings ) === array( 'core/code', 'core/html' ) );
jpkcom_check( 'two roles intersect', jpkcom_allow_blocks_blocked_for_roles( array( 'editor', 'contributor' ), $settings ) === array( 'core/code' ) );
jpkcom_check( 'a role blocking nothing empties the intersection', jpkcom_allow_blocks_blocked_for_roles( array( 'editor', 'shop' ), $settings ) === array() );
jpkcom_check( 'an unknown role blocks nothing', jpkcom_allow_blocks_blocked_for_roles( array( 'nope' ), $settings ) === array() );
jpkcom_check( 'no roles blocks nothing', jpkcom_allow_blocks_blocked_for_roles( array(), $settings ) === array() );

printf( "\n  %d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/jpk/wp/jpkcom-allow-blocks && php tests/test-settings-store.php`
Expected: FAIL — `Failed opening required '.../includes/settings-store.php'`

- [ ] **Step 3: Write the implementation**

Create `includes/settings-store.php`:

```php
<?php
/**
 * Settings store
 *
 * Reads, writes and validates the single option this plugin owns. Every other
 * module goes through here, so validation cannot be bypassed by adding a caller.
 *
 * @package JPKCom_Allow_Blocks
 * @since   3.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( function: 'jpkcom_allow_blocks_option_name' ) ) {

    /**
     * Name of the option holding all settings.
     *
     * @since 3.0.0
     *
     * @return string Option name.
     */
    function jpkcom_allow_blocks_option_name(): string {
        return 'jpkcom_allow_blocks_settings';
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_is_valid_block_name' ) ) {

    /**
     * Whether a string is a syntactically valid block name.
     *
     * Follows the WordPress block name grammar: a lowercase namespace and name
     * separated by exactly one slash.
     *
     * @since 3.0.0
     *
     * @param string $name Candidate block name.
     * @return bool True when the name may be stored.
     */
    function jpkcom_allow_blocks_is_valid_block_name( string $name ): bool {
        return 1 === preg_match( '#^[a-z0-9][a-z0-9-]*/[a-z0-9][a-z0-9-]*$#', $name );
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_empty_settings' ) ) {

    /**
     * The empty settings structure.
     *
     * @since 3.0.0
     *
     * @return array{schema:int,updated:string,roles:array<string,string[]>,labels:array<string,string>}
     */
    function jpkcom_allow_blocks_empty_settings(): array {
        return array(
            'schema'  => 1,
            'updated' => '',
            'roles'   => array(),
            'labels'  => array(),
        );
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_sanitize_settings' ) ) {

    /**
     * Coerce any input into a valid settings structure.
     *
     * Entries that fail validation are dropped rather than stored. Never throws,
     * so a corrupt option can only ever mean "nothing is blocked".
     *
     * @since 3.0.0
     *
     * @param mixed $raw Value from the database, an import file or a form.
     * @return array The validated structure.
     */
    function jpkcom_allow_blocks_sanitize_settings( mixed $raw ): array {
        $clean = jpkcom_allow_blocks_empty_settings();

        if ( ! is_array( $raw ) ) {
            return $clean;
        }

        if ( isset( $raw['updated'] ) && is_string( $raw['updated'] ) ) {
            $clean['updated'] = sanitize_text_field( $raw['updated'] );
        }

        if ( isset( $raw['roles'] ) && is_array( $raw['roles'] ) ) {
            foreach ( $raw['roles'] as $role => $blocked ) {
                if ( ! is_string( $role ) || ! is_array( $blocked ) ) {
                    continue;
                }

                $slug = sanitize_key( $role );

                if ( '' === $slug || $slug !== $role ) {
                    continue;
                }

                $names = array();

                foreach ( $blocked as $name ) {
                    if ( is_string( $name ) && jpkcom_allow_blocks_is_valid_block_name( $name ) ) {
                        $names[] = $name;
                    }
                }

                $clean['roles'][ $slug ] = array_values( array_unique( $names ) );
            }
        }

        if ( isset( $raw['labels'] ) && is_array( $raw['labels'] ) ) {
            foreach ( $raw['labels'] as $name => $label ) {
                if ( ! is_string( $name ) || ! is_string( $label ) || ! jpkcom_allow_blocks_is_valid_block_name( $name ) ) {
                    continue;
                }

                $clean['labels'][ $name ] = mb_substr( sanitize_text_field( $label ), 0, 120 );
            }
        }

        return $clean;
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_get_settings' ) ) {

    /**
     * Read the validated settings.
     *
     * @since 3.0.0
     *
     * @return array The validated structure.
     */
    function jpkcom_allow_blocks_get_settings(): array {
        return jpkcom_allow_blocks_sanitize_settings( get_option( jpkcom_allow_blocks_option_name(), array() ) );
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_save_settings' ) ) {

    /**
     * Validate and store the settings.
     *
     * Autoload is off: the option is only read in the admin area, so the front
     * end should not carry it on every request.
     *
     * @since 3.0.0
     *
     * @param array $settings Structure to store.
     * @return bool True when the option was written.
     */
    function jpkcom_allow_blocks_save_settings( array $settings ): bool {
        $clean            = jpkcom_allow_blocks_sanitize_settings( $settings );
        $clean['updated'] = current_time( 'c' );

        return (bool) update_option( jpkcom_allow_blocks_option_name(), $clean, false );
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_blocked_for_roles' ) ) {

    /**
     * Block names blocked for every one of the given roles.
     *
     * The intersection, not the union: a block is blocked only when all of the
     * user's roles block it. This mirrors WordPress capability semantics, where
     * holding more roles never means holding fewer rights. A role with no entry
     * blocks nothing, so it empties the intersection.
     *
     * @since 3.0.0
     *
     * @param string[]   $role_slugs Roles of the user.
     * @param array|null $settings   Settings to use, or null to read them.
     * @return string[] Blocked block names, re-indexed.
     */
    function jpkcom_allow_blocks_blocked_for_roles( array $role_slugs, ?array $settings = null ): array {
        if ( array() === $role_slugs ) {
            return array();
        }

        $settings = null === $settings ? jpkcom_allow_blocks_get_settings() : jpkcom_allow_blocks_sanitize_settings( $settings );
        $blocked  = null;

        foreach ( $role_slugs as $slug ) {
            $for_role = $settings['roles'][ $slug ] ?? array();

            if ( array() === $for_role ) {
                return array();
            }

            $blocked = null === $blocked ? $for_role : array_intersect( $blocked, $for_role );

            if ( array() === $blocked ) {
                return array();
            }
        }

        return array_values( $blocked ?? array() );
    }

}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-settings-store.php`
Expected: PASS, exit 0

- [ ] **Step 5: Commit**

```bash
git add includes/settings-store.php tests/test-settings-store.php
git commit -m "feat: settings store for per-role block permissions

Stores what is blocked per role rather than what is allowed. That inversion is
what makes an unconfigured block default to allowed and makes an entry survive a
temporarily deactivated plugin: nothing is ever pruned.

Every write is validated - role slugs through sanitize_key(), block names
against the WordPress block name grammar, labels through sanitize_text_field().
Invalid entries are dropped, and a corrupt option reads as empty rather than
raising anything, so the worst case is an inert plugin."
```

---

### Task 2: Runtime filter

**Files:**
- Create: `includes/block-filter.php`
- Create: `tests/test-block-filter.php`

**Interfaces:**
- Consumes: `jpkcom_allow_blocks_get_settings()`, `jpkcom_allow_blocks_blocked_for_roles()` from Task 1.
- Produces:
  - `jpkcom_allow_blocks_is_exempt(): bool`
  - `jpkcom_allow_blocks_current_role_slugs(): array`
  - `jpkcom_allow_blocks_all_block_names( array $settings ): array`
  - `jpkcom_allow_blocks_filter_allowed( mixed $allowed, mixed $context ): mixed` — registered on `allowed_block_types_all`

- [ ] **Step 1: Write the failing test**

Create `tests/test-block-filter.php`:

```php
<?php
/**
 * Regression tests for the allowed_block_types_all filter.
 *
 * @package JPKCom_Allow_Blocks
 * @since 3.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    define( constant_name: 'ABSPATH', value: __DIR__ . '/' );
}

$GLOBALS['jpkcom_options']  = array();
$GLOBALS['jpkcom_caps']     = array( 'manage_options' => false );
$GLOBALS['jpkcom_roles']    = array( 'editor' );
$GLOBALS['jpkcom_registry'] = array( 'core/paragraph', 'core/heading', 'core/code', 'core/html' );
$GLOBALS['jpkcom_hooks']    = array();
$GLOBALS['jpkcom_filters']  = array();

if ( ! function_exists( function: 'get_option' ) ) {
    function get_option( string $option, mixed $default_value = false ): mixed {
        return $GLOBALS['jpkcom_options'][ $option ] ?? $default_value;
    }
}

if ( ! function_exists( function: 'update_option' ) ) {
    function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
        $GLOBALS['jpkcom_options'][ $option ] = $value;
        return true;
    }
}

if ( ! function_exists( function: 'sanitize_key' ) ) {
    function sanitize_key( string $key ): string {
        return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) );
    }
}

if ( ! function_exists( function: 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $str ): string {
        return trim( strip_tags( $str ) );
    }
}

if ( ! function_exists( function: 'current_time' ) ) {
    function current_time( string $type, int|bool $gmt = 0 ): string {
        return '2026-07-30T12:00:00+00:00';
    }
}

if ( ! function_exists( function: 'current_user_can' ) ) {
    function current_user_can( string $capability, mixed ...$args ): bool {
        return (bool) ( $GLOBALS['jpkcom_caps'][ $capability ] ?? false );
    }
}

if ( ! function_exists( function: 'is_user_logged_in' ) ) {
    function is_user_logged_in(): bool {
        return true;
    }
}

if ( ! function_exists( function: 'wp_get_current_user' ) ) {
    function wp_get_current_user(): object {
        return (object) array( 'roles' => $GLOBALS['jpkcom_roles'] );
    }
}

if ( ! function_exists( function: 'add_filter' ) ) {
    function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $GLOBALS['jpkcom_hooks'][ $hook ][] = array( $callback, $priority );
    }
}

if ( ! function_exists( function: 'apply_filters' ) ) {
    function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
        foreach ( $GLOBALS['jpkcom_filters'][ $hook ] ?? array() as $cb ) {
            $value = $cb( $value, ...$args );
        }
        return $value;
    }
}

if ( ! class_exists( class: 'WP_Block_Type_Registry' ) ) {
    class WP_Block_Type_Registry {
        public static function get_instance(): self {
            return new self();
        }

        /** @return array<string,object> */
        public function get_all_registered(): array {
            $out = array();
            foreach ( $GLOBALS['jpkcom_registry'] as $name ) {
                $out[ $name ] = (object) array( 'name' => $name );
            }
            return $out;
        }
    }
}

require_once dirname( path: __DIR__ ) . '/includes/settings-store.php';
require_once dirname( path: __DIR__ ) . '/includes/block-filter.php';

$failed = 0;
$passed = 0;

/**
 * Assert a condition and report it.
 *
 * @param string $name Case name.
 * @param bool   $ok   Whether the case passed.
 * @param string $note Extra detail printed on failure.
 * @return void
 */
function jpkcom_check( string $name, bool $ok, string $note = '' ): void {
    global $failed, $passed;

    if ( $ok ) {
        ++$passed;
        printf( "  ok   %s\n", $name );
        return;
    }

    ++$failed;
    printf( "  FAIL %s%s\n", $name, $note !== '' ? ' -- ' . $note : '' );
}

echo "jpkcom-allow-blocks: runtime filter\n";

jpkcom_check(
    'the filter is registered on allowed_block_types_all',
    ( $GLOBALS['jpkcom_hooks']['allowed_block_types_all'] ?? array() ) !== array()
);

/* Nothing configured: the plugin must be completely inert. */
jpkcom_check( 'inert for true', jpkcom_allow_blocks_filter_allowed( true, null ) === true );
jpkcom_check( 'inert for an array', jpkcom_allow_blocks_filter_allowed( array( 'core/paragraph' ), null ) === array( 'core/paragraph' ) );
jpkcom_check( 'inert for false', jpkcom_allow_blocks_filter_allowed( false, null ) === false );

jpkcom_allow_blocks_save_settings(
    array( 'roles' => array( 'editor' => array( 'core/code', 'core/html' ) ) )
);

/* Administrators are exempt, by capability. */
$GLOBALS['jpkcom_caps']['manage_options'] = true;
jpkcom_check( 'an administrator is exempt', jpkcom_allow_blocks_filter_allowed( true, null ) === true );
$GLOBALS['jpkcom_caps']['manage_options'] = false;

/* Incoming true: build from the registry and subtract. */
$result = jpkcom_allow_blocks_filter_allowed( true, null );
sort( $result );
jpkcom_check( 'true becomes the registry minus the blocked names', $result === array( 'core/heading', 'core/paragraph' ), json_encode( $result ) );

/* Incoming array: subtract only, never add. */
$result = jpkcom_allow_blocks_filter_allowed( array( 'core/paragraph', 'core/code' ), null );
jpkcom_check( 'an incoming array is only reduced', $result === array( 'core/paragraph' ), json_encode( $result ) );

/* Incoming false stays false. */
jpkcom_check( 'false is left alone', jpkcom_allow_blocks_filter_allowed( false, null ) === false );

/* A block nobody registered but that is blocked must not appear. */
$result = jpkcom_allow_blocks_filter_allowed( true, null );
jpkcom_check( 'blocked names never reappear', ! in_array( 'core/code', $result, true ) );

/* Extra names can be added for blocks PHP cannot see. */
$GLOBALS['jpkcom_filters']['jpkcom_allow_blocks_extra_block_names'] = array(
    static function ( array $names ): array {
        $names[] = 'js-only/widget';
        return $names;
    },
);
$result = jpkcom_allow_blocks_filter_allowed( true, null );
jpkcom_check( 'extra block names are included', in_array( 'js-only/widget', $result, true ), json_encode( $result ) );
$GLOBALS['jpkcom_filters'] = array();

/* The exemption is overridable. */
$GLOBALS['jpkcom_filters']['jpkcom_allow_blocks_is_exempt'] = array(
    static function ( bool $exempt ): bool {
        return true;
    },
);
jpkcom_check( 'the exemption filter wins', jpkcom_allow_blocks_filter_allowed( true, null ) === true );
$GLOBALS['jpkcom_filters'] = array();

/* Two roles: blocked only where both block it. */
$GLOBALS['jpkcom_roles'] = array( 'editor', 'shop' );
$result                  = jpkcom_allow_blocks_filter_allowed( true, null );
jpkcom_check( 'a second role that blocks nothing lifts the restriction', $result === true );
$GLOBALS['jpkcom_roles'] = array( 'editor' );

printf( "\n  %d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-block-filter.php`
Expected: FAIL — `Failed opening required '.../includes/block-filter.php'`

- [ ] **Step 3: Write the implementation**

Create `includes/block-filter.php`:

```php
<?php
/**
 * Runtime block restriction
 *
 * Turns the stored deny list into the allow list WordPress expects.
 *
 * @package JPKCom_Allow_Blocks
 * @since   3.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( function: 'jpkcom_allow_blocks_is_exempt' ) ) {

    /**
     * Whether the current user is exempt from any restriction.
     *
     * Expressed as a capability rather than a role slug so multisite super
     * admins are covered. Administrators are never restricted.
     *
     * @since 3.0.0
     *
     * @return bool True when no restriction applies.
     */
    function jpkcom_allow_blocks_is_exempt(): bool {
        /**
         * Filters whether the current user is exempt from block restrictions.
         *
         * @since 3.0.0
         *
         * @param bool $exempt Whether the user is exempt.
         */
        return (bool) apply_filters( 'jpkcom_allow_blocks_is_exempt', current_user_can( 'manage_options' ) );
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_current_role_slugs' ) ) {

    /**
     * Role slugs of the current user.
     *
     * @since 3.0.0
     *
     * @return string[] Role slugs, empty when there is no user.
     */
    function jpkcom_allow_blocks_current_role_slugs(): array {
        if ( ! is_user_logged_in() ) {
            return array();
        }

        $user = wp_get_current_user();

        if ( ! isset( $user->roles ) || ! is_array( $user->roles ) ) {
            return array();
        }

        return array_values( array_filter( $user->roles, 'is_string' ) );
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_all_block_names' ) ) {

    /**
     * Every block name this site knows about.
     *
     * The server-side registry, plus every name mentioned in the settings so a
     * deactivated plugin's blocks are not silently forgotten, plus whatever the
     * extension filter adds.
     *
     * Blocks registered only in JavaScript are invisible to PHP. Sites using
     * such blocks can add their names through
     * `jpkcom_allow_blocks_extra_block_names`.
     *
     * @since 3.0.0
     *
     * @param array $settings Validated settings.
     * @return string[] Unique block names.
     */
    function jpkcom_allow_blocks_all_block_names( array $settings ): array {
        $names = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );

        foreach ( $settings['roles'] as $blocked ) {
            $names = array_merge( $names, $blocked );
        }

        $names = array_merge( $names, array_keys( $settings['labels'] ) );

        /**
         * Filters the block names the allow list is built from.
         *
         * @since 3.0.0
         *
         * @param string[] $names Block names known to PHP.
         */
        $names = apply_filters( 'jpkcom_allow_blocks_extra_block_names', $names );

        if ( ! is_array( $names ) ) {
            return array();
        }

        return array_values( array_unique( array_filter( $names, 'jpkcom_allow_blocks_is_valid_block_name' ) ) );
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_filter_allowed' ) ) {

    /**
     * Remove the blocked block types from the allowed list.
     *
     * Returns the incoming value untouched whenever nothing is blocked, so an
     * active but unconfigured plugin has no effect at all. An incoming array is
     * only ever reduced, never extended, so restrictions set by other plugins
     * are respected instead of overwritten.
     *
     * @since 3.0.0
     *
     * @param mixed $allowed Incoming value: true, false or an array of names.
     * @param mixed $context The block editor context. Unused.
     * @return mixed The allowed block types.
     */
    function jpkcom_allow_blocks_filter_allowed( mixed $allowed, mixed $context ): mixed {
        if ( false === $allowed ) {
            return false;
        }

        if ( jpkcom_allow_blocks_is_exempt() ) {
            return $allowed;
        }

        $settings = jpkcom_allow_blocks_get_settings();
        $blocked  = jpkcom_allow_blocks_blocked_for_roles( jpkcom_allow_blocks_current_role_slugs(), $settings );

        if ( array() === $blocked ) {
            return $allowed;
        }

        if ( is_array( $allowed ) ) {
            return array_values( array_diff( $allowed, $blocked ) );
        }

        return array_values( array_diff( jpkcom_allow_blocks_all_block_names( $settings ), $blocked ) );
    }

}

add_filter( 'allowed_block_types_all', 'jpkcom_allow_blocks_filter_allowed', 10, 2 );
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-block-filter.php`
Expected: PASS, exit 0

- [ ] **Step 5: Commit**

```bash
git add includes/block-filter.php tests/test-block-filter.php
git commit -m "feat: derive the allowed block list from the stored deny list

Returns the incoming value untouched whenever nothing is blocked, so an active
but unconfigured plugin has no effect. An incoming array is only reduced, never
extended, so restrictions from other plugins survive - the previous code
replaced them outright.

Administrators are exempt through manage_options rather than the role slug, so
multisite super admins are covered. A block counts as blocked only when every
one of the user's roles blocks it, matching the additive semantics of
capabilities."
```

---

### Task 3: Bootstrap rewiring

Makes the plugin actually restrict blocks. After this task the feature works end to end for anyone willing to write the option by hand; the UI follows.

**Files:**
- Modify: `jpkcom-allow-blocks.php` — replace lines 61-106 (the `! is_admin()` block and the hard-coded list), bump the version
- Modify: `phpdoc.xml` — `<version number="…">`
- Modify: `README.md` — `**Version:**`, `**Stable tag:**`

**Interfaces:**
- Consumes: both include files from Tasks 1 and 2.
- Produces: `JPKCOM_ALLOW_BLOCKS_PATH` constant; the modules are loaded on `plugins_loaded` priority 5.

- [ ] **Step 1: Replace the dead filter block**

In `jpkcom-allow-blocks.php`, delete everything from the `// https://developer.wordpress.org/...` comment to the end of the file, and append:

```php
if ( ! defined( 'JPKCOM_ALLOW_BLOCKS_PATH' ) ) {
    define( constant_name: 'JPKCOM_ALLOW_BLOCKS_PATH', value: plugin_dir_path( __FILE__ ) );
}

/**
 * Load the plugin modules.
 *
 * Loaded on plugins_loaded so the settings store exists before anything reads
 * it, and well before `allowed_block_types_all` is applied when the editor
 * assembles its settings in wp-admin.
 *
 * @since 3.0.0
 *
 * @return void
 */
add_action( 'plugins_loaded', static function (): void {

    $modules = array(
        'includes/settings-store.php',
        'includes/block-filter.php',
    );

    if ( is_admin() ) {
        $modules[] = 'includes/admin-page.php';
        $modules[] = 'includes/import-export.php';
    }

    foreach ( $modules as $module ) {
        $file = JPKCOM_ALLOW_BLOCKS_PATH . $module;

        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }

}, 5 );
```

> The removed code wrapped everything in `if ( ! is_admin() )`. That is why the plugin never did anything: `is_admin()` means "this request is for the admin area", and the block editor *is* the admin area. `is_admin()` is used above only to decide whether the settings screen needs loading, which is a genuine admin-only concern.

- [ ] **Step 2: Bump the version in all five places**

`jpkcom-allow-blocks.php` header `Version:` and `Stable tag:` → `3.0.0`; `JPKCOM_ALLOW_BLOCKS_VERSION` → `'3.0.0'`; `phpdoc.xml` `<version number="3.0.0">`; `README.md` `**Version:** 3.0.0` and `**Stable tag:** 3.0.0`.

- [ ] **Step 3: Verify the guards pass**

```bash
php -l jpkcom-allow-blocks.php
php tests/test-settings-store.php && php tests/test-block-filter.php
```
Expected: no syntax errors, both suites exit 0.

- [ ] **Step 4: Verify the version is consistent**

Run:
```bash
grep -nE '^(Version|Stable tag):' jpkcom-allow-blocks.php
grep -n "JPKCOM_ALLOW_BLOCKS_VERSION', '" jpkcom-allow-blocks.php
grep -n 'version number' phpdoc.xml
grep -nE '^\*\*(Version|Stable tag):\*\*' README.md
```
Expected: every hit reads `3.0.0`.

- [ ] **Step 5: Commit**

```bash
git add jpkcom-allow-blocks.php phpdoc.xml README.md
git commit -m "feat!: replace the hard-coded allow list with the settings modules

BREAKING: jpkcom_allowed_block_types() is gone, without a deprecated shim. It
took two arguments and returned a fixed array regardless of them, so a shim
could only hand back that same stale list.

The old code wrapped its filter registration in if ( ! is_admin() ), which is
why it never had any effect: is_admin() means the request targets the admin
area, and the block editor is the admin area. Measured against WordPress 7.0.2
the editor received \"allowedBlockTypes\": true with the plugin active.

Nothing is migrated from the old list. Behaviour after this update matches
behaviour before it - everything allowed - until the settings screen is used."
```

---

### Task 4: Settings screen, read-only

Renders the matrix. Saving comes in Task 5, so this task ends with a screen you can look at and verify against a hand-written option.

**Files:**
- Create: `includes/admin-page.php`
- Create: `assets/admin.css`
- Create: `assets/admin.js`
- Create: `tests/test-admin-page.php`

**Interfaces:**
- Consumes: Tasks 1 and 2.
- Produces:
  - `jpkcom_allow_blocks_menu_slug(): string` → `'jpkcom-allow-blocks'`
  - `jpkcom_allow_blocks_editable_roles( bool $include_non_editing = false ): array` — `[ slug => display name ]`, never contains `administrator`
  - `jpkcom_allow_blocks_block_rows( array $settings ): array` — rows for the table, each `[ 'name', 'title', 'category', 'registered' ]`, sorted by category then title
  - `jpkcom_allow_blocks_render_page(): void`

- [ ] **Step 1: Write the failing test**

Create `tests/test-admin-page.php`:

```php
<?php
/**
 * Regression tests for the settings screen data helpers.
 *
 * @package JPKCom_Allow_Blocks
 * @since 3.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    define( constant_name: 'ABSPATH', value: __DIR__ . '/' );
}

$GLOBALS['jpkcom_options'] = array();
$GLOBALS['jpkcom_hooks']   = array();
$GLOBALS['jpkcom_filters'] = array();

$GLOBALS['jpkcom_wp_roles'] = array(
    'administrator' => array( 'name' => 'Administrator', 'capabilities' => array( 'edit_posts' => true, 'manage_options' => true ) ),
    'editor'        => array( 'name' => 'Editor', 'capabilities' => array( 'edit_posts' => true ) ),
    'subscriber'    => array( 'name' => 'Subscriber', 'capabilities' => array( 'read' => true ) ),
);

$GLOBALS['jpkcom_registry'] = array(
    'core/paragraph' => array( 'title' => 'Paragraph', 'category' => 'text' ),
    'core/heading'   => array( 'title' => 'Heading', 'category' => 'text' ),
    'core/image'     => array( 'title' => 'Image', 'category' => 'media' ),
);

if ( ! function_exists( function: 'get_option' ) ) {
    function get_option( string $option, mixed $default_value = false ): mixed {
        return $GLOBALS['jpkcom_options'][ $option ] ?? $default_value;
    }
}

if ( ! function_exists( function: 'update_option' ) ) {
    function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
        $GLOBALS['jpkcom_options'][ $option ] = $value;
        return true;
    }
}

if ( ! function_exists( function: 'sanitize_key' ) ) {
    function sanitize_key( string $key ): string {
        return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) );
    }
}

if ( ! function_exists( function: 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $str ): string {
        return trim( strip_tags( $str ) );
    }
}

if ( ! function_exists( function: 'current_time' ) ) {
    function current_time( string $type, int|bool $gmt = 0 ): string {
        return '2026-07-30T12:00:00+00:00';
    }
}

if ( ! function_exists( function: 'add_action' ) ) {
    function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $GLOBALS['jpkcom_hooks'][ $hook ][] = array( $callback, $priority );
    }
}

if ( ! function_exists( function: 'add_filter' ) ) {
    function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $GLOBALS['jpkcom_hooks'][ $hook ][] = array( $callback, $priority );
    }
}

if ( ! function_exists( function: 'apply_filters' ) ) {
    function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
        foreach ( $GLOBALS['jpkcom_filters'][ $hook ] ?? array() as $cb ) {
            $value = $cb( $value, ...$args );
        }
        return $value;
    }
}

if ( ! function_exists( function: '__' ) ) {
    function __( string $text, string $domain = 'default' ): string {
        return $text;
    }
}

if ( ! function_exists( function: 'wp_roles' ) ) {
    function wp_roles(): object {
        return (object) array( 'roles' => $GLOBALS['jpkcom_wp_roles'] );
    }
}

if ( ! class_exists( class: 'WP_Block_Type_Registry' ) ) {
    class WP_Block_Type_Registry {
        public static function get_instance(): self {
            return new self();
        }

        /** @return array<string,object> */
        public function get_all_registered(): array {
            $out = array();
            foreach ( $GLOBALS['jpkcom_registry'] as $name => $data ) {
                $out[ $name ] = (object) array( 'name' => $name, 'title' => $data['title'], 'category' => $data['category'] );
            }
            return $out;
        }
    }
}

require_once dirname( path: __DIR__ ) . '/includes/settings-store.php';
require_once dirname( path: __DIR__ ) . '/includes/admin-page.php';

$failed = 0;
$passed = 0;

/**
 * Assert a condition and report it.
 *
 * @param string $name Case name.
 * @param bool   $ok   Whether the case passed.
 * @param string $note Extra detail printed on failure.
 * @return void
 */
function jpkcom_check( string $name, bool $ok, string $note = '' ): void {
    global $failed, $passed;

    if ( $ok ) {
        ++$passed;
        printf( "  ok   %s\n", $name );
        return;
    }

    ++$failed;
    printf( "  FAIL %s%s\n", $name, $note !== '' ? ' -- ' . $note : '' );
}

echo "jpkcom-allow-blocks: settings screen data\n";

/* Roles. */
$roles = jpkcom_allow_blocks_editable_roles();

jpkcom_check( 'administrator is never offered', ! isset( $roles['administrator'] ) );
jpkcom_check( 'editor is offered', isset( $roles['editor'] ) );
jpkcom_check( 'a role without edit_posts is hidden by default', ! isset( $roles['subscriber'] ) );

$roles = jpkcom_allow_blocks_editable_roles( true );
jpkcom_check( 'a role without edit_posts can be shown', isset( $roles['subscriber'] ) );
jpkcom_check( 'administrator stays hidden even then', ! isset( $roles['administrator'] ) );

/* Rows. */
$settings = jpkcom_allow_blocks_sanitize_settings(
    array(
        'roles'  => array( 'editor' => array( 'gone/block' ) ),
        'labels' => array( 'gone/block' => 'Retired Block' ),
    )
);

$rows  = jpkcom_allow_blocks_block_rows( $settings );
$names = array_column( $rows, 'name' );

jpkcom_check( 'registered blocks are listed', in_array( 'core/paragraph', $names, true ) );
jpkcom_check( 'a block only known from settings is listed', in_array( 'gone/block', $names, true ) );

$by_name = array_column( $rows, null, 'name' );

jpkcom_check( 'a registered row is marked registered', true === $by_name['core/paragraph']['registered'] );
jpkcom_check( 'a settings-only row is marked unregistered', false === $by_name['gone/block']['registered'] );
jpkcom_check( 'a registered row uses the block title', $by_name['core/paragraph']['title'] === 'Paragraph' );
jpkcom_check( 'a settings-only row falls back to the stored label', $by_name['gone/block']['title'] === 'Retired Block' );
jpkcom_check( 'a registered row carries its category', $by_name['core/paragraph']['category'] === 'text' );
jpkcom_check( 'a settings-only row gets the unregistered category', $by_name['gone/block']['category'] === 'jpkcom-unregistered' );

/* Sorting is stable: category first, then title. */
$categories = array_column( $rows, 'category' );
$sorted     = $categories;
sort( $sorted );
jpkcom_check( 'rows are grouped by category', $categories === $sorted, json_encode( $categories ) );

/* The menu is registered under Appearance. */
jpkcom_check( 'the admin_menu hook is used', ( $GLOBALS['jpkcom_hooks']['admin_menu'] ?? array() ) !== array() );

printf( "\n  %d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-admin-page.php`
Expected: FAIL — `Failed opening required '.../includes/admin-page.php'`

- [ ] **Step 3: Write the data helpers and the page**

Create `includes/admin-page.php` with the four functions from the Interfaces block. `jpkcom_allow_blocks_editable_roles()` reads `wp_roles()->roles`, skips `administrator`, and skips roles whose `capabilities['edit_posts']` is not truthy unless `$include_non_editing` is true. `jpkcom_allow_blocks_block_rows()` merges the registry with every name in `$settings['roles']` and `$settings['labels']`, sets `category` to `jpkcom-unregistered` and `registered` to `false` for names not in the registry, uses the registry `title` when present and the stored label otherwise, falls back to the block name, then sorts by `category` then `title`.

Register the page:

```php
add_action( 'admin_menu', static function (): void {
    $hook = add_submenu_page(
        'themes.php',
        __( 'Block permissions', 'jpkcom-allow-blocks' ),
        __( 'Block permissions', 'jpkcom-allow-blocks' ),
        'manage_options',
        jpkcom_allow_blocks_menu_slug(),
        'jpkcom_allow_blocks_render_page'
    );

    if ( ! $hook ) {
        return;
    }

    add_action( 'admin_print_styles-' . $hook, static function (): void {
        wp_enqueue_style(
            'jpkcom-allow-blocks-admin',
            plugins_url( 'assets/admin.css', JPKCOM_ALLOW_BLOCKS_PATH . 'jpkcom-allow-blocks.php' ),
            array(),
            JPKCOM_ALLOW_BLOCKS_VERSION
        );
        wp_enqueue_script(
            'jpkcom-allow-blocks-admin',
            plugins_url( 'assets/admin.js', JPKCOM_ALLOW_BLOCKS_PATH . 'jpkcom-allow-blocks.php' ),
            array(),
            JPKCOM_ALLOW_BLOCKS_VERSION,
            true
        );
    } );
} );
```

`jpkcom_allow_blocks_render_page()` outputs a `<form method="post" action="admin-post.php">` with `wp_nonce_field( 'jpkcom_allow_blocks_save', 'jpkcom_allow_blocks_nonce' )`, a hidden `action` field of `jpkcom_allow_blocks_save`, the search/category/column controls, and a `<table class="widefat jpkcom-ab-table">`. For every row emit a hidden `rendered[]` input carrying the block name, and for every role column a checkbox `allowed[<role>][<block name>]` that is **checked when the block is not blocked**. Escape every output: `esc_html()` for text, `esc_attr()` for attributes.

Create `assets/admin.css` with minimal styling for the sticky header row, the warning marker on unregistered rows and column widths. Create `assets/admin.js` with no dependencies: a `keyup` handler on the search field that hides non-matching `<tr>` elements, a `change` handler on the category select, checkbox column toggles, and a role-column visibility toggle. Guard everything with `document.querySelector( '.jpkcom-ab-table' )` so the script is inert on other screens.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-admin-page.php`
Expected: PASS, exit 0

- [ ] **Step 5: Verify by hand on the DDEV instance**

```bash
cd /home/jpk/wp && rsync -a --exclude='.git' --exclude='.github' --exclude='CLAUDE.md' --exclude='docs' \
  jpkcom-allow-blocks/ /home/jpk/ddev/posts/wp-content/plugins/jpkcom-allow-blocks/
cd /home/jpk/ddev/posts && ddev wp plugin activate jpkcom-allow-blocks
```
Open `Appearance → Block permissions`. Expected: 168 rows grouped by category, one column per editing role, everything checked.

- [ ] **Step 6: Commit**

```bash
git add includes/admin-page.php assets/ tests/test-admin-page.php
git commit -m "feat: settings screen listing every block per role

Rows are the block registry unioned with every name in the settings, so blocks
from a deactivated plugin still appear - grouped as unregistered and marked,
rather than quietly dropped.

Administrators are never listed: they cannot be restricted, so offering the
column would be a lie. Roles without edit_posts are hidden behind a toggle,
since they never see the editor."
```

---

### Task 5: Saving

**Files:**
- Modify: `includes/admin-page.php` — add the `admin_post` handler
- Modify: `tests/test-admin-page.php` — add the difference cases

**Interfaces:**
- Consumes: Task 4.
- Produces: `jpkcom_allow_blocks_apply_form( array $settings, array $rendered, array $allowed ): array` — pure function, computes the new settings from a form submission.

- [ ] **Step 1: Add the failing test**

Append to `tests/test-admin-page.php`, before the summary `printf`:

```php
/*
 * Saving computes the difference over the rendered rows only. Without that, a
 * save with an active search filter would wipe every setting not on screen.
 */
$settings = jpkcom_allow_blocks_sanitize_settings(
    array( 'roles' => array( 'editor' => array( 'core/code', 'core/html' ) ) )
);

$result = jpkcom_allow_blocks_apply_form(
    $settings,
    array( 'core/code', 'core/paragraph' ),
    array( 'editor' => array( 'core/code' => '1' ) )
);

jpkcom_check(
    'a row rendered and ticked is unblocked',
    ! in_array( 'core/code', $result['roles']['editor'], true )
);
jpkcom_check(
    'a row rendered and unticked is blocked',
    in_array( 'core/paragraph', $result['roles']['editor'], true )
);
jpkcom_check(
    'a row that was not rendered keeps its setting',
    in_array( 'core/html', $result['roles']['editor'], true ),
    json_encode( $result['roles']['editor'] )
);

$result = jpkcom_allow_blocks_apply_form( $settings, array( 'core/code' ), array() );
jpkcom_check(
    'a role absent from the submission still loses its rendered rows',
    in_array( 'core/code', $result['roles']['editor'], true )
);

$result = jpkcom_allow_blocks_apply_form( $settings, array( 'bad name', 'core/code' ), array( 'editor' => array() ) );
jpkcom_check( 'invalid rendered names are ignored', ! in_array( 'bad name', $result['roles']['editor'], true ) );

jpkcom_check(
    'labels are recorded for rendered blocks',
    ( $result['labels']['core/code'] ?? '' ) !== ''
);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-admin-page.php`
Expected: FAIL — `Call to undefined function jpkcom_allow_blocks_apply_form()`

- [ ] **Step 3: Implement the pure function and the handler**

Add to `includes/admin-page.php`:

```php
if ( ! function_exists( function: 'jpkcom_allow_blocks_apply_form' ) ) {

    /**
     * Compute new settings from a settings-screen submission.
     *
     * The difference is taken over the rendered rows only: names the form did
     * not show keep whatever they had. A save while the table is filtered must
     * not wipe the rows that were scrolled out of view.
     *
     * @since 3.0.0
     *
     * @param array                        $settings Current validated settings.
     * @param string[]                     $rendered Block names the form rendered.
     * @param array<string,array<string,mixed>> $allowed Ticked boxes, keyed role then block name.
     * @return array New settings, not yet stored.
     */
    function jpkcom_allow_blocks_apply_form( array $settings, array $rendered, array $allowed ): array {
        $rendered = array_values( array_unique( array_filter( $rendered, 'jpkcom_allow_blocks_is_valid_block_name' ) ) );
        $rows     = array_column( jpkcom_allow_blocks_block_rows( $settings ), null, 'name' );

        foreach ( array_keys( jpkcom_allow_blocks_editable_roles( true ) ) as $role ) {
            $previous = $settings['roles'][ $role ] ?? array();
            $kept     = array_values( array_diff( $previous, $rendered ) );
            $ticked   = $allowed[ $role ] ?? array();

            foreach ( $rendered as $name ) {
                if ( ! isset( $ticked[ $name ] ) ) {
                    $kept[] = $name;
                }
            }

            $settings['roles'][ $role ] = array_values( array_unique( $kept ) );
        }

        foreach ( $rendered as $name ) {
            $title = $rows[ $name ]['title'] ?? $name;

            if ( is_string( $title ) && '' !== $title ) {
                $settings['labels'][ $name ] = $title;
            }
        }

        return jpkcom_allow_blocks_sanitize_settings( $settings );
    }

}

add_action( 'admin_post_jpkcom_allow_blocks_save', static function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to change block permissions.', 'jpkcom-allow-blocks' ), '', array( 'response' => 403 ) );
    }

    check_admin_referer( 'jpkcom_allow_blocks_save', 'jpkcom_allow_blocks_nonce' );

    $rendered = isset( $_POST['rendered'] ) && is_array( $_POST['rendered'] )
        ? array_map( 'sanitize_text_field', wp_unslash( $_POST['rendered'] ) )
        : array();

    $allowed = isset( $_POST['allowed'] ) && is_array( $_POST['allowed'] )
        ? wp_unslash( $_POST['allowed'] )
        : array();

    jpkcom_allow_blocks_save_settings(
        jpkcom_allow_blocks_apply_form( jpkcom_allow_blocks_get_settings(), $rendered, is_array( $allowed ) ? $allowed : array() )
    );

    wp_safe_redirect( add_query_arg( 'jpkcom-ab-saved', '1', admin_url( 'themes.php?page=' . jpkcom_allow_blocks_menu_slug() ) ) );
    exit;
} );
```

In `jpkcom_allow_blocks_render_page()`, show a success notice when `$_GET['jpkcom-ab-saved']` is present.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-admin-page.php`
Expected: PASS, exit 0

- [ ] **Step 5: Verify by hand**

Re-sync to DDEV. Untick two blocks for `editor`, save. Then run:

```bash
cd /home/jpk/ddev/posts && ddev wp option get jpkcom_allow_blocks_settings --format=json
```
Expected: those two names appear under `roles.editor`. Then type a search term, save again, and confirm the two names are still there.

- [ ] **Step 6: Commit**

```bash
git add includes/admin-page.php tests/test-admin-page.php
git commit -m "feat: save block permissions from the settings screen

The difference is computed over the rendered rows only. Saving while the table
is filtered must not wipe settings for rows that were not on screen, which a
naive \"everything not ticked is blocked\" would do.

Every write is nonce- and capability-checked, and goes through the settings
store so validation cannot be skipped."
```

---

### Task 6: Export

**Files:**
- Create: `includes/import-export.php`
- Create: `tests/test-import-export.php`

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - `jpkcom_allow_blocks_export_payload( array $settings ): array` — adds `plugin_version`, `site_url`, `exported`
  - `jpkcom_allow_blocks_export_filename(): string`

- [ ] **Step 1: Write the failing test**

Create `tests/test-import-export.php` with the same stub preamble as `tests/test-settings-store.php`, plus stubs for `home_url()` returning `'https://example.test'`, `wp_json_encode()` delegating to `json_encode()`, and a `JPKCOM_ALLOW_BLOCKS_VERSION` constant of `'3.0.0'`. Then:

```php
echo "jpkcom-allow-blocks: export\n";

$settings = jpkcom_allow_blocks_sanitize_settings(
    array( 'roles' => array( 'editor' => array( 'core/code' ) ), 'labels' => array( 'core/code' => 'Code' ) )
);

$payload = jpkcom_allow_blocks_export_payload( $settings );

jpkcom_check( 'the payload carries the schema', ( $payload['schema'] ?? 0 ) === 1 );
jpkcom_check( 'the payload carries the roles', ( $payload['roles']['editor'] ?? array() ) === array( 'core/code' ) );
jpkcom_check( 'the payload records the plugin version', ( $payload['plugin_version'] ?? '' ) === '3.0.0' );
jpkcom_check( 'the payload records the site', ( $payload['site_url'] ?? '' ) === 'https://example.test' );
jpkcom_check( 'the payload is JSON-encodable', is_string( wp_json_encode( $payload ) ) );
jpkcom_check( 'the filename ends in .json', str_ends_with( haystack: jpkcom_allow_blocks_export_filename(), needle: '.json' ) );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-import-export.php`
Expected: FAIL — `Failed opening required '.../includes/import-export.php'`

- [ ] **Step 3: Implement export**

Create `includes/import-export.php` with the two functions and an `admin_post_jpkcom_allow_blocks_export` handler that checks `manage_options` and `check_admin_referer( 'jpkcom_allow_blocks_export' )`, then sends `Content-Type: application/json`, `Content-Disposition: attachment; filename="…"`, `nocache_headers()`, echoes `wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )` and exits.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-import-export.php`
Expected: PASS, exit 0

- [ ] **Step 5: Commit**

```bash
git add includes/import-export.php tests/test-import-export.php
git commit -m "feat: export block permissions as JSON

Carries plugin version, site URL and timestamp alongside the settings so an
import can tell where a file came from."
```

---

### Task 7: Import with preview

**Files:**
- Modify: `includes/import-export.php`
- Modify: `tests/test-import-export.php`

**Interfaces:**
- Consumes: Task 6.
- Produces:
  - `jpkcom_allow_blocks_parse_import( string $json ): array` — `[ 'ok' => bool, 'error' => string, 'settings' => array ]`
  - `jpkcom_allow_blocks_import_preview( array $current, array $incoming, array $known_roles ): array` — `[ 'roles_changed', 'blocks_changed', 'unknown_roles', 'unknown_blocks' ]`
  - `jpkcom_allow_blocks_merge_import( array $current, array $incoming ): array`

- [ ] **Step 1: Add the failing test**

Append to `tests/test-import-export.php`:

```php
echo "\njpkcom-allow-blocks: import\n";

/* Parsing rejects anything that is not a valid payload, without changing state. */
foreach ( array( 'not json', '[]', '{"schema":99}', '{"roles":[]}' ) as $bad ) {
    $parsed = jpkcom_allow_blocks_parse_import( $bad );
    jpkcom_check( sprintf( 'rejects %s', var_export( $bad, true ) ), false === $parsed['ok'], $parsed['error'] );
}

$good   = wp_json_encode( jpkcom_allow_blocks_export_payload( $settings ) );
$parsed = jpkcom_allow_blocks_parse_import( (string) $good );
jpkcom_check( 'accepts its own export', true === $parsed['ok'], $parsed['error'] );
jpkcom_check( 'a round trip reproduces the roles', $parsed['settings']['roles'] === $settings['roles'] );

/* Merge is per role: a role in the file replaces, a role absent stays. */
$current = jpkcom_allow_blocks_sanitize_settings(
    array( 'roles' => array( 'editor' => array( 'core/code' ), 'author' => array( 'core/html' ) ) )
);
$incoming = jpkcom_allow_blocks_sanitize_settings(
    array( 'roles' => array( 'editor' => array( 'core/table' ), 'shop_manager' => array( 'core/video' ) ) )
);

$merged = jpkcom_allow_blocks_merge_import( $current, $incoming );

jpkcom_check( 'a role in the file replaces its list', $merged['roles']['editor'] === array( 'core/table' ) );
jpkcom_check( 'a role absent from the file is untouched', $merged['roles']['author'] === array( 'core/html' ) );
jpkcom_check( 'a role unknown here is stored anyway', ( $merged['roles']['shop_manager'] ?? array() ) === array( 'core/video' ) );

/* The preview names what will happen before anything is written. */
$preview = jpkcom_allow_blocks_import_preview( $current, $incoming, array( 'editor', 'author' ) );

jpkcom_check( 'preview counts the changed roles', $preview['roles_changed'] === 2, json_encode( $preview ) );
jpkcom_check( 'preview names roles unknown here', $preview['unknown_roles'] === array( 'shop_manager' ) );
jpkcom_check( 'preview counts changed blocks', $preview['blocks_changed'] > 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-import-export.php`
Expected: FAIL — `Call to undefined function jpkcom_allow_blocks_parse_import()`

- [ ] **Step 3: Implement parse, preview and merge**

`jpkcom_allow_blocks_parse_import()` decodes with `json_decode( $json, true )`, requires an array with `schema === 1` and an array `roles` key, and returns the sanitised settings. Every failure path sets `ok` to false with a translated message and leaves state untouched.

`jpkcom_allow_blocks_merge_import()` replaces `roles` entries present in `$incoming`, keeps the rest, and merges `labels` with the incoming file winning.

`jpkcom_allow_blocks_import_preview()` compares the two and returns counts plus the role slugs not in `$known_roles`.

Add two `admin_post` handlers: `jpkcom_allow_blocks_import_preview` reads `$_FILES['jpkcom_allow_blocks_file']`, verifies `is_uploaded_file()`, rejects anything over 1 MB, parses, and renders the preview with a confirm form carrying the payload in a hidden field; `jpkcom_allow_blocks_import_apply` re-parses that field, merges, saves and redirects. Both check `manage_options` and their own nonce.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-import-export.php`
Expected: PASS, exit 0

- [ ] **Step 5: Verify by hand**

Export on the DDEV instance, edit the file to block a different block for `editor`, import it, confirm the preview names the change, apply, and check the option.

- [ ] **Step 6: Commit**

```bash
git add includes/import-export.php tests/test-import-export.php
git commit -m "feat: import block permissions with a preview

Merges per role: a role present in the file replaces that role's list, roles
absent from the file are left alone. A role that does not exist on this site is
stored anyway - it may be created later, or come from a plugin that is
currently off - and the preview names it so the import is not silently doing
more than it appears to.

Invalid JSON or a wrong schema changes nothing."
```

---

### Task 8: Documentation, localisation and release

**Files:**
- Modify: `CLAUDE.md`, `README.md`
- Create: `languages/jpkcom-allow-blocks.pot`, `languages/jpkcom-allow-blocks-de_DE.po`, `.mo`, `.l10n.php`, and the `de_DE_formal` set
- Modify: `jpkcom-allow-blocks.php` — add `Text Domain:` and `Domain Path:` headers and a `load_plugin_textdomain()` call on `plugins_loaded`

- [ ] **Step 1: Add the text domain headers and loader**

Add `Text Domain: jpkcom-allow-blocks` and `Domain Path: /languages` to the plugin header. Add a `plugins_loaded` callback calling `load_plugin_textdomain( 'jpkcom-allow-blocks', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' )`.

- [ ] **Step 2: Generate the catalogues**

```bash
cd /home/jpk/wp && rsync -a --exclude='.git' --exclude='.github' --exclude='CLAUDE.md' --exclude='docs' \
  jpkcom-allow-blocks/ /home/jpk/ddev/posts/wp-content/plugins/jpkcom-allow-blocks/
cd /home/jpk/ddev/posts
ddev wp i18n make-pot wp-content/plugins/jpkcom-allow-blocks \
  wp-content/plugins/jpkcom-allow-blocks/languages/jpkcom-allow-blocks.pot \
  --slug=jpkcom-allow-blocks --exclude=tests,docs --allow-root
```

Copy the POT back, create the two `.po` files from it with a proper header including `Plural-Forms`, translate every string into German — informal for `de_DE`, `Sie` forms for `de_DE_formal` — then `ddev wp i18n make-mo` and `ddev wp i18n make-php`, and copy the results back.

> Do not build the catalogues with `msgcat --use-first`: it takes the first file's header and silently drops `Plural-Forms`. Use `msgmerge --compendium` when merging translations into an existing `.po`.

- [ ] **Step 3: Rewrite CLAUDE.md and README.md**

`CLAUDE.md` gets: the new architecture block, the deny-list rationale, why `is_admin()` was the bug, the exemption capability, the intersection rule, the JS-only limitation and its filter, the save-difference rule, the import merge unit, and a Tests section. `README.md` gets a description of the settings screen, the export/import behaviour, and a changelog entry for `3.0.0` that names the breaking removal of `jpkcom_allowed_block_types()`.

- [ ] **Step 4: Run every guard**

```bash
cd /home/jpk/wp/jpkcom-allow-blocks
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 -P4 php -l | grep -v '^No syntax errors'
for t in tests/test-*.php; do php "$t" >/dev/null || echo "FAILED: $t"; done
python3 -c "import pathlib,yaml; [yaml.safe_load(f.read_text()) for f in pathlib.Path('.github').rglob('*.yml')]"
grep -rnE '^[[:space:]]*(-[[:space:]]+)?uses:' .github/workflows/ | grep -vE '@[0-9a-f]{40}'
grep -rlIn '[äöüÄÖÜß]' --include='*.php' --exclude-dir=.git . 
```
Expected: no output from any of them except the last, which may only list files under `languages/`.

- [ ] **Step 5: Commit and release**

```bash
git add -A
git commit -m "docs: document the settings screen, add German catalogues"
git push origin main
git tag v3.0.0
git push origin v3.0.0
```

- [ ] **Step 6: Verify the published artefact**

Wait for `https://jpkcom.github.io/jpkcom-allow-blocks/plugin_jpkcom-allow-blocks.json` to report `3.0.0`, then confirm: the manifest version, the ZIP checksum against both the manifest and the `.sha256` file, the version header inside the ZIP, that `tests/` and `docs/` are absent from the ZIP, and that `includes/settings-store.php` is present.

---

## Self-Review

**Spec coverage:** Data model → Task 1. Runtime filter incl. all four incoming values, exemption, intersection, extension filter → Task 2. Removal of the hard-coded list and the `is_admin()` bug → Task 3. Matrix, categories, unregistered group, role visibility → Task 4. Save difference over rendered rows → Task 5. Export → Task 6. Import with preview and per-role merge, unknown roles stored → Task 7. Error handling is covered by the sanitiser tests in Task 1, the parse tests in Task 7 and the upload checks in Task 7 Step 3. Localisation and the "no `uninstall.php`" decision → Task 8. Out-of-scope items are not implemented anywhere.

**Placeholder scan:** No TBD, TODO or "similar to Task N". Tasks 4, 7 and 8 describe rendering, handler and catalogue work in prose rather than full code — these are the parts where exact markup and translated strings are a judgement call, and each names the exact functions, hooks, nonces and capabilities to use.

**Type consistency:** `jpkcom_allow_blocks_get_settings()`, `_sanitize_settings()`, `_save_settings()`, `_blocked_for_roles()`, `_is_valid_block_name()`, `_option_name()`, `_empty_settings()` (Task 1) are used with the same names and signatures in Tasks 2, 4, 5, 6 and 7. `_block_rows()` and `_editable_roles()` (Task 4) are consumed by `_apply_form()` (Task 5) with matching shapes: rows carry `name`, `title`, `category`, `registered`; roles are `[ slug => name ]`. `_export_payload()` (Task 6) is consumed by `_parse_import()` (Task 7).
