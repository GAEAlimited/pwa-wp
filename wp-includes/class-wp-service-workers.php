<?php
/**
 * Dependencies API: WP_Service_Workers class
 *
 * @since 0.1
 *
 * @package PWA
 */

/**
 * Class used to register service workers.
 *
 * @since 0.1
 *
 * @see WP_Dependencies
 */
class WP_Service_Workers extends WP_Scripts {
	/**
	 * Param for service workers.
	 *
	 * @var string
	 */
	const QUERY_VAR = 'wp_service_worker';

	/**
	 * Scope for front.
	 *
	 * @var int
	 */
	const SCOPE_FRONT = 1;

	/**
	 * Scope for admin.
	 *
	 * @var int
	 */
	const SCOPE_ADMIN = 2;

	/**
	 * Scope for both front and admin.
	 *
	 * @var int
	 */
	const SCOPE_ALL = 3;

	/**
	 * Output for service worker scope script.
	 *
	 * @var string
	 */
	public $output = '';

	/**
	 * Cache Registry.
	 *
	 * @var WP_Service_Worker_Cache_Registry
	 */
	public $cache_registry;

	/**
	 * Constructor.
	 *
	 * @since 0.2
	 */
	public function __construct() {
		$this->cache_registry = new WP_Service_Worker_Cache_Registry();

		parent::__construct();
	}

	/**
	 * Initialize the class.
	 */
	public function init() {

		/**
		 * Fires when the WP_Service_Workers instance is initialized.
		 *
		 * @param WP_Service_Workers $this WP_Service_Workers instance (passed by reference).
		 */
		do_action_ref_array( 'wp_default_service_workers', array( &$this ) );
	}

	/**
	 * Get the current scope for the service worker request.
	 *
	 * @return int Scope. Either SCOPE_FRONT, SCOPE_ADMIN, or if neither then 0.
	 * @global WP $wp
	 */
	public function get_current_scope() {
		global $wp;
		if ( ! isset( $wp->query_vars[ self::QUERY_VAR ] ) || ! is_numeric( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return 0;
		}
		$scope = (int) $wp->query_vars[ self::QUERY_VAR ];
		if ( self::SCOPE_FRONT === $scope ) {
			return self::SCOPE_FRONT;
		} elseif ( self::SCOPE_ADMIN === $scope ) {
			return self::SCOPE_ADMIN;
		}
		return 0;
	}

	/**
	 * Get script for handling of error responses when the user is offline or when there is an internal server error.
	 *
	 * @return string Script.
	 */
	protected function get_error_response_handling_script() {
		$template   = get_template();
		$stylesheet = get_stylesheet();

		$revision = sprintf( '%s-v%s', $template, wp_get_theme( $template )->Version );
		if ( $template !== $stylesheet ) {
			$revision .= sprintf( ';%s-v%s', $stylesheet, wp_get_theme( $stylesheet )->Version );
		}

		// Ensure the user-specific offline/500 pages are precached, and thet they update when user logs out or switches to another user.
		$revision .= sprintf( ';user-%d', get_current_user_id() );

		$scope = $this->get_current_scope();
		if ( self::SCOPE_FRONT === $scope ) {
			$offline_error_template_file  = pwa_locate_template( array( 'offline.php', 'error.php' ) );
			$offline_error_precache_entry = array(
				'url'      => add_query_arg( 'wp_error_template', 'offline', home_url( '/' ) ),
				'revision' => $revision . ';' . md5( $offline_error_template_file . file_get_contents( $offline_error_template_file ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			);
			$server_error_template_file   = pwa_locate_template( array( '500.php', 'error.php' ) );
			$server_error_precache_entry  = array(
				'url'      => add_query_arg( 'wp_error_template', '500', home_url( '/' ) ),
				'revision' => $revision . ';' . md5( $server_error_template_file . file_get_contents( $server_error_template_file ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			);

			/**
			 * Filters what is precached to serve as the offline error response on the frontend.
			 *
			 * The URL returned in this array will be precached by the service worker and served as the response when
			 * the client is offline or their connection fails. To prevent this behavior, this value can be filtered
			 * to return false. When a theme or plugin makes a change to the response, the revision value in the array
			 * must be incremented to ensure the URL is re-fetched to store in the precache.
			 *
			 * @since 0.2
			 *
			 * @param array|false $entry {
			 *     Offline error precache entry.
			 *
			 *     @type string $url      URL to page that shows the offline error template.
			 *     @type string $revision Revision for the template. This defaults to the template and stylesheet names, with their respective theme versions.
			 * }
			 */
			$offline_error_precache_entry = apply_filters( 'wp_offline_error_precache_entry', $offline_error_precache_entry );

			/**
			 * Filters what is precached to serve as the internal server error response on the frontend.
			 *
			 * The URL returned in this array will be precached by the service worker and served as the response when
			 * the server returns a 500 internal server error . To prevent this behavior, this value can be filtered
			 * to return false. When a theme or plugin makes a change to the response, the revision value in the array
			 * must be incremented to ensure the URL is re-fetched to store in the precache.
			 *
			 * @since 0.2
			 *
			 * @param array $entry {
			 *     Server error precache entry.
			 *
			 *     @type string $url      URL to page that shows the server error template.
			 *     @type string $revision Revision for the template. This defaults to the template and stylesheet names, with their respective theme versions.
			 * }
			 */
			$server_error_precache_entry = apply_filters( 'wp_server_error_precache_entry', $server_error_precache_entry );

		} else {
			$offline_error_precache_entry = array(
				'url'      => add_query_arg( 'code', 'offline', admin_url( 'admin-ajax.php?action=wp_error_template' ) ), // Upon core merge, this would use admin_url( 'error.php' ).
				'revision' => PWA_VERSION, // Upon core merge, this should be the core version.
			);
			$server_error_precache_entry  = array(
				'url'      => add_query_arg( 'code', '500', admin_url( 'admin-ajax.php?action=wp_error_template' ) ), // Upon core merge, this would use admin_url( 'error.php' ).
				'revision' => PWA_VERSION, // Upon core merge, this should be the core version.
			);
		}

		if ( $offline_error_precache_entry ) {
			$this->cache_registry->register_precached_route( $offline_error_precache_entry['url'], isset( $offline_error_precache_entry['revision'] ) ? $offline_error_precache_entry['revision'] : null );
		}
		if ( $server_error_precache_entry ) {
			$this->cache_registry->register_precached_route( $server_error_precache_entry['url'], isset( $server_error_precache_entry['revision'] ) ? $server_error_precache_entry['revision'] : null );
		}

		$blacklist_patterns = array();
		if ( self::SCOPE_FRONT === $scope ) {
			$blacklist_patterns[] = '^' . preg_quote( untrailingslashit( wp_parse_url( admin_url(), PHP_URL_PATH ) ), '/' ) . '($|\?.*|/.*)';
		}

		$replacements = array(
			'ERROR_OFFLINE_URL'  => isset( $offline_error_precache_entry['url'] ) ? $this->json_encode( $offline_error_precache_entry['url'] ) : null,
			'ERROR_500_URL'      => isset( $server_error_precache_entry['url'] ) ? $this->json_encode( $server_error_precache_entry['url'] ) : null,
			'BLACKLIST_PATTERNS' => $this->json_encode( $blacklist_patterns ),
		);

		$script = file_get_contents( PWA_PLUGIN_DIR . '/wp-includes/js/service-worker-error-response-handling.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$script = preg_replace( '#/\*\s*global.+?\*/#', '', $script );

		return str_replace(
			array_keys( $replacements ),
			array_values( $replacements ),
			$script
		);
	}

	/**
	 * Get base script for service worker.
	 *
	 * This involves the loading and configuring Workbox. However, the `workbox` global should not be directly
	 * interacted with. Instead, developers should interface with `wp.serviceWorker` which is a wrapper around
	 * the Workbox library.
	 *
	 * @link https://github.com/GoogleChrome/workbox
	 *
	 * @return string Script.
	 */
	protected function get_base_script() {

		$current_scope = $this->get_current_scope();
		$workbox_dir   = 'wp-includes/js/workbox-v3.6.1/';

		$script = sprintf(
			"importScripts( %s );\n",
			$this->json_encode( PWA_PLUGIN_URL . $workbox_dir . 'workbox-sw.js' )
		);

		$options = array(
			'debug'            => WP_DEBUG,
			'modulePathPrefix' => PWA_PLUGIN_URL . $workbox_dir,
		);
		$script .= sprintf( "workbox.setConfig( %s );\n", $this->json_encode( $options ) );

		$cache_name_details = array(
			'prefix' => 'wordpress',
			'suffix' => 'v1',
		);

		$script .= sprintf( "workbox.core.setCacheNameDetails( %s );\n", $this->json_encode( $cache_name_details ) );

		// @todo Add filter controlling workbox.skipWaiting().
		// @todo Add filter controlling workbox.clientsClaim().
		/**
		 * Filters whether navigation preload is enabled.
		 *
		 * The filtered value will be sent as the Service-Worker-Navigation-Preload header value if a truthy string.
		 * This filter should be set to return false to disable navigation preload such as when a site is using
		 * the app shell model. Take care of the current scope when setting this, as it is unlikely that the admin
		 * should have navigation preload disabled until core has an admin single-page app. To disable navigation preload on
		 * the frontend only, you may do:
		 *
		 *     add_filter( 'wp_front_service_worker', function() {
		 *         add_filter( 'wp_service_worker_navigation_preload', '__return_false' );
		 *     } );
		 *
		 * Alternatively, you should check the `$current_scope` for example:
		 *
		 *     add_filter( 'wp_service_worker_navigation_preload', function( $preload, $current_scope ) {
		 *         if ( WP_Service_Workers::SCOPE_FRONT === $current_scope ) {
		 *             $preload = false;
		 *         }
		 *         return $preload;
		 *     }, 10, 2 );
		 *
		 * @param bool|string $navigation_preload Whether to use navigation preload. Returning a string will cause it it to populate the Service-Worker-Navigation-Preload header.
		 * @param int         $current_scope      The current scope. Either 1 (WP_Service_Workers::SCOPE_FRONT) or 2 (WP_Service_Workers::SCOPE_ADMIN).
		 */
		$navigation_preload = apply_filters( 'wp_service_worker_navigation_preload', true, $current_scope );
		if ( false !== $navigation_preload ) {
			if ( is_string( $navigation_preload ) ) {
				$script .= sprintf( "workbox.navigationPreload.enable( %s );\n", $this->json_encode( $navigation_preload ) );
			} else {
				$script .= "workbox.navigationPreload.enable();\n";
			}
		} else {
			$script .= "/* Navigation preload disabled. */\n";
		}

		// Note: This includes the aliasing of `workbox` to `wp.serviceWorker`.
		$script .= file_get_contents( PWA_PLUGIN_DIR . '/wp-includes/js/service-worker.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return $script;
	}

	/**
	 * Register service worker script.
	 *
	 * Registers service worker if no item of that name already exists.
	 *
	 * @param string          $handle Name of the item. Should be unique.
	 * @param string|callable $src    URL to the source in the WordPress install, or a callback that returns the JS to include in the service worker.
	 * @param array           $deps   Optional. An array of registered item handles this item depends on. Default empty array.
	 * @param int             $scope  Scope for which service worker the script will be part of. Can be WP_Service_Workers::SCOPE_FRONT, WP_Service_Workers::SCOPE_ADMIN, or WP_Service_Workers::SCOPE_ALL. Default to WP_Service_Workers::SCOPE_ALL.
	 * @return bool Whether the item has been registered. True on success, false on failure.
	 */
	public function register_script( $handle, $src, $deps = array(), $scope = self::SCOPE_ALL ) {
		$valid_scopes = array( self::SCOPE_FRONT, self::SCOPE_ADMIN, self::SCOPE_ALL );

		if ( ! in_array( $scope, $valid_scopes, true ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s is a comma-separated list of valid scopes */
					esc_html__( 'Scope must be one out of %s.', 'pwa' ),
					esc_html( implode( ', ', $valid_scopes ) )
				),
				'0.1'
			);

			$scope = self::SCOPE_ALL;
		}

		return parent::add( $handle, $src, $deps, false, compact( 'scope' ) );
	}

	/**
	 * Register service worker script (deprecated).
	 *
	 * @deprecated Use the register_script() method instead.
	 *
	 * @param string          $handle Name of the item. Should be unique.
	 * @param string|callable $src    URL to the source in the WordPress install, or a callback that returns the JS to include in the service worker.
	 * @param array           $deps   Optional. An array of registered item handles this item depends on. Default empty array.
	 * @param int             $scope  Scope for which service worker the script will be part of. Can be WP_Service_Workers::SCOPE_FRONT, WP_Service_Workers::SCOPE_ADMIN, or WP_Service_Workers::SCOPE_ALL. Default to WP_Service_Workers::SCOPE_ALL.
	 * @return bool Whether the item has been registered. True on success, false on failure.
	 */
	public function register( $handle, $src, $deps = array(), $scope = self::SCOPE_ALL ) {
		_deprecated_function( __METHOD__, '0.2', __CLASS__ . '::register_script' );
		return $this->register_script( $handle, $src, $deps, $scope );
	}

	/**
	 * Register route and caching strategy (deprecated).
	 *
	 * @deprecated Use the WP_Service_Worker_Cache_Registry::register_cached_route() method instead.
	 *
	 * @param string $route    Route regular expression, without delimiters.
	 * @param string $strategy Strategy, can be WP_Service_Workers::STRATEGY_STALE_WHILE_REVALIDATE, WP_Service_Workers::STRATEGY_CACHE_FIRST,
	 *                         WP_Service_Workers::STRATEGY_NETWORK_FIRST, WP_Service_Workers::STRATEGY_CACHE_ONLY,
	 *                         WP_Service_Workers::STRATEGY_NETWORK_ONLY.
	 * @param array  $strategy_args {
	 *     An array of strategy arguments.
	 *
	 *     @type string $cache_name Cache name. Optional.
	 *     @type array  $plugins    Array of plugins with configuration. The key of each plugin in the array must match the plugin's name.
	 *                              See https://developers.google.com/web/tools/workbox/guides/using-plugins#workbox_plugins.
	 * }
	 */
	public function register_cached_route( $route, $strategy, $strategy_args = array() ) {
		_deprecated_function( __METHOD__, '0.2', 'WP_Service_Worker_Cache_Registry::register_cached_route' );
		$this->cache_registry->register_cached_route( $route, $strategy, $strategy_args );
	}

	/**
	 * Register precached route (deprecated).
	 *
	 * @deprecated Use the WP_Service_Worker_Cache_Registry::register_precached_route() method instead.
	 *
	 * If a registered route is stored in the precache cache, then it will be served with the cache-first strategy.
	 * For other routes registered with non-precached routes (e.g. runtime), you must currently also call
	 * `wp_service_workers()->register_cached_route(...)` to specify the strategy for interacting with that
	 * precached resource.
	 *
	 * @see WP_Service_Workers::register_cached_route()
	 * @link https://github.com/GoogleChrome/workbox/issues/1612
	 *
	 * @param string       $url URL to cache.
	 * @param array|string $options {
	 *     Options. Or else if not an array, then treated as revision.
	 *
	 *     @type string $revision Revision. Currently only applicable for precache. Optional.
	 *     @type string $cache    Cache. Defaults to the precache (WP_Service_Workers::PRECACHE_CACHE_NAME); the values 'precache' and 'runtime' will be replaced with the appropriately-namespaced cache names.
	 * }
	 */
	public function register_precached_route( $url, $options = array() ) {
		_deprecated_function( __METHOD__, '0.2', 'WP_Service_Worker_Cache_Registry::register_precached_route' );
		$this->cache_registry->register_precached_route( $url, $options );
	}

	/**
	 * Register routes / files for precaching.
	 *
	 * @deprecated Use WP_Service_Worker_Cache_Registry::register_precached_route() method instead.
	 *
	 * @param array $routes Routes.
	 */
	public function register_precached_routes( $routes ) {
		_deprecated_function( __METHOD__, '0.2', 'WP_Service_Worker_Cache_Registry::register_precached_route' );

		if ( ! is_array( $routes ) ) {
			_doing_it_wrong( __METHOD__, esc_html__( 'Routes must be an array.', 'pwa' ), '0.2' );
			return;
		}

		foreach ( $routes as $options ) {
			$url = '';
			if ( isset( $options['url'] ) ) {
				$url = $options['url'];
				unset( $options['url'] );
			}

			$this->cache_registry->register_precached_route( $url, $options );
		}
	}

	/**
	 * Gets the script for precaching routes.
	 *
	 * @return string Precaching logic.
	 */
	protected function get_precaching_for_routes_script() {
		$precache_entries = $this->cache_registry->get_precached_routes();
		if ( empty( $precache_entries ) ) {
			return '';
		}

		$replacements = array(
			'PRECACHE_ENTRIES' => $this->json_encode( $precache_entries ),
		);

		$script = file_get_contents( PWA_PLUGIN_DIR . '/wp-includes/js/service-worker-precaching.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$script = preg_replace( '#/\*\s*global.+?\*/#', '', $script );

		return str_replace(
			array_keys( $replacements ),
			array_values( $replacements ),
			$script
		);
	}

	/**
	 * Get the caching strategy script for route.
	 *
	 * @param string $route Route.
	 * @param int    $strategy Caching strategy.
	 * @param array  $strategy_args {
	 *     An array of strategy arguments. If argument keys are supplied in snake_case, they'll be converted to camelCase for JS.
	 *
	 *     @type string $cache_name    Cache name to store and retrieve requests.
	 *     @type array  $plugins       Array of plugins with configuration. The key of each plugin must match the plugins name, with values being strategy options. Optional.
	 *                                 See https://developers.google.com/web/tools/workbox/guides/using-plugins#workbox_plugins.
	 *     @type array  $fetch_options Fetch options. Not supported by cacheOnly strategy. Optional.
	 *     @type array  $match_options Match options. Not supported by networkOnly strategy. Optional.
	 * }
	 * @return string Script.
	 */
	protected function get_caching_for_routes_script( $route, $strategy, $strategy_args ) {
		$script = '{'; // Begin lexical scope.

		// Extract plugins since not JSON-serializable as-is.
		$plugins = array();
		if ( isset( $strategy_args['plugins'] ) ) {
			$plugins = $strategy_args['plugins'];
			unset( $strategy_args['plugins'] );
		}

		$exported_strategy_args = array();
		foreach ( $strategy_args as $strategy_arg_name => $strategy_arg_value ) {
			if ( false !== strpos( $strategy_arg_name, '_' ) ) {
				$strategy_arg_name = preg_replace_callback( '/_[a-z]/', array( $this, 'convert_snake_case_to_camel_case_callback' ), $strategy_arg_name );
			}
			$exported_strategy_args[ $strategy_arg_name ] = $strategy_arg_value;
		}

		$script .= sprintf( 'const strategyArgs = %s;', empty( $exported_strategy_args ) ? '{}' : $this->json_encode( $exported_strategy_args ) );

		if ( is_array( $plugins ) ) {

			$recognized_plugins = array(
				'backgroundSync',
				'broadcastUpdate',
				'cacheableResponse',
				'expiration',
				'rangeRequests',
			);

			$plugins_js = array();
			foreach ( $plugins as $plugin_name => $plugin_args ) {
				if ( false !== strpos( $plugin_name, '_' ) ) {
					$plugin_name = preg_replace_callback( '/_[a-z]/', array( $this, 'convert_snake_case_to_camel_case_callback' ), $plugin_name );
				}

				if ( ! in_array( $plugin_name, $recognized_plugins, true ) ) {
					_doing_it_wrong( 'WP_Service_Workers::register_cached_route', esc_html__( 'Unrecognized plugin', 'pwa' ), '0.2' );
				} else {
					$plugins_js[] = sprintf(
						'new wp.serviceWorker[ %s ].Plugin( %s )',
						$this->json_encode( $plugin_name ),
						empty( $plugin_args ) ? '{}' : $this->json_encode( $plugin_args )
					);
				}
			}

			$script .= sprintf( 'strategyArgs.plugins = [%s];', implode( ', ', $plugins_js ) );
		}

		$script .= sprintf(
			'wp.serviceWorker.routing.registerRoute( new RegExp( %s ), wp.serviceWorker.strategies[ %s ]( strategyArgs ) );',
			$this->json_encode( $route ),
			$this->json_encode( $strategy )
		);

		$script .= '}'; // End lexical scope.

		return $script;
	}

	/**
	 * Convert snake_case to camelCase.
	 *
	 * This is is used by `preg_replace_callback()` for the pattern /_[a-z]/.
	 *
	 * @see WP_Service_Workers::get_caching_for_routes_script()
	 * @param array $matches Matches.
	 * @return string Replaced string.
	 */
	protected function convert_snake_case_to_camel_case_callback( $matches ) {
		return strtoupper( ltrim( $matches[0], '_' ) );
	}

	/**
	 * Get service worker logic for scope.
	 *
	 * @see wp_service_worker_loaded()
	 * @param int $scope Scope of the Service Worker.
	 */
	public function serve_request( $scope ) {
		/*
		 * Per Workbox <https://developers.google.com/web/tools/workbox/guides/service-worker-checklist#cache-control_of_your_service_worker_file>:
		 * "Generally, most developers will want to set the Cache-Control header to no-cache,
		 * forcing browsers to always check the server for a new service worker file."
		 * Nevertheless, an ETag header is also sent with support for Conditional Requests
		 * to save on needlessly re-downloading the same service worker with each page load.
		 */
		@header( 'Cache-Control: no-cache' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged

		@header( 'Content-Type: text/javascript; charset=utf-8' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged

		if ( self::SCOPE_FRONT === $scope ) {
			wp_enqueue_scripts();

			/**
			 * Fires before serving the frontend service worker, when its scripts should be registered, caching routes established, and assets precached.
			 *
			 * The following integrations are hooked into this action by default: 'wp-site-icon', 'wp-custom-logo', 'wp-custom-header', 'wp-custom-background',
			 * 'wp-scripts', 'wp-styles', and 'wp-fonts'. This default behavior can be disabled with code such as the following, for disabling the
			 * 'wp-custom-header' integration:
			 *
			 *     add_filter( 'wp_service_worker_integrations', function( $integrations ) {
			 *         unset( $integrations['wp-custom-header'] );
			 *         return $integrations;
			 *     } );
			 *
			 * @since 0.2
			 *
			 * @param WP_Service_Worker_Cache_Registry $cache_registry Instance to register service worker behavior with.
			 */
			do_action( 'wp_front_service_worker', $this->cache_registry );
		} elseif ( self::SCOPE_ADMIN === $scope ) {
			/**
			 * Fires before serving the wp-admin service worker, when its scripts should be registered, caching routes established, and assets precached.
			 *
			 * @since 0.2
			 *
			 * @param WP_Service_Worker_Cache_Registry $cache_registry Instance to register service worker behavior with.
			 */
			do_action( 'wp_admin_service_worker', $this->cache_registry );
		}

		/**
		 * Fires before serving the service worker (both front and admin), when its scripts should be registered, caching routes established, and assets precached.
		 *
		 * @since 0.2
		 *
		 * @param WP_Service_Worker_Cache_Registry $cache_registry Instance to register service worker behavior with.
		 */
		do_action( 'wp_service_worker', $this->cache_registry );

		if ( self::SCOPE_FRONT !== $scope && self::SCOPE_ADMIN !== $scope ) {
			status_header( 400 );
			echo '/* invalid_scope_requested */';
			return;
		}

		printf( "/* PWA v%s */\n\n", esc_html( PWA_VERSION ) );

		$this->output  = '';
		$this->output .= $this->get_base_script();
		$this->output .= $this->get_error_response_handling_script();

		// Get handles from the relevant scope only.
		$scope_items = array();
		foreach ( $this->registered as $handle => $item ) {
			if ( $item->args['scope'] & $scope ) { // Yes, Bitwise AND intended. SCOPE_ALL & SCOPE_FRONT == true. SCOPE_ADMIN & SCOPE_FRONT == false.
				$scope_items[] = $handle;
			}
		}

		$this->do_items( $scope_items );
		$this->output .= $this->get_precaching_for_routes_script();

		$caching_routes = $this->cache_registry->get_cached_routes();
		foreach ( $caching_routes as $caching_route ) {
			$this->output .= $this->get_caching_for_routes_script( $caching_route['route'], $caching_route['strategy'], $caching_route['strategy_args'] );
		}

		$file_hash = md5( $this->output );
		@header( "ETag: $file_hash" ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged

		$etag_header = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( $_SERVER['HTTP_IF_NONE_MATCH'] ) : false;
		if ( $file_hash === $etag_header ) {
			status_header( 304 );
			return;
		}

		echo $this->output; // phpcs:ignore WordPress.XSS.EscapeOutput, WordPress.Security.EscapeOutput
	}

	/**
	 * Process one registered script.
	 *
	 * @param string $handle Handle.
	 * @param bool   $group Group. Unused.
	 * @return void
	 */
	public function do_item( $handle, $group = false ) {
		$registered = $this->registered[ $handle ];
		$invalid    = false;

		if ( is_callable( $registered->src ) ) {
			$this->output .= sprintf( "\n/* Source %s: */\n", $handle );
			$this->output .= call_user_func( $registered->src ) . "\n";
		} elseif ( is_string( $registered->src ) ) {
			$validated_path = $this->get_validated_file_path( $registered->src );
			if ( is_wp_error( $validated_path ) ) {
				$invalid = true;
			} else {
				/* translators: %s is file URL */
				$this->output .= sprintf( "\n/* Source %s <%s>: */\n", $handle, $registered->src );
				$this->output .= @file_get_contents( $validated_path ) . "\n"; // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents
			}
		} else {
			$invalid = true;
		}

		if ( $invalid ) {
			/* translators: %s is script handle */
			$error = sprintf( __( 'Service worker src is invalid for handle "%s".', 'pwa' ), $handle );
			@_doing_it_wrong( 'WP_Service_Workers::register', esc_html( $error ), '0.1' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- We want the error in the PHP log, but not in the JS output.
			$this->output .= sprintf( "console.warn( %s );\n", $this->json_encode( $error ) );
		}
	}

	/**
	 * Get validated path to file.
	 *
	 * @param string $url Relative path.
	 * @return string|WP_Error
	 */
	public function get_validated_file_path( $url ) {
		$needs_base_url = (
			! is_bool( $url )
			&&
			! preg_match( '|^(https?:)?//|', $url )
			&&
			! ( $this->content_url && 0 === strpos( $url, $this->content_url ) )
		);
		if ( $needs_base_url ) {
			$url = $this->base_url . $url;
		}

		$url_scheme_pattern = '#^\w+:(?=//)#';

		// Strip URL scheme, query, and fragment.
		$url = preg_replace( $url_scheme_pattern, '', preg_replace( ':[\?#].*$:', '', $url ) );

		$includes_url = preg_replace( $url_scheme_pattern, '', includes_url( '/' ) );
		$content_url  = preg_replace( $url_scheme_pattern, '', content_url( '/' ) );
		$admin_url    = preg_replace( $url_scheme_pattern, '', get_admin_url( null, '/' ) );

		$allowed_hosts = array(
			wp_parse_url( $includes_url, PHP_URL_HOST ),
			wp_parse_url( $content_url, PHP_URL_HOST ),
			wp_parse_url( $admin_url, PHP_URL_HOST ),
		);

		$url_host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! in_array( $url_host, $allowed_hosts, true ) ) {
			/* translators: %s is file URL */
			return new WP_Error( 'external_file_url', sprintf( __( 'URL is located on an external domain: %s.', 'pwa' ), $url_host ) );
		}

		$base_path = null;
		$file_path = null;
		if ( 0 === strpos( $url, $content_url ) ) {
			$base_path = WP_CONTENT_DIR;
			$file_path = substr( $url, strlen( $content_url ) - 1 );
		} elseif ( 0 === strpos( $url, $includes_url ) ) {
			$base_path = ABSPATH . WPINC;
			$file_path = substr( $url, strlen( $includes_url ) - 1 );
		} elseif ( 0 === strpos( $url, $admin_url ) ) {
			$base_path = ABSPATH . 'wp-admin';
			$file_path = substr( $url, strlen( $admin_url ) - 1 );
		}

		if ( ! $file_path || false !== strpos( $file_path, '../' ) || false !== strpos( $file_path, '..\\' ) ) {
			/* translators: %s is file URL */
			return new WP_Error( 'file_path_not_allowed', sprintf( __( 'Disallowed URL filesystem path for %s.', 'pwa' ), $url ) );
		}
		if ( ! file_exists( $base_path . $file_path ) ) {
			/* translators: %s is file URL */
			return new WP_Error( 'file_path_not_found', sprintf( __( 'Unable to locate filesystem path for %s.', 'pwa' ), $url ) );
		}

		return $base_path . $file_path;
	}

	/**
	 * JSON encode with pretty printing.
	 *
	 * @param mixed $data Data.
	 * @return string JSON.
	 */
	protected function json_encode( $data ) {
		return wp_json_encode( $data, 128 | 64 /* JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES */ );
	}
}
