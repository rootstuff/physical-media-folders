/**
 * Physical Media Folders — searchable folder select.
 *
 * Progressively enhances the plugin's folder <select> elements into a
 * type-to-filter combobox. The original select stays in the DOM (hidden)
 * as the source of truth: choosing an option sets its value and fires a
 * bubbling `change` event, so every existing behavior — form submission,
 * async list filtering, grid props, modal field saves — is untouched.
 */
( function () {
	'use strict';

	var cfg = window.pmfPicker || {};
	var SELECTOR = [
		'#pmf_folder_filter',
		'#pmf-media-folder-filter',
		'#pmf-upload-folder',
		'#pmf_default_upload_folder',
		'#pmf_target',
		'select[id^="attachments-"][id$="-pmf_folder"]',
	].join( ', ' );

	function currentLabel( select ) {
		var option = select.options[ select.selectedIndex ];
		return option ? option.text : '';
	}

	function enhance( select ) {
		if ( select.dataset.pmfEnhanced ) {
			return;
		}
		select.dataset.pmfEnhanced = '1';

		var wrap = document.createElement( 'span' );
		wrap.className = 'pmf-combobox';
		select.parentNode.insertBefore( wrap, select );
		wrap.appendChild( select );

		var input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'pmf-combobox-input';
		input.autocomplete = 'off';
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.placeholder = cfg.placeholder || '';
		input.value = currentLabel( select );
		wrap.appendChild( input );

		var panel = document.createElement( 'ul' );
		panel.className = 'pmf-combobox-panel';
		panel.setAttribute( 'role', 'listbox' );
		panel.style.display = 'none';
		wrap.appendChild( panel );

		var open = false;
		var highlighted = -1;
		var visible = []; // Currently rendered li elements.

		function close() {
			open = false;
			highlighted = -1;
			panel.style.display = 'none';
			input.setAttribute( 'aria-expanded', 'false' );
			input.value = currentLabel( select );
		}

		function choose( value ) {
			select.value = value;
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			close();
		}

		function setHighlight( index ) {
			if ( highlighted >= 0 && visible[ highlighted ] ) {
				visible[ highlighted ].classList.remove( 'pmf-combobox-active' );
			}
			highlighted = index;
			if ( highlighted >= 0 && visible[ highlighted ] ) {
				visible[ highlighted ].classList.add( 'pmf-combobox-active' );
				visible[ highlighted ].scrollIntoView( { block: 'nearest' } );
			}
		}

		function renderPanel( query ) {
			panel.innerHTML = '';
			visible = [];
			query = ( query || '' ).toLowerCase();

			Array.prototype.forEach.call( select.options, function ( option ) {
				var label = option.text;
				var haystack = ( label + ' ' + option.value ).toLowerCase();
				var index = query ? haystack.indexOf( query ) : 0;

				if ( -1 === index ) {
					return;
				}

				var li = document.createElement( 'li' );
				li.setAttribute( 'role', 'option' );
				li.dataset.value = option.value;

				// Highlight the match inside the visible label.
				var labelIndex = query ? label.toLowerCase().indexOf( query ) : -1;
				if ( labelIndex >= 0 ) {
					li.appendChild( document.createTextNode( label.slice( 0, labelIndex ) ) );
					var mark = document.createElement( 'mark' );
					mark.textContent = label.slice( labelIndex, labelIndex + query.length );
					li.appendChild( mark );
					li.appendChild( document.createTextNode( label.slice( labelIndex + query.length ) ) );
				} else {
					li.textContent = label;
				}

				if ( option.value === select.value ) {
					li.classList.add( 'pmf-combobox-current' );
				}

				li.addEventListener( 'mousedown', function ( event ) {
					event.preventDefault(); // Keep focus; blur would close first.
					choose( option.value );
				} );

				panel.appendChild( li );
				visible.push( li );
			} );

			if ( ! visible.length ) {
				var empty = document.createElement( 'li' );
				empty.className = 'pmf-combobox-empty';
				empty.textContent = cfg.noMatches || '';
				panel.appendChild( empty );
			}

			setHighlight( visible.length ? 0 : -1 );
		}

		function show() {
			open = true;
			input.setAttribute( 'aria-expanded', 'true' );
			panel.style.display = 'block';
			renderPanel( '' );
		}

		input.addEventListener( 'focus', function () {
			input.select();
			show();
		} );

		input.addEventListener( 'input', function () {
			if ( ! open ) {
				show();
			}
			renderPanel( input.value.trim() );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				if ( ! open ) {
					show();
				} else {
					setHighlight( Math.min( highlighted + 1, visible.length - 1 ) );
				}
			} else if ( 'ArrowUp' === event.key ) {
				event.preventDefault();
				setHighlight( Math.max( highlighted - 1, 0 ) );
			} else if ( 'Enter' === event.key ) {
				if ( open && highlighted >= 0 && visible[ highlighted ] ) {
					event.preventDefault();
					choose( visible[ highlighted ].dataset.value );
				}
			} else if ( 'Escape' === event.key ) {
				close();
			}
		} );

		input.addEventListener( 'blur', function () {
			// Delay so option mousedown can run first.
			window.setTimeout( close, 120 );
		} );

		// Keep the visible label in sync when the value changes elsewhere
		// (tree clicks update the grid filter's select programmatically).
		select.addEventListener( 'change', function () {
			if ( ! open ) {
				input.value = currentLabel( select );
			}
		} );
	}

	function scan( root ) {
		( root || document ).querySelectorAll( SELECTOR ).forEach( enhance );
	}

	function boot() {
		scan( document );

		// Folder selects appear later inside the media modal, the grid
		// toolbar, and after async list-table swaps.
		new window.MutationObserver( function () {
			scan( document );
		} ).observe( document.body, { childList: true, subtree: true } );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
