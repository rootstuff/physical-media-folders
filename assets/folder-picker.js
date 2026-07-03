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
		if ( ! option ) {
			return '';
		}
		// Show the full path: the option labels are depth-indented
		// basenames ("— — mp3"), which are ambiguous outside the list.
		// Sentinel values ('', 'all' from AttachmentFilters, '/' for the
		// uploads root) show their human label instead.
		if ( option.value && '/' !== option.value && 'all' !== option.value ) {
			return option.value;
		}
		return option.text;
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

		function syncLabel() {
			if ( ! open ) {
				input.value = currentLabel( select );
			}
		}

		/**
		 * Backbone filter views may set the select to a transient value
		 * synchronously and correct it (without an event) a beat later;
		 * read again after the chain settles.
		 */
		function syncLabelSettled() {
			syncLabel();
			window.setTimeout( syncLabel, 150 );
		}

		function close() {
			open = false;
			highlighted = -1;
			panel.style.display = 'none';
			input.setAttribute( 'aria-expanded', 'false' );
			window.removeEventListener( 'scroll', onScrollAway, true );
			window.removeEventListener( 'resize', close );
			syncLabelSettled();
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

		/**
		 * The panel is fixed-positioned so it escapes scroll containers
		 * (the media modal's details sidebar clips absolute children).
		 */
		function positionPanel() {
			var rect = input.getBoundingClientRect();
			var spaceBelow = window.innerHeight - rect.bottom;

			panel.style.minWidth = rect.width + 'px';
			panel.style.left = rect.left + 'px';

			if ( spaceBelow < 180 && rect.top > spaceBelow ) {
				panel.style.top = 'auto';
				panel.style.bottom = window.innerHeight - rect.top + 2 + 'px';
				panel.style.maxHeight = Math.min( 280, rect.top - 12 ) + 'px';
			} else {
				panel.style.bottom = 'auto';
				panel.style.top = rect.bottom + 2 + 'px';
				panel.style.maxHeight = Math.min( 280, spaceBelow - 12 ) + 'px';
			}
		}

		function onScrollAway( event ) {
			// Scrolling inside the panel is fine; anything else moves the
			// anchor out from under the fixed panel, so close.
			if ( ! panel.contains( event.target ) ) {
				close();
			}
		}

		function show() {
			open = true;
			input.setAttribute( 'aria-expanded', 'true' );
			panel.style.display = 'block';
			positionPanel();
			renderPanel( '' );
			window.addEventListener( 'scroll', onScrollAway, true );
			window.addEventListener( 'resize', close );
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
		select.addEventListener( 'change', syncLabelSettled );

		// Programmatic value changes fire no event; resync as the pointer
		// arrives so a stale label is corrected before it can be read.
		wrap.addEventListener( 'mouseenter', syncLabel );
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

		// On media-new.php, keep the plupload instance's destination in
		// sync with the "Upload to folder" picker.
		var uploadSelect = document.getElementById( 'pmf-upload-folder' );
		if ( uploadSelect ) {
			uploadSelect.addEventListener( 'change', function () {
				if ( window.uploader && window.uploader.settings ) {
					window.uploader.settings.multipart_params.pmf_folder = uploadSelect.value;
				}
			} );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
