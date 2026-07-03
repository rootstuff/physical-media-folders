/**
 * Physical Media Folders admin JS.
 *
 * Adds a folder filter dropdown to media views. In the library the tree
 * sidebar (tree.js) is the primary UI; this dropdown also covers the
 * media modal inside the post editor, where the sidebar is not present.
 */
( function () {
	'use strict';

	if ( ! window.wp || ! wp.media || ! window.pmfMedia ) {
		return;
	}

	var FolderFilter = wp.media.view.AttachmentFilters.extend( {
		id: 'pmf-media-folder-filter',

		createFilters: function () {
			var filters = {};

			Object.keys( pmfMedia.choices ).forEach( function ( value ) {
				filters[ value || 'all' ] = {
					text: pmfMedia.choices[ value ],
					props: { pmf_folder: value || null },
				};
			} );

			this.filters = filters;
		},
	} );

	var AttachmentsBrowser = wp.media.view.AttachmentsBrowser;

	wp.media.view.AttachmentsBrowser = AttachmentsBrowser.extend( {
		createToolbar: function () {
			AttachmentsBrowser.prototype.createToolbar.call( this );

			this.toolbar.set(
				'pmfFolderFilter',
				new FolderFilter( {
					controller: this.controller,
					model: this.collection.props,
					priority: -75,
				} ).render()
			);
		},
	} );
} )();
