/**
 * Physical Media Folders — folder tree sidebar.
 *
 * Renders an expandable folder tree next to the media library (grid and
 * list modes) and on the Media > Folders screen. Supports dragging
 * attachments onto folders and folders onto folders, inline
 * create / rename / delete, and live folder search.
 */
( function () {
	'use strict';

	var cfg = window.pmfTree;
	if ( ! cfg ) {
		return;
	}

	var ROOT = '/'; // Sentinel for the uploads root (matches PHP).
	var STORAGE_KEY = 'pmfExpandedFolders';

	var state = {
		tree: cfg.tree,
		selected: typeof cfg.currentFolder === 'string' ? cfg.currentFolder : null,
		expanded: loadExpanded(),
		query: '',
		busy: false,
	};

	var container = null;
	var listHost = null;
	var noticeEl = null;
	var searchInput = null;
	var mode = null; // 'grid' | 'list'

	// ---- Utilities ----------------------------------------------------------

	function loadExpanded() {
		try {
			return new Set( JSON.parse( window.localStorage.getItem( STORAGE_KEY ) || '[]' ) );
		} catch ( e ) {
			return new Set();
		}
	}

	function saveExpanded() {
		try {
			window.localStorage.setItem( STORAGE_KEY, JSON.stringify( Array.from( state.expanded ) ) );
		} catch ( e ) {
			// Storage unavailable; expansion just won't persist.
		}
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function isDescendantPath( maybeChild, parent ) {
		return ( maybeChild + '/' ).indexOf( parent + '/' ) === 0;
	}

	function notify( message, isError ) {
		if ( ! noticeEl ) {
			return;
		}
		noticeEl.textContent = message;
		noticeEl.className = 'pmf-tree-notice' + ( isError ? ' pmf-tree-notice-error' : '' );
		noticeEl.style.display = message ? 'block' : 'none';
		if ( message && ! isError ) {
			window.clearTimeout( notify.timer );
			notify.timer = window.setTimeout( function () {
				noticeEl.style.display = 'none';
			}, 4000 );
		}
	}

	/**
	 * @param {string}  action  Ajax action.
	 * @param {Object}  data    Request data.
	 * @param {boolean} passive Background refreshes must not block clicks
	 *                          on the sidebar while in flight.
	 */
	function ajax( action, data, passive ) {
		var body = new window.FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data ).forEach( function ( key ) {
			if ( Array.isArray( data[ key ] ) ) {
				data[ key ].forEach( function ( value ) {
					body.append( key + '[]', value );
				} );
			} else {
				body.append( key, data[ key ] );
			}
		} );

		if ( ! passive ) {
			state.busy = true;
			if ( container ) {
				container.classList.add( 'pmf-busy' );
			}
		}

		return window
			.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( ( json && json.data && json.data.message ) || cfg.i18n.genericError );
				}
				if ( json.data && json.data.tree ) {
					state.tree = json.data.tree;
				}
				return json.data;
			} )
			.finally( function () {
				if ( ! passive ) {
					state.busy = false;
					if ( container ) {
						container.classList.remove( 'pmf-busy' );
					}
				}
			} );
	}

	// Row paths ride on a data attribute so a single delegated listener on
	// the stable list container handles activation — per-row listeners die
	// when a background render replaces the row mid-click.
	var TARGET_ALL = '::all::';
	var TARGET_ROOT = '::root::';

	function pathToTarget( path ) {
		return path === null ? TARGET_ALL : path === '' ? TARGET_ROOT : path;
	}

	function targetToPath( value ) {
		return value === TARGET_ALL ? null : value === TARGET_ROOT ? '' : value;
	}

	// ---- Search -------------------------------------------------------------

	/**
	 * Return the subset of nodes whose name (or a descendant's name) matches
	 * the query, with non-matching branches pruned.
	 */
	function filterNodes( nodes, query ) {
		var out = [];

		nodes.forEach( function ( node ) {
			var children = filterNodes( node.children, query );
			var selfMatch = node.name.toLowerCase().indexOf( query ) !== -1;

			if ( selfMatch || children.length ) {
				out.push( {
					name: node.name,
					path: node.path,
					count: node.count,
					children: children,
					selfMatch: selfMatch,
				} );
			}
		} );

		return out;
	}

	function firstMatch( nodes ) {
		for ( var i = 0; i < nodes.length; i++ ) {
			if ( nodes[ i ].selfMatch ) {
				return nodes[ i ];
			}
			var deeper = firstMatch( nodes[ i ].children );
			if ( deeper ) {
				return deeper;
			}
		}
		return null;
	}

	/**
	 * Build the folder name with the matching substring wrapped in <mark>,
	 * using text nodes only.
	 */
	function highlightedName( name, query ) {
		var span = el( 'span', 'pmf-name' );
		var index = name.toLowerCase().indexOf( query );

		if ( ! query || index === -1 ) {
			span.textContent = name;
			return span;
		}

		span.appendChild( document.createTextNode( name.slice( 0, index ) ) );
		span.appendChild( el( 'mark', null, name.slice( index, index + query.length ) ) );
		span.appendChild( document.createTextNode( name.slice( index + query.length ) ) );
		return span;
	}

	// ---- Filtering the media library ---------------------------------------

	function setGridFolderProp( path ) {
		if ( ! window.wp || ! wp.media || ! wp.media.frame ) {
			return false;
		}
		var content = wp.media.frame.content.get();
		if ( ! content || ! content.collection ) {
			return false;
		}
		content.collection.props.set( {
			pmf_folder: path === null ? null : path === '' ? ROOT : path,
		} );
		return true;
	}

	function applyFilter( path ) {
		state.selected = path;

		if ( mode === 'grid' ) {
			// The frame's content view can be mid-swap; retry once rather
			// than silently dropping the click.
			if ( ! setGridFolderProp( path ) ) {
				window.setTimeout( function () {
					setGridFolderProp( path );
				}, 350 );
			}
			render();
			return;
		}

		if ( mode === 'list' ) {
			var url = new window.URL( window.location.href );
			if ( path === null ) {
				url.searchParams.delete( 'pmf_folder' );
			} else {
				url.searchParams.set( 'pmf_folder', path === '' ? ROOT : path );
			}
			url.searchParams.delete( 'paged' );
			loadListPage( url.toString(), true );
		}
	}

	var listRequestSeq = 0;

	/**
	 * Refresh the list table in place instead of navigating: fetch the
	 * filtered page, swap the table markup, and push the URL so back /
	 * forward and bookmarks keep working. Falls back to a normal
	 * navigation if anything goes wrong.
	 *
	 * @param {string}  url  Filtered upload.php URL.
	 * @param {boolean} push Whether to push a history entry.
	 */
	function loadListPage( url, push ) {
		var form = document.querySelector( 'form#posts-filter' );
		if ( ! form || ! window.fetch || ! window.DOMParser ) {
			window.location.href = url;
			return;
		}

		var seq = ++listRequestSeq;
		var activeId = document.activeElement ? document.activeElement.id : '';

		form.classList.add( 'pmf-busy' );

		window
			.fetch( url, { credentials: 'same-origin' } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( response.status );
				}
				return response.text();
			} )
			.then( function ( html ) {
				if ( seq !== listRequestSeq ) {
					return; // A newer request superseded this one.
				}

				var doc = new window.DOMParser().parseFromString( html, 'text/html' );
				var next = doc.querySelector( 'form#posts-filter' );
				if ( ! next ) {
					throw new Error( 'unexpected response' );
				}

				form.innerHTML = next.innerHTML;

				var nextSub = doc.querySelector( 'ul.subsubsub' );
				var currentSub = document.querySelector( 'ul.subsubsub' );
				if ( nextSub && currentSub ) {
					currentSub.innerHTML = nextSub.innerHTML;
				}

				if ( push ) {
					window.history.pushState( { pmfFolder: state.selected }, '', url );
				}

				// The loaded URL is the source of truth for the selection.
				var raw = new window.URL( url, window.location.origin ).searchParams.get( 'pmf_folder' );
				state.selected = raw === null ? null : raw === ROOT ? '' : raw;
				updateAddNewLink();
				render();

				// The swap replaces the focused control (e.g. the search
				// box mid-typing); give focus back with the caret at the end.
				if ( activeId ) {
					var refocus = document.getElementById( activeId );
					if ( refocus ) {
						refocus.focus();
						if ( refocus.setSelectionRange && 'string' === typeof refocus.value ) {
							try {
								refocus.setSelectionRange( refocus.value.length, refocus.value.length );
							} catch ( e ) {
								/* Non-text inputs; focus alone is enough. */
							}
						}
					}
				}

				form.classList.remove( 'pmf-busy' );
			} )
			.catch( function () {
				window.location.href = url;
			} );
	}

	/**
	 * Make the list view's own controls async as well: the date and folder
	 * dropdowns apply on change, search filters as you type, and
	 * pagination / view / sort links swap in place. Bulk actions still
	 * submit normally (their redirect + notice flow needs a real request).
	 */
	function bindListAsync() {
		var form = document.querySelector( 'form#posts-filter' );
		if ( ! form ) {
			return;
		}

		function filteredUrl() {
			var url = new window.URL( window.location.href );
			url.searchParams.set( 'mode', 'list' );

			var month = form.querySelector( 'select[name="m"]' );
			var mime = form.querySelector( 'select[name="attachment-filter"]' );
			var folder = form.querySelector( 'select[name="pmf_folder"]' );
			var search = form.querySelector( 'input[name="s"]' );

			if ( month && month.value && '0' !== month.value ) {
				url.searchParams.set( 'm', month.value );
			} else {
				url.searchParams.delete( 'm' );
			}
			if ( mime && mime.value ) {
				url.searchParams.set( 'attachment-filter', mime.value );
			} else {
				url.searchParams.delete( 'attachment-filter' );
			}
			if ( folder && folder.value ) {
				url.searchParams.set( 'pmf_folder', folder.value );
			} else {
				url.searchParams.delete( 'pmf_folder' );
			}
			if ( search && search.value ) {
				url.searchParams.set( 's', search.value );
			} else {
				url.searchParams.delete( 's' );
			}

			url.searchParams.delete( 'paged' );
			return url.toString();
		}

		// Filter and search submits load in place; bulk actions do not.
		form.addEventListener( 'submit', function ( event ) {
			var submitter = event.submitter;
			if ( submitter && ( 'doaction' === submitter.id || 'doaction2' === submitter.id || 'delete_all' === submitter.name ) ) {
				return;
			}
			event.preventDefault();
			loadListPage( filteredUrl(), true );
		} );

		// Dropdowns apply immediately, like the grid.
		form.addEventListener( 'change', function ( event ) {
			if ( event.target.matches( 'select[name="m"], select[name="pmf_folder"], select[name="attachment-filter"]' ) ) {
				loadListPage( filteredUrl(), true );
			}
		} );

		// Search as you type, debounced.
		var searchTimer = null;
		form.addEventListener( 'input', function ( event ) {
			if ( 'media-search-input' !== event.target.id ) {
				return;
			}
			window.clearTimeout( searchTimer );
			searchTimer = window.setTimeout( function () {
				loadListPage( filteredUrl(), true );
			}, 450 );
		} );

		// Pagination and column-sort links swap in place.
		form.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( '.tablenav-pages a, th.sortable a, th.sorted a' );
			if ( link ) {
				event.preventDefault();
				loadListPage( link.href, true );
			}
		} );

		// Views (All | Images | Mine …) swap in place too.
		var sub = document.querySelector( 'ul.subsubsub' );
		if ( sub ) {
			sub.addEventListener( 'click', function ( event ) {
				var link = event.target.closest( 'a' );
				if ( link ) {
					event.preventDefault();
					loadListPage( link.href, true );
				}
			} );
		}
	}

	/**
	 * Point the Add Media File button at the current folder so the upload
	 * screen preselects it.
	 */
	function updateAddNewLink() {
		var addNew = document.querySelector( 'a.page-title-action[href*="media-new.php"]' );
		if ( ! addNew ) {
			return;
		}
		var url = new window.URL( addNew.href );
		if ( state.selected === null ) {
			url.searchParams.delete( 'pmf_folder' );
		} else {
			url.searchParams.set( 'pmf_folder', state.selected === '' ? ROOT : state.selected );
		}
		addNew.href = url.toString();
	}

	function refreshGrid() {
		if ( mode === 'grid' && window.wp && wp.media && wp.media.frame ) {
			var content = wp.media.frame.content.get();
			if ( content && content.collection ) {
				content.collection.props.set( { pmf_refresh: Date.now() } );
			}
		} else if ( mode === 'list' ) {
			window.location.reload();
		}
	}

	// ---- Folder operations --------------------------------------------------

	function moveAttachments( ids, targetPath ) {
		ajax( 'pmf_move_attachments', {
			ids: ids,
			target: targetPath === '' ? ROOT : targetPath,
		} )
			.then( function ( data ) {
				var message = cfg.i18n.movedFiles.replace( '%d', data.moved );
				if ( data.errors && data.errors.length ) {
					message += ' ' + data.errors.join( ' ' );
				}
				notify( message, data.errors && data.errors.length > 0 );
				render();
				refreshGrid();
			} )
			.catch( function ( error ) {
				notify( error.message, true );
			} );
	}

	function folderOp( op, path, to ) {
		return ajax( 'pmf_folder_op', { op: op, path: path, to: to || '' } )
			.then( function () {
				render();
				refreshGrid();
			} )
			.catch( function ( error ) {
				notify( error.message, true );
				render();
			} );
	}

	// ---- Drag and drop ------------------------------------------------------

	// A folder-row drag that never travels anywhere is really a click that
	// the browser promoted to a drag (easy to do on a trackpad); tracked so
	// dragend can recover it.
	var folderDragInfo = null;
	var folderDragDropped = false;

	function getDragPayload( event ) {
		var types = event.dataTransfer.types || [];
		if ( types.indexOf( 'pmf/attachments' ) !== -1 ) {
			return { kind: 'attachments' };
		}
		if ( types.indexOf( 'pmf/folder' ) !== -1 ) {
			return { kind: 'folder' };
		}
		return null;
	}

	function bindRowDrop( row, path ) {
		var expandTimer = null;

		row.addEventListener( 'dragover', function ( event ) {
			if ( ! getDragPayload( event ) ) {
				return;
			}
			event.preventDefault();
			event.dataTransfer.dropEffect = 'move';
			row.classList.add( 'pmf-drop-target' );
		} );

		row.addEventListener( 'dragenter', function ( event ) {
			if ( ! getDragPayload( event ) || path === '' ) {
				return;
			}
			// Spring-loaded folders: expand while hovering during a drag.
			expandTimer = window.setTimeout( function () {
				if ( ! state.expanded.has( path ) ) {
					state.expanded.add( path );
					saveExpanded();
					render();
				}
			}, 700 );
		} );

		row.addEventListener( 'dragleave', function () {
			row.classList.remove( 'pmf-drop-target' );
			window.clearTimeout( expandTimer );
		} );

		row.addEventListener( 'drop', function ( event ) {
			event.preventDefault();
			row.classList.remove( 'pmf-drop-target' );
			window.clearTimeout( expandTimer );
			folderDragDropped = true;

			var attachments = event.dataTransfer.getData( 'pmf/attachments' );
			if ( attachments ) {
				var ids = JSON.parse( attachments );
				if ( ids.length ) {
					moveAttachments( ids, path );
				}
				return;
			}

			var folder = event.dataTransfer.getData( 'pmf/folder' );
			if ( folder && cfg.canManage ) {
				if ( folder === path || isDescendantPath( path, folder ) ) {
					notify( cfg.i18n.cannotMoveIntoSelf, true );
					return;
				}
				var name = folder.split( '/' ).pop();
				var destination = path === '' ? name : path + '/' + name;
				if ( destination === folder ) {
					return;
				}
				folderOp( 'rename', folder, destination ).then( function () {
					notify( cfg.i18n.folderMoved, false );
				} );
			}
		} );
	}

	/**
	 * Mark every grid tile as draggable. Grid tiles hold no selectable
	 * text, so the attribute can be set proactively (list rows keep the
	 * mousedown approach to preserve text selection).
	 */
	function markGridTilesDraggable() {
		document.querySelectorAll( '.attachments-browser .attachment:not([draggable="true"])' ).forEach( function ( tile ) {
			tile.draggable = true;
		} );
	}

	// Attachments in the grid and rows in the list table become draggable on
	// mousedown (draggable must be set before dragstart can fire).
	function bindSourceDragging() {
		document.addEventListener( 'mousedown', function ( event ) {
			var item = event.target.closest( '.attachments-browser .attachment, #the-list tr' );
			if ( item ) {
				item.draggable = true;
			}
		} );

		// List rows must not STAY draggable — it blocks selecting filename
		// text. Reset after the gesture ends (mouseup for clicks, dragend
		// for drags; mouseup does not fire during a native drag).
		var resetListRowDraggable = function () {
			document.querySelectorAll( '#the-list tr[draggable="true"]' ).forEach( function ( row ) {
				row.draggable = false;
			} );
		};
		document.addEventListener( 'mouseup', resetListRowDraggable );

		document.addEventListener( 'dragstart', function ( event ) {
			if ( ! event.target.closest ) {
				return;
			}

			// Folder-row drags also suppress the core upload overlay.
			if ( event.target.closest( '.pmf-tree-sidebar .pmf-row' ) ) {
				document.body.classList.add( 'pmf-dragging' );
				return;
			}

			var ids = [];
			var gridItem = event.target.closest( '.attachments-browser .attachment' );
			var listRow = event.target.closest( '#the-list tr' );

			if ( gridItem ) {
				var selected = document.querySelectorAll( '.attachments-browser .attachment.selected' );
				if ( gridItem.classList.contains( 'selected' ) && selected.length > 1 ) {
					selected.forEach( function ( node ) {
						ids.push( parseInt( node.getAttribute( 'data-id' ), 10 ) );
					} );
				} else {
					ids.push( parseInt( gridItem.getAttribute( 'data-id' ), 10 ) );
				}
			} else if ( listRow && listRow.id.indexOf( 'post-' ) === 0 ) {
				var rowId = parseInt( listRow.id.replace( 'post-', '' ), 10 );
				var checked = document.querySelectorAll( '#the-list input[name="media[]"]:checked' );
				var checkedIds = Array.prototype.map.call( checked, function ( input ) {
					return parseInt( input.value, 10 );
				} );
				ids = checkedIds.indexOf( rowId ) !== -1 && checkedIds.length > 1 ? checkedIds : [ rowId ];
			} else {
				return;
			}

			ids = ids.filter( function ( id ) {
				return ! isNaN( id ) && id > 0;
			} );
			if ( ! ids.length ) {
				return;
			}

			event.dataTransfer.setData( 'pmf/attachments', JSON.stringify( ids ) );
			event.dataTransfer.effectAllowed = 'move';
			document.body.classList.add( 'pmf-dragging' );
		} );

		document.addEventListener( 'dragend', function ( event ) {
			document.body.classList.remove( 'pmf-dragging' );
			resetListRowDraggable();

			// Recover clicks the browser promoted to drags: the "drag"
			// ended near where it started without ever being dropped.
			if ( folderDragInfo && ! folderDragDropped ) {
				var dx = Math.abs( ( event.screenX || 0 ) - folderDragInfo.x );
				var dy = Math.abs( ( event.screenY || 0 ) - folderDragInfo.y );
				if ( dx < 8 && dy < 8 ) {
					applyFilter( folderDragInfo.path );
				}
			}
			folderDragInfo = null;
			folderDragDropped = false;
		} );
	}

	// ---- Inline editing -----------------------------------------------------

	function startInlineInput( row, initialValue, placeholder, onCommit ) {
		var nameEl = row.querySelector( '.pmf-name' );
		var input = el( 'input', 'pmf-inline-input' );
		input.type = 'text';
		input.value = initialValue;
		input.placeholder = placeholder || '';
		input.setAttribute( 'aria-label', placeholder || cfg.i18n.folderName );

		nameEl.style.display = 'none';
		nameEl.parentNode.insertBefore( input, nameEl );
		input.focus();
		input.select();

		var done = false;
		function finish( commit ) {
			if ( done ) {
				return;
			}
			done = true;
			var value = input.value.trim();
			input.remove();
			nameEl.style.display = '';
			if ( commit && value && value !== initialValue ) {
				onCommit( value );
			} else {
				render();
			}
		}

		input.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' ) {
				event.preventDefault();
				finish( true );
			} else if ( event.key === 'Escape' ) {
				finish( false );
			}
		} );
		input.addEventListener( 'blur', function () {
			finish( false );
		} );
		// Prevent row click/drag while typing.
		[ 'click', 'mousedown', 'dragstart' ].forEach( function ( type ) {
			input.addEventListener( type, function ( event ) {
				event.stopPropagation();
			} );
		} );
	}

	function armDeleteButton( button, path ) {
		if ( button.dataset.armed ) {
			folderOp( 'delete', path ).then( function () {
				notify( cfg.i18n.folderDeleted, false );
			} );
			return;
		}
		button.dataset.armed = '1';
		button.textContent = cfg.i18n.confirmShort;
		button.classList.add( 'pmf-armed' );
		window.setTimeout( function () {
			delete button.dataset.armed;
			button.textContent = '×';
			button.classList.remove( 'pmf-armed' );
		}, 3000 );
	}

	// ---- Rendering ----------------------------------------------------------

	function buildActionButtons( row, node ) {
		var actions = el( 'span', 'pmf-actions' );

		var addBtn = el( 'button', 'pmf-action', '+' );
		addBtn.type = 'button';
		addBtn.title = cfg.i18n.newSubfolder;
		addBtn.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
			state.expanded.add( node.path );
			saveExpanded();
			startInlineInput( row, '', cfg.i18n.newFolderName, function ( value ) {
				folderOp( 'create', node.path === '' ? value : node.path + '/' + value );
			} );
		} );
		actions.appendChild( addBtn );

		if ( node.path !== '' ) {
			var renameBtn = el( 'button', 'pmf-action', '✎' );
			renameBtn.type = 'button';
			renameBtn.title = cfg.i18n.rename;
			renameBtn.addEventListener( 'click', function ( event ) {
				event.stopPropagation();
				startInlineInput( row, node.name, cfg.i18n.folderName, function ( value ) {
					var parent = node.path.indexOf( '/' ) !== -1 ? node.path.slice( 0, node.path.lastIndexOf( '/' ) ) : '';
					folderOp( 'rename', node.path, parent === '' ? value : parent + '/' + value );
				} );
			} );
			actions.appendChild( renameBtn );

			var deleteBtn = el( 'button', 'pmf-action pmf-action-delete', '×' );
			deleteBtn.type = 'button';
			deleteBtn.title = cfg.i18n.deleteFolder;
			deleteBtn.addEventListener( 'click', function ( event ) {
				event.stopPropagation();
				armDeleteButton( deleteBtn, node.path );
			} );
			actions.appendChild( deleteBtn );
		}

		return actions;
	}

	function buildNode( node, depth, searching ) {
		var li = el( 'li', 'pmf-node' );
		var hasChildren = node.children && node.children.length > 0;
		var isExpanded = searching || state.expanded.has( node.path );

		var row = el( 'div', 'pmf-row' );
		row.style.paddingLeft = 8 + depth * 16 + 'px';
		row.dataset.pmfTarget = pathToTarget( node.path );
		if ( ! searching && hasChildren ) {
			row.dataset.pmfToggle = '1';
		}
		if ( state.selected === node.path ) {
			row.classList.add( 'pmf-selected' );
		}

		var caret = el( 'button', 'pmf-caret' + ( hasChildren && ! searching ? '' : ' pmf-caret-empty' ) );
		caret.type = 'button';
		caret.setAttribute( 'aria-expanded', isExpanded ? 'true' : 'false' );
		caret.textContent = hasChildren ? ( isExpanded ? '▾' : '▸' ) : '';
		row.appendChild( caret );

		row.appendChild( el( 'span', 'pmf-icon', '📁' ) );
		row.appendChild( highlightedName( node.name, searching ? state.query : '' ) );
		row.appendChild( el( 'span', 'pmf-count', String( node.count ) ) );

		if ( cfg.canManage ) {
			row.appendChild( buildActionButtons( row, node ) );
		}

		if ( cfg.canManage ) {
			row.draggable = true;
			row.addEventListener( 'dragstart', function ( event ) {
				event.stopPropagation();
				event.dataTransfer.setData( 'pmf/folder', node.path );
				event.dataTransfer.effectAllowed = 'move';
				folderDragInfo = { path: node.path, x: event.screenX, y: event.screenY };
				folderDragDropped = false;
			} );
		}

		bindRowDrop( row, node.path );
		li.appendChild( row );

		if ( hasChildren && isExpanded ) {
			var children = el( 'ul', 'pmf-children' );
			node.children.forEach( function ( child ) {
				children.appendChild( buildNode( child, depth + 1, searching ) );
			} );
			li.appendChild( children );
		}

		return li;
	}

	function buildSpecialRow( label, path, count, droppable, extraClass ) {
		var li = el( 'li', 'pmf-node' );
		var row = el( 'div', 'pmf-row pmf-row-special' + ( extraClass ? ' ' + extraClass : '' ) );
		row.style.paddingLeft = '8px';
		row.dataset.pmfTarget = pathToTarget( path );
		if ( state.selected === path ) {
			row.classList.add( 'pmf-selected' );
		}

		row.appendChild( el( 'span', 'pmf-caret pmf-caret-empty' ) );
		row.appendChild( el( 'span', 'pmf-icon', path === null ? '🗂' : '🏠' ) );
		row.appendChild( el( 'span', 'pmf-name', label ) );
		if ( count !== null ) {
			row.appendChild( el( 'span', 'pmf-count', String( count ) ) );
		}

		if ( droppable ) {
			bindRowDrop( row, path );
		}

		li.appendChild( row );
		return li;
	}

	function render() {
		if ( ! listHost ) {
			return;
		}
		listHost.innerHTML = '';

		var searching = state.query.length > 0;
		var nodes = searching ? filterNodes( state.tree.folders, state.query ) : state.tree.folders;
		var list = el( 'ul', 'pmf-tree-list' );

		if ( ! searching ) {
			list.appendChild( buildSpecialRow( cfg.i18n.allFiles, null, null, false ) );
			list.appendChild( buildSpecialRow( cfg.i18n.uploadsRoot, '', state.tree.root_count, true, 'pmf-root-row' ) );
		}

		nodes.forEach( function ( node ) {
			list.appendChild( buildNode( node, searching ? 0 : 1, searching ) );
		} );

		if ( searching && ! nodes.length ) {
			listHost.appendChild( el( 'p', 'pmf-no-results', cfg.i18n.noMatches ) );
		}

		listHost.appendChild( list );
	}

	function buildChrome() {
		var header = el( 'div', 'pmf-tree-header' );
		header.appendChild( el( 'strong', null, cfg.i18n.heading ) );

		var actions = el( 'span', 'pmf-header-actions' );

		var collapseBtn = el( 'button', 'button button-small pmf-collapse-all', '⌃' );
		collapseBtn.type = 'button';
		collapseBtn.title = cfg.i18n.collapseAll;
		collapseBtn.setAttribute( 'aria-label', cfg.i18n.collapseAll );
		collapseBtn.addEventListener( 'click', function () {
			state.expanded.clear();
			saveExpanded();
			render();
		} );
		actions.appendChild( collapseBtn );
		header.appendChild( actions );

		if ( cfg.canManage ) {
			var newBtn = el( 'button', 'button button-small pmf-new-root', cfg.i18n.newFolder );
			newBtn.type = 'button';
			newBtn.addEventListener( 'click', function () {
				if ( state.query ) {
					searchInput.value = '';
					state.query = '';
					render();
				}
				var rootRow = listHost.querySelector( '.pmf-root-row' );
				if ( rootRow ) {
					startInlineInput( rootRow, '', cfg.i18n.newFolderName, function ( value ) {
						folderOp( 'create', value );
					} );
				}
			} );
			actions.appendChild( newBtn );
		}
		container.appendChild( header );

		var searchWrap = el( 'div', 'pmf-tree-search' );
		searchInput = el( 'input', 'pmf-search-input' );
		searchInput.type = 'search';
		searchInput.placeholder = cfg.i18n.searchFolders;
		searchInput.setAttribute( 'aria-label', cfg.i18n.searchFolders );

		searchInput.addEventListener( 'input', function () {
			state.query = searchInput.value.trim().toLowerCase();
			render();
		} );

		searchInput.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' ) {
				event.preventDefault();
				var match = firstMatch( filterNodes( state.tree.folders, state.query ) );
				if ( match ) {
					applyFilter( match.path );
				}
			} else if ( event.key === 'Escape' ) {
				searchInput.value = '';
				state.query = '';
				render();
			}
		} );

		searchWrap.appendChild( searchInput );
		container.appendChild( searchWrap );

		noticeEl = el( 'div', 'pmf-tree-notice' );
		noticeEl.style.display = 'none';
		container.appendChild( noticeEl );

		// Shown only while dragging files (body.pmf-dragging).
		container.appendChild( el( 'div', 'pmf-drop-hint', cfg.i18n.dropHint ) );

		listHost = el( 'div', 'pmf-tree-body' );
		container.appendChild( listHost );

		// One delegated listener on the stable container: it survives
		// background re-renders that replace rows mid-click, and it still
		// resolves when mousedown and mouseup land on different nodes.
		listHost.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '.pmf-action, .pmf-inline-input' ) ) {
				return; // Direct handlers own these.
			}

			var row = event.target.closest( '.pmf-row' );
			if ( ! row || ! listHost.contains( row ) || ! ( 'pmfTarget' in row.dataset ) ) {
				return;
			}

			var path = targetToPath( row.dataset.pmfTarget );

			if ( event.target.closest( '.pmf-caret' ) && row.dataset.pmfToggle ) {
				if ( state.expanded.has( path ) ) {
					state.expanded.delete( path );
				} else {
					state.expanded.add( path );
				}
				saveExpanded();
				render();
				return;
			}

			applyFilter( path );
		} );
	}

	/**
	 * The list view has a visible "Search Media" button; give the grid the
	 * same control. The grid already searches as you type, so the button
	 * simply applies whatever is in the box.
	 */
	function ensureGridSearchButton() {
		var toolbar = document.querySelector( '#wp-media-grid .media-toolbar-primary' );
		if ( ! toolbar || toolbar.querySelector( '.pmf-search-button' ) ) {
			return;
		}

		var input = toolbar.querySelector( 'input[type="search"]' );
		if ( ! input ) {
			return;
		}

		var button = el( 'button', 'button pmf-search-button', cfg.i18n.searchMedia );
		button.type = 'button';
		button.addEventListener( 'click', function () {
			if ( window.wp && wp.media && wp.media.frame ) {
				var content = wp.media.frame.content.get();
				if ( content && content.collection ) {
					content.collection.props.set( { search: input.value || '' } );
				}
			}
		} );

		input.insertAdjacentElement( 'afterend', button );
	}

	// ---- Uploads ------------------------------------------------------------

	/**
	 * Send the currently selected folder with every upload so new files
	 * land directly in it (server-side upload_dir filter reads pmf_folder),
	 * and refresh the tree counts when a batch finishes.
	 */
	function bindUploaderFolder() {
		if ( ! window.wp || typeof wp.Uploader !== 'function' ) {
			return;
		}

		var originalInit = wp.Uploader.prototype.init;

		wp.Uploader.prototype.init = function () {
			if ( originalInit ) {
				originalInit.apply( this, arguments );
			}
			this.uploader.bind( 'BeforeUpload', function ( up ) {
				up.settings.multipart_params.pmf_folder =
					state.selected === null ? '' : state.selected === '' ? ROOT : state.selected;
			} );
			this.uploader.bind( 'UploadComplete', function ( up, files ) {
				var ok = ( files || [] ).filter( function ( f ) {
					return f.status === window.plupload.DONE;
				} ).length;
				if ( ok ) {
					notify( cfg.i18n.uploadedFiles.replace( '%d', ok ), false );
				}
				// Counts changed on the server; re-render with fresh data.
				ajax( 'pmf_tree', {}, true )
					.then( render )
					.catch( function () {} );
			} );
		};
	}

	/**
	 * Core only lets a media query watch the upload queue when its args are
	 * on a small allowlist, so a query filtered by pmf_folder never shows
	 * new uploads until reload. Re-enable observation when the only extra
	 * args are ours — uploads land in the selected folder, so they belong
	 * in the filtered view.
	 */
	function patchQueryObservation() {
		if ( ! window.wp || ! wp.media || ! wp.media.model || ! wp.media.model.Query || ! wp.Uploader ) {
			return;
		}

		var coreAllowed = [ 's', 'order', 'orderby', 'posts_per_page', 'post_mime_type', 'post_parent', 'author' ];
		var ourKeys = [ 'pmf_folder', 'pmf_refresh' ];
		var proto = wp.media.model.Query.prototype;
		var originalInit = proto.initialize;

		proto.initialize = function ( models, options ) {
			originalInit.apply( this, arguments );

			if ( ! this.args ) {
				return;
			}

			var keys = Object.keys( this.args );
			var hasOurs = keys.some( function ( key ) {
				return ourKeys.indexOf( key ) !== -1;
			} );
			var foreign = keys.filter( function ( key ) {
				return coreAllowed.indexOf( key ) === -1 && ourKeys.indexOf( key ) === -1;
			} );
			var alreadyObserving = ( this.observers || [] ).indexOf( wp.Uploader.queue ) !== -1;

			if ( hasOurs && ! foreign.length && ! alreadyObserving ) {
				this.observe( wp.Uploader.queue );
			}
		};
	}

	// ---- Mount --------------------------------------------------------------

	function mount() {
		var grid = document.getElementById( 'wp-media-grid' );
		var listForm = document.querySelector( 'body.upload-php form#posts-filter' );

		container = el( 'div', 'pmf-tree-sidebar' );

		if ( grid ) {
			mode = 'grid';
			// WordPress appends the media frame as a direct child of
			// #wp-media-grid (after this script runs). Flexing the wrap
			// itself would drag the heading and notices into the row, so
			// build a two-column layout at the end of the wrap and adopt
			// the frame into it once it appears.
			var gridLayout = el( 'div', 'pmf-layout' );
			var frameHost = el( 'div', 'pmf-frame-host' );
			gridLayout.appendChild( container );
			gridLayout.appendChild( frameHost );
			grid.appendChild( gridLayout );

			var adoptFrame = function () {
				var frame = grid.querySelector( ':scope > .media-frame' );
				if ( frame ) {
					frameHost.appendChild( frame );
				}
				return !! frame;
			};

			if ( ! adoptFrame() ) {
				var observer = new window.MutationObserver( function () {
					if ( adoptFrame() ) {
						observer.disconnect();
					}
				} );
				observer.observe( grid, { childList: true } );
			}

			// Keep the Search Media button and tile draggability applied
			// across grid re-renders.
			ensureGridSearchButton();
			markGridTilesDraggable();
			new window.MutationObserver( function () {
				ensureGridSearchButton();
				markGridTilesDraggable();
			} ).observe( frameHost, { childList: true, subtree: true } );
		} else if ( listForm ) {
			mode = 'list';
			var layout = el( 'div', 'pmf-layout' );
			listForm.parentNode.insertBefore( layout, listForm );
			layout.appendChild( container );
			layout.appendChild( listForm );
		} else {
			return;
		}

		// Expand ancestors of the current selection so it is visible.
		if ( state.selected ) {
			var parts = state.selected.split( '/' );
			var path = '';
			parts.forEach( function ( part ) {
				path = path === '' ? part : path + '/' + part;
				state.expanded.add( path );
			} );
			saveExpanded();
		}

		// Keep back/forward working with the in-place list refresh, and
		// make the list view's own filter/search controls async too.
		if ( mode === 'list' ) {
			updateAddNewLink();
			bindListAsync();
			window.addEventListener( 'popstate', function () {
				loadListPage( window.location.href, false );
			} );
		}

		buildChrome();
		render();
		bindSourceDragging();
		bindUploaderFolder();
		patchQueryObservation();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
} )();
