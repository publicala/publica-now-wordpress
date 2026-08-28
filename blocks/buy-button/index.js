/**
 * Publica.now Buy Button block — editor script.
 *
 * Plain JavaScript on the wp.* globals: no build step. Title, attributes
 * and supports come from block.json (registered server-side); this file
 * supplies edit() — a work picker fed by the plugin's own REST route plus
 * the real server render as preview — and a null save().
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
	var sprintf = wp.i18n.sprintf;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var SelectControl = wp.components.SelectControl;
	var ComboboxControl = wp.components.ComboboxControl;
	var Placeholder = wp.components.Placeholder;
	var Notice = wp.components.Notice;
	var Spinner = wp.components.Spinner;
	var ServerSideRender = wp.serverSideRender;
	var apiFetch = wp.apiFetch;

	var BLOCK = 'publica-now/buy-button';
	var SEARCH_DEBOUNCE_MS = 300;

	var FORMAT_OPTIONS = [
		{ value: 'auto', label: __( 'Automatic (Buy, Read free or Order paperback)', 'publica-now' ) },
		{ value: 'digital', label: __( 'Digital (Buy or Read free)', 'publica-now' ) },
		{ value: 'print', label: __( 'Printed copy (Order paperback)', 'publica-now' ) }
	];

	/**
	 * Connection status from the plugin's own REST route: null while loading,
	 * { connected: null } when the request failed so the editor stays usable.
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

	/**
	 * Debounced search against GET /publica-now/v1/works?search=.
	 */
	function useWorkSearch( term ) {
		var state = useState( { items: [], loading: false } );
		var result = state[ 0 ];
		var setResult = state[ 1 ];

		useEffect( function () {
			var cancelled = false;

			if ( ! apiFetch ) {
				return undefined;
			}

			var timer = setTimeout( function () {
				setResult( function ( previous ) {
					return { items: previous.items, loading: true };
				} );

				apiFetch( { path: '/publica-now/v1/works?search=' + encodeURIComponent( term || '' ) } )
					.then( function ( response ) {
						if ( cancelled ) {
							return;
						}
						setResult( {
							items: response && Array.isArray( response.items ) ? response.items : [],
							loading: false
						} );
					} )
					.catch( function () {
						if ( ! cancelled ) {
							setResult( { items: [], loading: false } );
						}
					} );
			}, SEARCH_DEBOUNCE_MS );

			return function () {
				cancelled = true;
				clearTimeout( timer );
			};
		}, [ term ] );

		return result;
	}

	/**
	 * ComboboxControl over the creator's works. The stored value is the work
	 * slug (readable in the editor, accepted by the API alongside ids); typing
	 * something that matches nothing offers it verbatim so a pasted id or slug
	 * still works when the account is not connected.
	 */
	function WorkPicker( props ) {
		var termState = useState( '' );
		var term = termState[ 0 ];
		var setTerm = termState[ 1 ];
		var search = useWorkSearch( term );
		var current = props.value || '';
		var trimmed = term.trim();

		var options = search.items.map( function ( item ) {
			var value = item.slug ? String( item.slug ) : String( item.id );
			var parts = [ item.title || value ];
			if ( item.kind ) {
				parts.push( item.kind );
			}
			if ( item.price_label ) {
				parts.push( item.price_label );
			}
			return { value: value, label: parts.join( ' · ' ) };
		} );

		function has( value ) {
			return options.some( function ( option ) {
				return option.value === value;
			} );
		}

		if ( trimmed && ! has( trimmed ) ) {
			options.push( {
				value: trimmed,
				/* translators: %s: what the user typed. */
				label: sprintf( __( 'Use “%s” as the work id or slug', 'publica-now' ), trimmed )
			} );
		}

		if ( current && ! has( current ) ) {
			options.unshift( { value: current, label: current } );
		}

		return el(
			'div',
			{ className: 'publicanow-work-picker' },
			el( ComboboxControl, {
				label: props.label,
				help: props.help,
				value: current || null,
				options: options,
				onChange: function ( value ) {
					props.onChange( value ? String( value ) : '' );
				},
				onFilterValueChange: function ( value ) {
					setTerm( value || '' );
				},
				allowReset: true,
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true
			} ),
			search.loading && Spinner ? el( Spinner ) : null
		);
	}

	function Edit( props ) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var blockProps = useBlockProps();
		var status = useStatus();
		var notConnected = !! ( status && status.connected === false );

		function set( key ) {
			return function ( value ) {
				var next = {};
				next[ key ] = value;
				setAttributes( next );
			};
		}

		var connectNotice = notConnected
			? el(
				Notice,
				{ status: 'warning', isDismissible: false },
				__( 'Connect your Publica.now account under Settings → Publica.now to search your works. You can still type a work id or slug.', 'publica-now' )
			)
			: null;

		var inspector = el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: __( 'Button', 'publica-now' ), initialOpen: true },
				connectNotice,
				el( WorkPicker, {
					label: __( 'Work', 'publica-now' ),
					help: __( 'Search by title, or type a work id or slug.', 'publica-now' ),
					value: attributes.work,
					onChange: set( 'work' )
				} ),
				el( SelectControl, {
					label: __( 'Which button', 'publica-now' ),
					value: attributes.format,
					options: FORMAT_OPTIONS,
					onChange: set( 'format' ),
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true
				} ),
				el( TextControl, {
					label: __( 'Button text', 'publica-now' ),
					help: __( 'Leave empty for Buy / Read free / Order paperback.', 'publica-now' ),
					value: attributes.text,
					onChange: set( 'text' ),
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true
				} ),
				el( TextControl, {
					label: __( 'Extra CSS class', 'publica-now' ),
					value: attributes[ 'class' ],
					onChange: set( 'class' ),
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true
				} )
			)
		);

		var body;

		if ( ! attributes.work ) {
			body = el(
				Placeholder,
				{
					icon: 'cart',
					label: __( 'Publica.now Buy Button', 'publica-now' ),
					instructions: __( 'Pick one of your Publica.now works. The button sends readers to publica.now to buy, read or order a printed copy.', 'publica-now' )
				},
				el(
					'div',
					{ style: { width: '100%' } },
					connectNotice,
					el( WorkPicker, {
						label: __( 'Work', 'publica-now' ),
						help: __( 'Search by title, or type a work id or slug.', 'publica-now' ),
						value: attributes.work,
						onChange: set( 'work' )
					} )
				)
			);
		} else if ( ServerSideRender ) {
			body = el( ServerSideRender, {
				block: BLOCK,
				attributes: attributes,
				skipBlockSupportAttributes: true
			} );
		} else {
			body = el( Placeholder, {
				icon: 'cart',
				label: __( 'Publica.now Buy Button', 'publica-now' ),
				instructions: __( 'The button renders on the published page.', 'publica-now' )
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
