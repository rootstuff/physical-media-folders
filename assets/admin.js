/**
 * Physical Media Folders admin JS.
 *
 * Powers the folder experience inside wp.media views: the searchable
 * folder filter dropdown, folder-aware uploads from the media modal
 * (including Gutenberg's image block "Media Library" flow), an inline
 * "New folder" control, and upload-queue observation for folder-filtered
 * queries. Loads on the media library and everywhere the modal appears.
 */
( function () {
	'use strict';

	if ( ! window.wp || ! wp.media || ! window.pmfMedia ) {
		return;
	}

	/**
	 * Folder choices for the dropdown. On the library screen the full tree
	 * already ships with pmf-tree, so choices are derived from it; other
	 * screens (the post editor's media modal) receive a flat list instead.
	 */
	function folderChoices() {
		var choices = pmfMedia.choices || {};

		if ( Object.keys( choices ).length ) {
			return choices;
		}

		choices = {};
		choices[ '' ] = pmfMedia.allLabel;
		choices[ '/' ] = pmfMedia.rootLabel;

		if ( window.pmfTree && pmfTree.tree ) {
			( function walk( nodes, depth ) {
				nodes.forEach( function ( node ) {
					choices[ node.path ] = new Array( depth + 1 ).join( '— ' ) + node.name;
					walk( node.children, depth + 1 );
				} );
			} )( pmfTree.tree.folders, 0 );
		}

		return choices;
	}

	var FolderFilter = wp.media.view.AttachmentFilters.extend( {
		id: 'pmf-media-folder-filter',

		createFilters: function () {
			var filters = {};
			var choices = folderChoices();

			Object.keys( choices ).forEach( function ( value ) {
				filters[ value || 'all' ] = {
					text: choices[ value ],
					props: { pmf_folder: value || null },
				};
			} );

			this.filters = filters;
		},
	} );

	/**
	 * Inline "New folder" control for the modal toolbar: click, type a
	 * name, Enter. The folder is created inside the currently selected
	 * folder and becomes the active filter (and upload destination).
	 */
	var NewFolderButton = wp.media.View.extend( {
		tagName: 'button',
		className: 'button media-button pmf-modal-new-folder',

		events: { click: 'onClick' },

		render: function () {
			this.$el.attr( 'type', 'button' ).text( pmfMedia.i18n.newFolder );
			return this;
		},

		onClick: function ( event ) {
			event.preventDefault();

			var view = this;
			if ( this.$el.next( '.pmf-modal-folder-input' ).length ) {
				return;
			}

			var input = document.createElement( 'input' );
			input.type = 'text';
			input.className = 'pmf-modal-folder-input';
			input.placeholder = pmfMedia.i18n.folderName;
			this.el.insertAdjacentElement( 'afterend', input );
			input.focus();

			function remove() {
				input.remove();
			}

			input.addEventListener( 'keydown', function ( e ) {
				if ( 'Escape' === e.key ) {
					remove();
				} else if ( 'Enter' === e.key ) {
					e.preventDefault();
					var name = input.value.trim();
					if ( name ) {
						view.createFolder( name, remove );
					} else {
						remove();
					}
				}
			} );
			input.addEventListener( 'blur', function () {
				window.setTimeout( remove, 150 );
			} );
		},

		createFolder: function ( name, done ) {
			var filterView = this.options.folderFilter;
			var select = filterView.el;
			var parent = select.value && '/' !== select.value ? select.value : '';
			var path = parent ? parent + '/' + name : name;

			var body = new window.FormData();
			body.append( 'action', 'pmf_folder_op' );
			body.append( 'nonce', pmfMedia.nonce );
			body.append( 'op', 'create' );
			body.append( 'path', path );

			window
				.fetch( pmfMedia.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( json ) {
					if ( ! json || ! json.success ) {
						throw new Error( ( json && json.data && json.data.message ) || pmfMedia.i18n.genericError );
					}

					done();

					// Register the new folder with the filter view and
					// select it: the dropdown, the combobox enhancement,
					// and the upload destination all follow.
					var depth = path.split( '/' ).length - 1;
					var label = new Array( depth + 1 ).join( '— ' ) + name;
					filterView.filters[ path ] = { text: label, props: { pmf_folder: path } };

					var option = new window.Option( label, path );
					var parentOption = parent ? select.querySelector( 'option[value="' + window.CSS.escape( parent ) + '"]' ) : null;
					if ( parentOption ) {
						parentOption.insertAdjacentElement( 'afterend', option );
					} else {
						select.appendChild( option );
					}

					select.value = path;
					filterView.$el.trigger( 'change' );
				} )
				.catch( function ( error ) {
					done();
					var note = document.createElement( 'span' );
					note.className = 'pmf-modal-folder-error';
					note.textContent = error.message;
					view.el.insertAdjacentElement( 'afterend', note );
					window.setTimeout( function () {
						note.remove();
					}, 4000 );
				} );
		},
	} );

	var AttachmentsBrowser = wp.media.view.AttachmentsBrowser;

	wp.media.view.AttachmentsBrowser = AttachmentsBrowser.extend( {
		createToolbar: function () {
			AttachmentsBrowser.prototype.createToolbar.call( this );

			var folderFilter = new FolderFilter( {
				controller: this.controller,
				model: this.collection.props,
				priority: -75,
			} ).render();

			this.toolbar.set( 'pmfFolderFilter', folderFilter );

			if ( pmfMedia.canManage ) {
				this.toolbar.set(
					'pmfNewFolder',
					new NewFolderButton( {
						controller: this.controller,
						folderFilter: folderFilter,
						priority: -74,
					} ).render()
				);
			}
		},
	} );

	/**
	 * Core only lets a media query watch the upload queue when its args
	 * are on a small allowlist, so folder-filtered views never show new
	 * uploads until refresh. Re-enable observation when the only extra
	 * args are ours — uploads land in the selected folder, so they belong
	 * in the filtered view. (Shared by the library grid and the modal.)
	 */
	( function patchQueryObservation() {
		if ( ! wp.media.model || ! wp.media.model.Query || ! wp.Uploader ) {
			return;
		}

		var proto = wp.media.model.Query.prototype;
		if ( proto.__pmfObserves ) {
			return;
		}
		proto.__pmfObserves = true;

		var coreAllowed = [ 's', 'order', 'orderby', 'posts_per_page', 'post_mime_type', 'post_parent', 'author' ];
		var ourKeys = [ 'pmf_folder', 'pmf_refresh' ];
		var originalInit = proto.initialize;

		proto.initialize = function () {
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
	} )();

	/**
	 * Route uploads made through the media modal (e.g. Gutenberg's image
	 * block -> Media Library -> Upload files) into the folder selected in
	 * the modal's dropdown. On upload.php the tree script owns this.
	 */
	( function patchModalUploads() {
		if ( typeof wp.Uploader !== 'function' ) {
			return;
		}

		var originalInit = wp.Uploader.prototype.init;

		wp.Uploader.prototype.init = function () {
			if ( originalInit ) {
				originalInit.apply( this, arguments );
			}

			// The library screen's tree handles its own destination.
			if ( window.pmfTree ) {
				return;
			}

			this.uploader.bind( 'BeforeUpload', function ( up ) {
				up.settings.multipart_params.pmf_folder = activeModalFolder();
			} );
		};

		function activeModalFolder() {
			var selects = document.querySelectorAll( '#pmf-media-folder-filter, select[id="pmf-media-folder-filter"]' );
			for ( var i = selects.length - 1; i >= 0; i-- ) {
				var wrap = selects[ i ].closest( '.pmf-combobox' ) || selects[ i ];
				if ( wrap.offsetParent !== null ) {
					return selects[ i ].value || '';
				}
			}
			return '';
		}
	} )();
} )();
