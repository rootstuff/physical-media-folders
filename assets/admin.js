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
