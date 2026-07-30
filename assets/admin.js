/**
 * Settings screen behaviour.
 *
 * Plain browser JavaScript, no dependencies. Every handler is guarded on the
 * presence of the block matrix table, so the script is inert on any other
 * admin screen it might accidentally be enqueued on.
 *
 * @package JPKCom_Allow_Blocks
 * @since   3.0.0
 */

( function () {
	'use strict';

	var table = document.querySelector( '.jpkcom-ab-table' );

	if ( ! table ) {
		return;
	}

	var searchField     = document.getElementById( 'jpkcom-ab-search' );
	var categoryField   = document.getElementById( 'jpkcom-ab-category' );
	var columnToggles   = document.querySelectorAll( '.jpkcom-ab-toggle-column' );
	var rolesToggle     = document.getElementById( 'jpkcom-ab-show-all-roles' );
	var rows            = table.querySelectorAll( 'tbody tr' );

	/**
	 * Re-apply the search and category filters to every row.
	 *
	 * @return {void}
	 */
	function applyFilters() {
		var term     = searchField ? searchField.value.trim().toLowerCase() : '';
		var category = categoryField ? categoryField.value : '';

		rows.forEach( function ( row ) {
			var matchesTerm     = '' === term || row.textContent.toLowerCase().indexOf( term ) !== -1;
			var matchesCategory = '' === category || row.getAttribute( 'data-category' ) === category;

			row.classList.toggle( 'jpkcom-ab-hidden-row', ! ( matchesTerm && matchesCategory ) );
		} );
	}

	/**
	 * Show or hide every cell belonging to one role column.
	 *
	 * @param {string}  role    Role slug.
	 * @param {boolean} visible Whether the column should be visible.
	 * @return {void}
	 */
	function toggleColumn( role, visible ) {
		var cells = table.querySelectorAll( '[data-role="' + role + '"]' );

		cells.forEach( function ( cell ) {
			cell.classList.toggle( 'jpkcom-ab-hidden-column', ! visible );
		} );
	}

	if ( searchField ) {
		searchField.addEventListener( 'keyup', applyFilters );
	}

	if ( categoryField ) {
		categoryField.addEventListener( 'change', applyFilters );
	}

	columnToggles.forEach( function ( toggle ) {
		toggle.addEventListener( 'change', function () {
			toggleColumn( toggle.getAttribute( 'data-role' ), toggle.checked );
		} );
	} );

	/*
	 * The "show all roles" checkbox lives in its own small GET form so its
	 * state round-trips through the query string: submitting it re-renders
	 * the page with the requested role set, and the save form below reads
	 * back the same state from a hidden field so the two never disagree.
	 */
	if ( rolesToggle && rolesToggle.form ) {
		rolesToggle.addEventListener( 'change', function () {
			rolesToggle.form.submit();
		} );
	}
} )();
