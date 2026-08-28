<?php
/**
 * Renderer: turns shortcode/block attributes into escaped HTML.
 *
 * One place owns attribute sanitisation, the wrapper element contract
 * (class="publicanow publicanow-{surface} {class}" + data-publicanow-creator),
 * template loading with theme overrides, stylesheet enqueueing and the
 * "never a blank block" fallback. Shortcodes and blocks are thin adapters
 * over the three public methods: catalog(), work(), button().
 *
 * @package PublicaNow
 */

namespace PublicaNow;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end renderer (singleton).
 */
final class Renderer {

	const STYLE_HANDLE = 'publica-now';
	const MIN_COLUMNS  = 1;
	const MAX_COLUMNS  = 6;

	/**
	 * Stored publica.now content types plus the "ebook" alias people will type.
	 */
	const CONTENT_TYPES = array( 'literary', 'ebook', 'audiobook', 'music', 'video', 'course', 'zine', 'photography', 'design', 'print' );
	const ORDERS        = array( 'newest', 'oldest', 'title', 'price_asc', 'price_desc' );

	/**
	 * Singleton instance.
	 *
	 * @var Renderer|null
	 */
	private static $instance = null;

	/**
	 * Whether register() already added its hooks.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Singleton accessor.
	 *
	 * @return Renderer
	 */
	public static function instance(): Renderer {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hook stylesheet registration. Idempotent: Shortcodes::register() and
	 * Blocks::register() both call it because Plugin::boot() only knows them.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		// Priority 5 so the handle exists before blocks (init 10) reference it via block.json "style".
		if ( did_action( 'init' ) ) {
			self::register_style();
		} else {
			add_action( 'init', array( __CLASS__, 'register_style' ), 5 );
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_early' ) );
		add_action( 'enqueue_block_assets', array( __CLASS__, 'enqueue_editor_style' ) );
	}

	/**
	 * Register (never enqueue) the single front-end stylesheet.
	 *
	 * The "path" data lets block themes inline it when small, which WordPress
	 * does for block styles registered with a file path.
	 *
	 * @return void
	 */
	public static function register_style(): void {
		if ( wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			return;
		}

		wp_register_style(
			self::STYLE_HANDLE,
			self::url() . 'assets/css/publica-now.css',
			array(),
			self::version()
		);
		wp_style_add_data( self::STYLE_HANDLE, 'path', self::path() . 'assets/css/publica-now.css' );
	}

	/**
	 * Enqueue the stylesheet. Safe to call repeatedly and from inside render.
	 *
	 * @return void
	 */
	public static function enqueue_style(): void {
		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			self::register_style();
		}

		wp_enqueue_style( self::STYLE_HANDLE );
	}

	/**
	 * Load the stylesheet in <head> when the queried post visibly uses us, so
	 * shortcodes do not have to wait for the footer print (blocks get this for
	 * free from block.json "style").
	 *
	 * @return void
	 */
	public static function maybe_enqueue_early(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post || ! is_string( $post->post_content ) ) {
			return;
		}

		foreach ( array( 'publicanow_works', 'publicanow_catalog', 'publicanow_work', 'publicanow_button' ) as $shortcode ) {
			if ( has_shortcode( $post->post_content, $shortcode ) ) {
				self::enqueue_style();
				return;
			}
		}
	}

	/**
	 * Block editor: make the front-end styles available to ServerSideRender
	 * previews (enqueue_block_assets also fires on the front end, where the
	 * render path already enqueues, hence the is_admin() guard).
	 *
	 * @return void
	 */
	public static function enqueue_editor_style(): void {
		if ( is_admin() ) {
			self::enqueue_style();
		}
	}

	/**
	 * Plugin settings merged with the contract defaults (docs/PLAN.md §7).
	 *
	 * @return array
	 */
	public static function settings(): array {
		$defaults = array(
			'creator_slug'    => '',
			'open_in_new_tab' => false,
			'show_powered_by' => false,
			'cache_ttl'       => 900,
			'default_columns' => 3,
			'default_layout'  => 'grid',
			'show_excerpt'    => true,
			'show_rating'     => true,
			'button_text'     => '',
		);

		$stored = get_option( 'publicanow_settings', array() );

		return wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
	}

	/**
	 * Catalog attribute defaults. null means "use the site setting", which
	 * lets shortcodes follow Settings → Publica.now while blocks ship
	 * concrete defaults in block.json.
	 *
	 * @return array
	 */
	public static function catalog_defaults(): array {
		return array(
			'creator'       => '',
			'content_type'  => '',
			'free'          => 'any',
			'ids'           => '',
			'exclude'       => '', // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- "exclude" is a block attribute, not a WP_Query parameter.
			'order'         => 'newest',
			'limit'         => 12,
			'columns'       => null,
			'layout'        => null,
			'show_excerpt'  => null,
			'show_rating'   => null,
			'show_author'   => true,
			'show_type'     => true,
			'button_text'   => '',
			'class'         => '',
			'heading_level' => 3,
		);
	}

	/**
	 * Single-work attribute defaults.
	 *
	 * @return array
	 */
	public static function work_defaults(): array {
		return array(
			'id'            => '',
			'layout'        => 'card',
			'show_excerpt'  => null,
			'button_text'   => '',
			'class'         => '',
			'heading_level' => 3,
		);
	}

	/**
	 * Buy-button attribute defaults.
	 *
	 * @return array
	 */
	public static function button_defaults(): array {
		return array(
			'work'   => '',
			'text'   => '',
			'format' => 'auto',
			'class'  => '',
		);
	}

	/**
	 * Render a catalog (grid or list) of a creator's works.
	 *
	 * @param array $atts Raw attributes from a shortcode or block.
	 * @return string HTML.
	 */
	public function catalog( array $atts ): string {
		$a = $this->normalize_catalog( $atts );
		self::enqueue_style();

		$creator = $a['creator'];
		if ( '' === $creator ) {
			$creator = $this->connected_slug();
		}

		if ( '' === $creator ) {
			return $this->render_empty(
				'catalog',
				array(
					'admin_message' => __( 'Connect your Publica.now account under Settings → Publica.now to show your catalog here, or set the creator attribute.', 'publica-now' ),
					'class'         => $a['class'],
					'wrapper'       => $a['wrapper_attributes'],
				)
			);
		}

		$catalog = $this->catalog_api();
		if ( null === $catalog ) {
			return $this->render_empty(
				'catalog',
				array(
					'creator_slug'  => $creator,
					'admin_message' => __( 'The Publica.now catalog service is not available on this site.', 'publica-now' ),
					'class'         => $a['class'],
					'wrapper'       => $a['wrapper_attributes'],
				)
			);
		}

		$args = array(
			'creator' => $creator,
			'order'   => $a['order'],
			'limit'   => $a['limit'],
			'offset'  => 0,
		);
		if ( '' !== $a['content_type'] ) {
			$args['content_type'] = $a['content_type'];
		}
		if ( 'any' !== $a['free'] ) {
			$args['free'] = ( 'yes' === $a['free'] );
		}
		if ( ! empty( $a['ids'] ) ) {
			$args['ids'] = $a['ids'];
		}
		if ( ! empty( $a['exclude'] ) ) {
			$args['exclude'] = $a['exclude']; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- "exclude" is a block attribute, not a WP_Query parameter.
		}

		$result = $catalog->works( $args );

		if ( is_wp_error( $result ) ) {
			return $this->render_empty(
				'catalog',
				array(
					'creator_slug' => $creator,
					'error'        => $result,
					'class'        => $a['class'],
					'wrapper'      => $a['wrapper_attributes'],
				)
			);
		}

		$works = isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
		$works = array_values( array_filter( $works, 'is_array' ) );

		if ( empty( $works ) ) {
			return $this->render_empty(
				'catalog',
				array(
					'creator_slug' => $creator,
					'message'      => __( 'No works to show yet.', 'publica-now' ),
					'class'        => $a['class'],
					'wrapper'      => $a['wrapper_attributes'],
				)
			);
		}

		foreach ( $works as $work ) {
			Structured_Data::instance()->add( $work );
		}

		$source = isset( $result['source'] ) ? (string) $result['source'] : 'api';

		$vars = array(
			'works'         => $works,
			'total'         => isset( $result['total'] ) ? (int) $result['total'] : count( $works ),
			'source'        => $source,
			'creator_slug'  => $creator,
			'layout'        => $a['layout'],
			'columns'       => $a['columns'],
			'show_excerpt'  => $a['show_excerpt'],
			'show_rating'   => $a['show_rating'],
			'show_author'   => $a['show_author'],
			'show_type'     => $a['show_type'],
			'button_text'   => $a['button_text'],
			'heading_level' => $a['heading_level'],
			'target'        => Links::target(),
			'context'       => 'catalog',
			'atts'          => $a,
		);

		$inner = $this->template( 'grid' === $a['layout'] ? 'works-grid' : 'works-list', $vars );

		// Admins get the stale-cache reason inline; visitors see the catalog as usual.
		if ( 'stale' === $source && ! empty( $result['error'] ) && is_wp_error( $result['error'] ) && current_user_can( 'manage_options' ) ) {
			$inner .= sprintf(
				'<small class="publicanow-notice publicanow-notice--stale">%s</small>',
				esc_html(
					sprintf(
						/* translators: %s: error message from the API client. */
						__( 'Showing a saved copy of the catalog because Publica.now could not be reached: %s', 'publica-now' ),
						$result['error']->get_error_message()
					)
				)
			);
		}

		$style = 'grid' === $a['layout'] ? '--publicanow-columns:' . (int) $a['columns'] : '';

		return $this->wrapper_open(
			'div',
			array( 'publicanow', 'publicanow-catalog', 'publicanow-catalog--' . $a['layout'], $a['class'] ),
			array(
				'data-publicanow-creator' => $creator,
				'data-publicanow-source'  => $source,
			),
			$style,
			$a['wrapper_attributes']
		) . $inner . '</div>';
	}

	/**
	 * Render one work as a card or an inline row.
	 *
	 * @param array $atts Raw attributes from a shortcode or block.
	 * @return string HTML.
	 */
	public function work( array $atts ): string {
		$a = $this->normalize_work( $atts );
		self::enqueue_style();

		if ( '' === $a['id'] ) {
			return $this->render_empty(
				'work',
				array(
					'admin_message' => __( 'Choose a work: set the id attribute to a Publica.now work id or slug.', 'publica-now' ),
					'class'         => $a['class'],
					'wrapper'       => $a['wrapper_attributes'],
				)
			);
		}

		$work = $this->fetch_work( $a['id'] );
		if ( is_wp_error( $work ) ) {
			return $this->render_empty(
				'work',
				array(
					'creator_slug' => $this->connected_slug(),
					'error'        => $work,
					'class'        => $a['class'],
					'wrapper'      => $a['wrapper_attributes'],
				)
			);
		}

		Structured_Data::instance()->add( $work );

		$settings = self::settings();
		$vars     = $this->card_vars(
			$work,
			array(
				'show_excerpt'  => $a['show_excerpt'],
				'show_rating'   => ! empty( $settings['show_rating'] ),
				'show_author'   => true,
				'show_type'     => true,
				'button_text'   => $a['button_text'],
				'heading_level' => $a['heading_level'],
				'context'       => 'work',
			)
		);

		$inner = $this->template( 'card' === $a['layout'] ? 'work-card' : 'work-inline', $vars );

		return $this->wrapper_open(
			'div',
			array( 'publicanow', 'publicanow-work', 'publicanow-work--' . $a['layout'], $a['class'] ),
			array( 'data-publicanow-creator' => $this->creator_slug_of( $work ) ),
			'',
			$a['wrapper_attributes']
		) . $inner . '</div>';
	}

	/**
	 * Render a single call-to-action button for a work.
	 *
	 * @param array $atts Raw attributes from a shortcode or block.
	 * @return string HTML.
	 */
	public function button( array $atts ): string {
		$a = $this->normalize_button( $atts );
		self::enqueue_style();

		if ( '' === $a['work'] ) {
			return $this->render_empty(
				'buy-button',
				array(
					'admin_message' => __( 'Choose a work: set the work attribute to a Publica.now work id or slug.', 'publica-now' ),
					'class'         => $a['class'],
					'wrapper'       => $a['wrapper_attributes'],
					'inline'        => true,
				)
			);
		}

		$work = $this->fetch_work( $a['work'] );
		if ( is_wp_error( $work ) ) {
			return $this->render_empty(
				'buy-button',
				array(
					'creator_slug' => $this->connected_slug(),
					'error'        => $work,
					'class'        => $a['class'],
					'wrapper'      => $a['wrapper_attributes'],
					'inline'       => true,
				)
			);
		}

		$buttons = Links::buttons_for( $work, $a['text'] );
		$button  = $this->pick_button( $buttons, $a['format'] );

		if ( null === $button ) {
			return $this->render_empty(
				'buy-button',
				array(
					'creator_slug'  => $this->creator_slug_of( $work ),
					'admin_message' => __( 'This work has no link to offer yet (it may be unpublished on Publica.now).', 'publica-now' ),
					'class'         => $a['class'],
					'wrapper'       => $a['wrapper_attributes'],
					'inline'        => true,
				)
			);
		}

		Structured_Data::instance()->add( $work );

		$inner = $this->template(
			'buy-button',
			array(
				'work'   => $work,
				'button' => $button,
				'target' => Links::target(),
				'format' => $a['format'],
			)
		);

		return $this->wrapper_open(
			'span',
			array( 'publicanow', 'publicanow-buy-button', $a['class'] ),
			array( 'data-publicanow-creator' => $this->creator_slug_of( $work ) ),
			'',
			$a['wrapper_attributes']
		) . $inner . '</span>';
	}

	/**
	 * Per-card template variables. Grid/list templates call this for each
	 * work so buttons and links are computed in exactly one place.
	 *
	 * @param array $work Normalised work.
	 * @param array $vars Surface-level vars (show_*, button_text, heading_level, context).
	 * @return array
	 */
	public function card_vars( array $work, array $vars ): array {
		$button_text = isset( $vars['button_text'] ) ? (string) $vars['button_text'] : '';
		$seed        = isset( $work['slug'] ) && is_scalar( $work['slug'] ) ? (string) $work['slug'] : '';
		if ( '' === $seed && isset( $work['id'] ) && is_scalar( $work['id'] ) ) {
			$seed = (string) $work['id'];
		}

		return array(
			'work'          => $work,
			'buttons'       => Links::buttons_for( $work, $button_text ),
			'read_url'      => Links::read( $work ),
			'target'        => Links::target(),
			'show_excerpt'  => ! empty( $vars['show_excerpt'] ),
			'show_rating'   => ! isset( $vars['show_rating'] ) || ! empty( $vars['show_rating'] ),
			'show_author'   => ! isset( $vars['show_author'] ) || ! empty( $vars['show_author'] ),
			'show_type'     => ! isset( $vars['show_type'] ) || ! empty( $vars['show_type'] ),
			'heading_level' => isset( $vars['heading_level'] ) ? $this->heading_level( $vars['heading_level'] ) : 3,
			'hue_class'     => Formatting::cover_hue_class( $seed ),
			'context'       => isset( $vars['context'] ) ? (string) $vars['context'] : 'catalog',
		);
	}

	/**
	 * Load a template, preferring {theme}/publica-now/{name}.php.
	 *
	 * Templates receive their variables as $args (the get_template_part()
	 * convention) and escape their own output.
	 *
	 * @param string $name Template name without extension.
	 * @param array  $vars Variables for the template.
	 * @return string Rendered HTML, '' when the template does not exist.
	 */
	public function template( string $name, array $vars = array() ): string {
		$name = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $name ) );
		if ( '' === $name ) {
			return '';
		}

		/**
		 * Filter the variables passed to a Publica.now template.
		 *
		 * @param array  $vars     Template variables.
		 * @param string $template Template name (e.g. "work-card").
		 */
		$vars = apply_filters( 'publicanow_template_vars', $vars, $name );
		$vars = is_array( $vars ) ? $vars : array();

		$file = locate_template( 'publica-now/' . $name . '.php' );
		if ( ! $file ) {
			$file = self::path() . 'templates/' . $name . '.php';
		}

		if ( ! is_readable( $file ) ) {
			return '';
		}

		ob_start();
		self::include_template( $file, $vars );

		// Trailing newlines from the template file would become stray whitespace inside inline wrappers.
		return trim( (string) ob_get_clean() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Attribute normalisation
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Sanitise catalog attributes and resolve setting-backed defaults.
	 *
	 * @param array $atts Raw attributes.
	 * @return array
	 */
	private function normalize_catalog( array $atts ): array {
		$atts     = wp_parse_args( $atts, self::catalog_defaults() );
		$settings = self::settings();

		$content_type = self::enum( $atts['content_type'], self::CONTENT_TYPES, '' );

		return array(
			'creator'            => self::slug( $atts['creator'] ),
			'content_type'       => $content_type,
			'free'               => self::enum( $atts['free'], array( 'yes', 'no', 'any' ), 'any' ),
			'ids'                => self::id_list( $atts['ids'] ),
			'exclude'            => self::id_list( $atts['exclude'] ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- "exclude" is a block attribute, not a WP_Query parameter.
			'order'              => self::enum( $atts['order'], self::ORDERS, 'newest' ),
			'limit'              => min( 100, absint( $atts['limit'] ) ),
			'columns'            => $this->columns( $atts['columns'], (int) $settings['default_columns'] ),
			'layout'             => self::enum( $atts['layout'], array( 'grid', 'list' ), self::enum( $settings['default_layout'], array( 'grid', 'list' ), 'grid' ) ),
			'show_excerpt'       => self::to_bool( $atts['show_excerpt'], ! empty( $settings['show_excerpt'] ) ),
			'show_rating'        => self::to_bool( $atts['show_rating'], ! empty( $settings['show_rating'] ) ),
			'show_author'        => self::to_bool( $atts['show_author'], true ),
			'show_type'          => self::to_bool( $atts['show_type'], true ),
			'button_text'        => sanitize_text_field( (string) $atts['button_text'] ),
			'class'              => self::css_class( $atts['class'] ),
			'heading_level'      => $this->heading_level( $atts['heading_level'] ),
			'wrapper_attributes' => isset( $atts['wrapper_attributes'] ) && is_string( $atts['wrapper_attributes'] ) ? $atts['wrapper_attributes'] : '',
		);
	}

	/**
	 * Sanitise single-work attributes.
	 *
	 * @param array $atts Raw attributes.
	 * @return array
	 */
	private function normalize_work( array $atts ): array {
		$atts     = wp_parse_args( $atts, self::work_defaults() );
		$settings = self::settings();

		return array(
			'id'                 => self::identifier( $atts['id'] ),
			'layout'             => self::enum( $atts['layout'], array( 'card', 'inline' ), 'card' ),
			'show_excerpt'       => self::to_bool( $atts['show_excerpt'], ! empty( $settings['show_excerpt'] ) ),
			'button_text'        => sanitize_text_field( (string) $atts['button_text'] ),
			'class'              => self::css_class( $atts['class'] ),
			'heading_level'      => $this->heading_level( $atts['heading_level'] ),
			'wrapper_attributes' => isset( $atts['wrapper_attributes'] ) && is_string( $atts['wrapper_attributes'] ) ? $atts['wrapper_attributes'] : '',
		);
	}

	/**
	 * Sanitise buy-button attributes.
	 *
	 * @param array $atts Raw attributes.
	 * @return array
	 */
	private function normalize_button( array $atts ): array {
		$atts = wp_parse_args( $atts, self::button_defaults() );

		return array(
			'work'               => self::identifier( $atts['work'] ),
			'text'               => sanitize_text_field( (string) $atts['text'] ),
			'format'             => self::enum( $atts['format'], array( 'digital', 'print', 'auto' ), 'auto' ),
			'class'              => self::css_class( $atts['class'] ),
			'wrapper_attributes' => isset( $atts['wrapper_attributes'] ) && is_string( $atts['wrapper_attributes'] ) ? $atts['wrapper_attributes'] : '',
		);
	}

	/**
	 * Boolean from shortcode/block input ("yes", "0", true, ...).
	 *
	 * @param mixed $value   Raw value.
	 * @param bool  $fallback Used for null/'' and unrecognised strings.
	 * @return bool
	 */
	private static function to_bool( $value, bool $fallback ): bool {
		if ( null === $value || '' === $value ) {
			return $fallback;
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 0 !== $value;
		}

		$value = strtolower( trim( (string) $value ) );

		if ( in_array( $value, array( '1', 'true', 'yes', 'on' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( '0', 'false', 'no', 'off' ), true ) ) {
			return false;
		}

		return $fallback;
	}

	/**
	 * Whitelisted string value.
	 *
	 * @param mixed  $value   Raw value.
	 * @param array  $allowed Allowed values.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function enum( $value, array $allowed, string $fallback ): string {
		if ( ! is_scalar( $value ) ) {
			return $fallback;
		}

		$value = strtolower( trim( (string) $value ) );

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Creator slug per the contract pattern; also accepts a pasted profile URL.
	 *
	 * @param mixed $value Raw value.
	 * @return string '' when invalid.
	 */
	private static function slug( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = strtolower( trim( (string) $value ) );

		if ( false !== strpos( $value, '/' ) ) {
			// "https://publica.now/creators/jane" or "/creators/jane/works/x" → "jane".
			$path     = (string) wp_parse_url( $value, PHP_URL_PATH );
			$segments = array_values( array_filter( explode( '/', $path ) ) );
			$index    = array_search( 'creators', $segments, true );
			$value    = false !== $index && isset( $segments[ $index + 1 ] ) ? $segments[ $index + 1 ] : (string) end( $segments );
		}

		return preg_match( '/^[a-z0-9][a-z0-9-]{0,189}$/', $value ) ? $value : '';
	}

	/**
	 * Work id or slug: a single safe token.
	 *
	 * @param mixed $value Raw value.
	 * @return string '' when invalid.
	 */
	private static function identifier( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );

		return preg_match( '/^[A-Za-z0-9_\-]{1,190}$/', $value ) ? $value : '';
	}

	/**
	 * Comma/space separated ids or slugs → list of safe tokens.
	 *
	 * @param mixed $value Raw value (string or array).
	 * @return string[]
	 */
	private static function id_list( $value ): array {
		if ( is_array( $value ) ) {
			$value = implode( ',', array_filter( $value, 'is_scalar' ) );
		}
		if ( ! is_scalar( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( preg_split( '/[\s,]+/', (string) $value ) as $token ) {
			$token = self::identifier( $token );
			if ( '' !== $token ) {
				$out[] = $token;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Space-separated list of extra CSS classes, each run through sanitize_html_class().
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function css_class( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$classes = array();
		foreach ( preg_split( '/\s+/', trim( (string) $value ) ) as $class ) {
			$class = sanitize_html_class( $class );
			if ( '' !== $class ) {
				$classes[] = $class;
			}
		}

		return implode( ' ', array_unique( $classes ) );
	}

	/**
	 * Column count clamped to 1–6; null/'' → the site default.
	 *
	 * @param mixed $value   Raw value.
	 * @param int   $fallback Site default.
	 * @return int
	 */
	private function columns( $value, int $fallback ): int {
		$columns = ( null === $value || '' === $value ) ? $fallback : absint( $value );

		if ( $columns < self::MIN_COLUMNS ) {
			$columns = $fallback > 0 ? $fallback : 3;
		}

		return max( self::MIN_COLUMNS, min( self::MAX_COLUMNS, $columns ) );
	}

	/**
	 * Heading level clamped to h2–h6 (h1 is the page's).
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private function heading_level( $value ): int {
		$level = absint( $value );

		return ( $level < 2 || $level > 6 ) ? 3 : $level;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Catalog access (Team A) with guards so a missing class never fatals
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The Catalog service, or null when its class is unavailable.
	 *
	 * @return Catalog|null
	 */
	private function catalog_api() {
		if ( ! class_exists( __NAMESPACE__ . '\Catalog' ) ) {
			return null;
		}

		return Catalog::instance();
	}

	/**
	 * Connected creator slug, '' when not connected.
	 *
	 * @return string
	 */
	private function connected_slug(): string {
		$catalog = $this->catalog_api();

		return null === $catalog ? '' : (string) $catalog->connected_slug();
	}

	/**
	 * Fetch one normalised work.
	 *
	 * @param string $id_or_slug Work id or slug.
	 * @return array|WP_Error
	 */
	private function fetch_work( string $id_or_slug ) {
		$catalog = $this->catalog_api();

		if ( null === $catalog ) {
			return new WP_Error( 'publicanow_http', __( 'The Publica.now catalog service is not available on this site.', 'publica-now' ) );
		}

		$work = $catalog->work( $id_or_slug );

		if ( is_wp_error( $work ) ) {
			return $work;
		}

		if ( ! is_array( $work ) ) {
			return new WP_Error( 'publicanow_not_found', __( 'This work could not be found on Publica.now.', 'publica-now' ) );
		}

		return $work;
	}

	/**
	 * Creator slug of a normalised work, '' when unknown.
	 *
	 * @param array $work Normalised work.
	 * @return string
	 */
	private function creator_slug_of( array $work ): string {
		return isset( $work['creator']['slug'] ) && is_scalar( $work['creator']['slug'] ) ? (string) $work['creator']['slug'] : '';
	}

	/**
	 * Choose the button for the buy-button surface.
	 *
	 * Rule: auto → the primary; digital → buy/read, else primary; print → print,
	 * else primary (a visible button beats an empty block).
	 *
	 * @param array  $buttons Output of Links::buttons_for().
	 * @param string $format  digital|print|auto.
	 * @return array|null
	 */
	private function pick_button( array $buttons, string $format ) {
		if ( empty( $buttons ) ) {
			return null;
		}

		$primary = null;
		foreach ( $buttons as $button ) {
			if ( ! empty( $button['primary'] ) ) {
				$primary = $button;
				break;
			}
		}
		if ( null === $primary ) {
			$primary = $buttons[0];
		}

		if ( 'auto' === $format ) {
			return $primary;
		}

		$wanted = 'print' === $format ? array( 'print' ) : array( 'buy', 'read' );
		foreach ( $buttons as $button ) {
			if ( in_array( $button['kind'], $wanted, true ) ) {
				return $button;
			}
		}

		return $primary;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Fallback + wrapper
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The "never a blank block" state: a quiet paragraph, a link to the
	 * creator's publica.now page when we know it, and the reason for admins.
	 *
	 * The creator name comes from the connect snapshot option, never from a
	 * network call — this path runs precisely when the API is failing.
	 *
	 * @param string $surface catalog|work|buy-button.
	 * @param array  $opts    creator_slug, error (WP_Error), message, admin_message, class, wrapper, inline.
	 * @return string HTML.
	 */
	private function render_empty( string $surface, array $opts ): string {
		$slug     = isset( $opts['creator_slug'] ) ? (string) $opts['creator_slug'] : '';
		$error    = isset( $opts['error'] ) && is_wp_error( $opts['error'] ) ? $opts['error'] : null;
		$inline   = ! empty( $opts['inline'] );
		$link_url = '';
		$name     = '';

		if ( '' !== $slug ) {
			$snapshot = get_option( 'publicanow_creator', array() );
			$creator  = array( 'slug' => $slug );

			if ( is_array( $snapshot ) && isset( $snapshot['slug'] ) && $snapshot['slug'] === $slug ) {
				$name = isset( $snapshot['name'] ) ? (string) $snapshot['name'] : '';
				if ( ! empty( $snapshot['url'] ) ) {
					$creator['url'] = (string) $snapshot['url'];
				}
			}

			$link_url = Links::creator( $creator );
		}

		$message = isset( $opts['message'] ) ? (string) $opts['message'] : '';
		if ( '' === $message && null !== $error ) {
			// A single work or button is not a "catalog" to the reader.
			$message = 'catalog' === $surface
				? __( 'This catalog is temporarily unavailable.', 'publica-now' )
				: __( 'This work is temporarily unavailable.', 'publica-now' );
		}

		$link_text = '';
		if ( '' !== $link_url ) {
			$link_text = '' !== $name
				/* translators: %s: creator name. */
				? sprintf( __( 'Browse %s on Publica.now', 'publica-now' ), $name )
				: __( 'Browse on Publica.now', 'publica-now' );
		}

		$admin_message = '';
		if ( current_user_can( 'manage_options' ) ) {
			$admin_message = isset( $opts['admin_message'] ) ? (string) $opts['admin_message'] : '';
			if ( null !== $error ) {
				$detail = $error->get_error_message();
				$code   = (string) $error->get_error_code();
				$data   = $error->get_error_data();
				if ( is_array( $data ) && ! empty( $data['status'] ) ) {
					$code .= ' / HTTP ' . (int) $data['status'];
				}
				/* translators: 1: error message, 2: error code. */
				$admin_message = trim( $admin_message . ' ' . sprintf( __( 'Publica.now error: %1$s (%2$s)', 'publica-now' ), $detail, $code ) );
				if ( 'publicanow_not_connected' === $error->get_error_code() ) {
					$admin_message .= ' ' . __( 'Connect your Publica.now account under Settings → Publica.now.', 'publica-now' );
				}
			}
		}

		$inner = $this->template(
			'empty',
			array(
				'surface'       => $surface,
				'message'       => $message,
				'link_url'      => $link_url,
				'link_text'     => $link_text,
				'admin_message' => $admin_message,
				'inline'        => $inline,
				'target'        => Links::target(),
				'error'         => $error,
			)
		);

		$tag  = $inline ? 'span' : 'div';
		$data = array();
		if ( '' !== $slug ) {
			$data['data-publicanow-creator'] = $slug;
		}

		return $this->wrapper_open(
			$tag,
			array( 'publicanow', 'publicanow-' . $surface, 'publicanow-empty', isset( $opts['class'] ) ? (string) $opts['class'] : '' ),
			$data,
			'',
			isset( $opts['wrapper'] ) ? (string) $opts['wrapper'] : ''
		) . $inner . '</' . $tag . '>';
	}

	/**
	 * Opening tag of the outer element, merging block wrapper attributes.
	 *
	 * Core's get_block_wrapper_attributes() hands us a string ("class=… style=…");
	 * instead of echoing it verbatim next to our own class/style (two class
	 * attributes on one element is invalid HTML) we parse it, allow-list the
	 * attribute names and merge, escaping once at output.
	 *
	 * @param string $tag        div|span.
	 * @param array  $classes    Our classes (empty strings are dropped).
	 * @param array  $data       Our data-* attributes.
	 * @param string $style      Our inline style (only --publicanow-columns is ever set).
	 * @param string $wrapper    Output of get_block_wrapper_attributes(), or ''.
	 * @return string
	 */
	private function wrapper_open( string $tag, array $classes, array $data, string $style, string $wrapper ): string {
		$attrs = array(
			'class' => implode( ' ', array_filter( array_map( 'trim', $classes ) ) ),
			'style' => $style,
		);

		foreach ( $data as $name => $value ) {
			if ( '' !== (string) $value ) {
				$attrs[ $name ] = (string) $value;
			}
		}

		foreach ( self::parse_wrapper_attributes( $wrapper ) as $name => $value ) {
			if ( 'class' === $name ) {
				$attrs['class'] = trim( $attrs['class'] . ' ' . $value );
			} elseif ( 'style' === $name ) {
				$attrs['style'] = trim( rtrim( $attrs['style'], '; ' ) . ';' . $value, '; ' );
			} elseif ( ! isset( $attrs[ $name ] ) ) {
				$attrs[ $name ] = $value;
			}
		}

		$html = '<' . tag_escape( $tag );
		foreach ( $attrs as $name => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$html .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}

		return $html . '>';
	}

	/**
	 * Parse an HTML attribute string into an allow-listed name → value map.
	 *
	 * @param string $html e.g. 'class="wp-block-x alignwide" style="margin:0"'.
	 * @return array<string,string>
	 */
	private static function parse_wrapper_attributes( string $html ): array {
		$out = array();

		if ( '' === trim( $html ) ) {
			return $out;
		}

		if ( ! preg_match_all( '/([A-Za-z][A-Za-z0-9\-_]*)\s*=\s*"([^"]*)"/', $html, $matches, PREG_SET_ORDER ) ) {
			return $out;
		}

		foreach ( $matches as $pair ) {
			$name = strtolower( $pair[1] );

			if ( in_array( $name, array( 'class', 'style', 'id' ), true ) || preg_match( '/^(data|aria)-[a-z0-9\-_]+$/', $name ) ) {
				// Values arrive esc_attr()-ed by core; decoding here means esc_attr() at output does not double-encode.
				$out[ $name ] = html_entity_decode( $pair[2], ENT_QUOTES, 'UTF-8' );
			}
		}

		return $out;
	}

	/**
	 * Include a template with $args in scope, without leaking $this or extract().
	 *
	 * @param string $publicanow_template Absolute path.
	 * @param array  $args                Template variables.
	 * @return void
	 */
	private static function include_template( string $publicanow_template, array $args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $args is read by the included template file.
		include $publicanow_template;
	}

	/**
	 * Plugin directory path with trailing slash.
	 *
	 * @return string
	 */
	private static function path(): string {
		return trailingslashit( defined( 'PUBLICANOW_PATH' ) ? PUBLICANOW_PATH : dirname( __DIR__ ) );
	}

	/**
	 * Plugin directory URL with trailing slash.
	 *
	 * @return string
	 */
	private static function url(): string {
		return trailingslashit( defined( 'PUBLICANOW_URL' ) ? PUBLICANOW_URL : plugins_url( '', __DIR__ ) );
	}

	/**
	 * Asset version for cache busting.
	 *
	 * @return string
	 */
	private static function version(): string {
		return defined( 'PUBLICANOW_VERSION' ) ? PUBLICANOW_VERSION : '1.0.0';
	}
}
