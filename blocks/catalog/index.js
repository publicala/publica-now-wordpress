/**
 * Publica.now Catalog block — editor script.
 *
 * Plain JavaScript on the wp.* globals: no build step, nothing to bundle for
 * WordPress.org review. Title, attributes and supports come from block.json
 * (registered server-side); this file only supplies edit() and a null save()
 * because render.php produces the markup. The preview is the real server
 * render, so what the author sees is what visitors get.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.blockEditor || ! wp.components || ! wp.i18n ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var SelectControl = wp.components.SelectControl;
	var RangeControl = wp.components.RangeControl;
	var ToggleControl = wp.components.ToggleControl;
	var Placeholder = wp.components.Placeholder;
	var Notice = wp.components.Notice;
	var ServerSideRender = wp.serverSideRender;
	var apiFetch = wp.apiFetch;

	var BLOCK = 'publica-now/catalog';

	var CONTENT_TYPES = [
		{ value: '', label: __( 'All types', 'publica-now' ) },
		{ value: 'ebook', label: __( 'Ebooks', 'publica-now' ) },
		{ value: 'audiobook', label: __( 'Audiobooks', 'publica-now' ) },
		{ value: 'music', label: __( 'Music', 'publica-now' ) },
		{ value: 'video', label: __( 'Video', 'publica-now' ) },
		{ value: 'course', label: __( 'Courses', 'publica-now' ) },
		{ value: 'zine', label: __( 'Zines', 'publica-now' ) },
		{ value: 'photography', label: __( 'Photography', 'publica-now' ) },
		{ value: 'design', label: __( 'Design', 'publica-now' ) },
		{ value: 'print', label: __( 'Printed books', 'publica-now' ) }
	];

	var FREE_OPTIONS = [
		{ value: 'any', label: __( 'Free and paid', 'publica-now' ) },
		{ value: 'yes', label: __( 'Only free works', 'publica-now' ) },
		{ value: 'no', label: __( 'Only paid works', 'publica-now' ) }
	];

	var ORDER_OPTIONS = [
		{ value: 'newest', label: __( 'Newest first', 'publica-now' ) },
		{ value: 'oldest', label: __( 'Oldest first', 'publica-now' ) },
		{ value: 'title', label: __( 'Title A–Z', 'publica-now' ) },
		{ value: 'price_asc', label: __( 'Price: low to high', 'publica-now' ) },
		{ value: 'price_desc', label: __( 'Price: high to low', 'publica-now' ) }
	];

	var LAYOUT_OPTIONS = [
		{ value: 'grid', label: __( 'Grid', 'publica-now' ) },
		{ value: 'list', label: __( 'List', 'publica-now' ) }
	];

	/**
	 * Connection status from the plugin's own REST route: null while loading,
	 * { connected: null } when the request failed (e.g. a role without
	 * edit_posts) so the preview still renders and the server explains.
	 */
	function useStatus() {
		var state = useState( null );
		var status = state[ 0 ];
		var setStatus = state[ 1 ];

		useEffect( function () {
			var cancelled = false;

			if ( ! apiFetch ) {
				return undefined;
			}

			apiFetch( { path: '/publica-now/v1/status' } )
				.then( function ( response ) {
					if ( ! cancelled ) {
						setStatus( response && typeof response === 'object' ? response : { connected: false } );
					}
				} )
				.catch( function () {
					if ( ! cancelled ) {
						setStatus( { connected: null } );
					}
				} );

			return function () {
				cancelled = true;
			};
		}, [] );

		return status;
	}

	function Edit( props ) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var blockProps = useBlockProps();
		var status = useStatus();
		var notConnected = !! ( status && status.connected === false );

		/*
		 * Four attributes (layout, columns, show_excerpt, show_rating) have no
		 * default in block.json on purpose: undefined means "use the site
		 * default", which the server resolves at render time. The editor shows
		 * that same value, read from /publica-now/v1/status, so the control
		 * never disagrees with the preview.
		 */
		var siteDefaults = ( status && status.defaults ) || {};

		function resolved( key, fallback ) {
			if ( attributes[ key ] !== undefined && attributes[ key ] !== null && attributes[ key ] !== '' ) {
				return attributes[ key ];
			}
			return siteDefaults[ key ] !== undefined ? siteDefaults[ key ] : fallback;
		}

		var layout = resolved( 'layout', 'grid' );

		function set( key ) {
			return function ( value ) {
				var next = {};
				next[ key ] = value;
				setAttributes( next );
			};
		}

		function setNumber( key, fallback ) {
			return function ( value ) {
				var next = {};
				next[ key ] = typeof value === 'number' && ! isNaN( value ) ? value : fallback;
				setAttributes( next );
			};
		}

		var inspector = el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: __( 'Catalog', 'publica-now' ), initialOpen: true },
				notConnected && ! attributes.creator
					? el(
						Notice,
						{ status: 'warning', isDismissible: false },
						__( 'Not connected yet. Connect your Publica.now account under Settings → Publica.now, or enter a creator slug below.', 'publica-now' )
					)
					: null,
				el( TextControl, {
					label: __( 'Creator', 'publica-now' ),
					help: __( 'Publica.now creator slug or profile URL. Leave empty to use the connected account.', 'publica-now' ),
					value: attributes.creator,
					onChange: set( 'creator' ),
					__nextHasNoMarginBottom: true
				} ),
				el( SelectControl, {
					label: __( 'Content type', 'publica-now' ),
					value: attributes.content_type,
					options: CONTENT_TYPES,
					onChange: set( 'content_type' ),
					__nextHasNoMarginBottom: true
				} ),
				el( SelectControl, {
					label: __( 'Price', 'publica-now' ),
					value: attributes.free,
					options: FREE_OPTIONS,
					onChange: set( 'free' ),
					__nextHasNoMarginBottom: true
				} ),
				el( SelectControl, {
					label: __( 'Order', 'publica-now' ),
					value: attributes.order,
					options: ORDER_OPTIONS,
					onChange: set( 'order' ),
					__nextHasNoMarginBottom: true
				} ),
				el( RangeControl, {
					label: __( 'Number of works', 'publica-now' ),
					help: __( '0 shows every work.', 'publica-now' ),
					value: attributes.limit,
					min: 0,
					max: 100,
					onChange: setNumber( 'limit', 12 ),
					__nextHasNoMarginBottom: true
				} ),
				el( TextControl, {
					label: __( 'Only these works', 'publica-now' ),
					help: __( 'Work ids or slugs, comma separated.', 'publica-now' ),
					value: attributes.ids,
					onChange: set( 'ids' ),
					__nextHasNoMarginBottom: true
				} ),
				el( TextControl, {
					label: __( 'Hide these works', 'publica-now' ),
					help: __( 'Work ids or slugs, comma separated.', 'publica-now' ),
					value: attributes.exclude,
					onChange: set( 'exclude' ),
					__nextHasNoMarginBottom: true
				} )
			),
			el(
				PanelBody,
				{ title: __( 'Layout', 'publica-now' ), initialOpen: true },
				el( SelectControl, {
					label: __( 'Layout', 'publica-now' ),
					value: layout,
					options: LAYOUT_OPTIONS,
					onChange: set( 'layout' ),
					__nextHasNoMarginBottom: true
				} ),
				layout !== 'list'
					? el( RangeControl, {
						label: __( 'Columns', 'publica-now' ),
						value: resolved( 'columns', 3 ),
						min: 1,
						max: 6,
						onChange: setNumber( 'columns', 3 ),
						__nextHasNoMarginBottom: true
					} )
					: null,
				el( ToggleControl, {
					label: __( 'Show content type', 'publica-now' ),
					checked: !! attributes.show_type,
					onChange: set( 'show_type' ),
					__nextHasNoMarginBottom: true
				} ),
				el( ToggleControl, {
					label: __( 'Show author', 'publica-now' ),
					checked: !! attributes.show_author,
					onChange: set( 'show_author' ),
					__nextHasNoMarginBottom: true
				} ),
				el( ToggleControl, {
					label: __( 'Show rating', 'publica-now' ),
					checked: !! resolved( 'show_rating', true ),
					onChange: set( 'show_rating' ),
					__nextHasNoMarginBottom: true
				} ),
				el( ToggleControl, {
					label: __( 'Show excerpt', 'publica-now' ),
					checked: !! resolved( 'show_excerpt', true ),
					onChange: set( 'show_excerpt' ),
					__nextHasNoMarginBottom: true
				} )
			),
			el(
				PanelBody,
				{ title: __( 'Button', 'publica-now' ), initialOpen: false },
				el( TextControl, {
					label: __( 'Button text', 'publica-now' ),
					help: __( 'Replaces the primary button label. Leave empty for Buy / Read free / Order paperback.', 'publica-now' ),
					value: attributes.button_text,
					onChange: set( 'button_text' ),
					__nextHasNoMarginBottom: true
				} ),
				el( TextControl, {
					label: __( 'Extra CSS class', 'publica-now' ),
					value: attributes[ 'class' ],
					onChange: set( 'class' ),
					__nextHasNoMarginBottom: true
				} )
			)
		);

		var body;

		if ( notConnected && ! attributes.creator ) {
			body = el( Placeholder, {
				icon: 'book-alt',
				label: __( 'Publica.now Catalog', 'publica-now' ),
				instructions: __( 'Connect your Publica.now account under Settings → Publica.now, or enter a creator slug in the block settings, to show a catalog here.', 'publica-now' )
			} );
		} else if ( ServerSideRender ) {
			body = el( ServerSideRender, {
				block: BLOCK,
				attributes: attributes,
				skipBlockSupportAttributes: true
			} );
		} else {
			body = el( Placeholder, {
				icon: 'book-alt',
				label: __( 'Publica.now Catalog', 'publica-now' ),
				instructions: __( 'The catalog renders on the published page.', 'publica-now' )
			} );
		}

		return el( 'div', blockProps, inspector, body );
	}

	wp.blocks.registerBlockType( BLOCK, {
		edit: Edit,
		save: function () {
			return null;
		}
	} );
} )( window.wp );
