<?php
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

/*------------------------------------------------------------------------------------------------*/
/* REQUIRE FILES ================================================================================ */
/*------------------------------------------------------------------------------------------------*/

/**
 * Return the path to a class.
 *
 * @since 1.0
 *
 * @param (string) $prefix          Only one possible value so far: "scan".
 * @param (string) $class_name_part The classes name is built as follow: "SecuPress_{$prefix}_{$class_name_part}".
 *
 * @return (string) Path of the class.
 */
function secupress_class_path( $prefix, $class_name_part = '' ) {
	$folders = array(
		'scan'      => 'scanners',
		'singleton' => 'common',
		'logs'      => 'common',
		'log'       => 'common',
	);

	$prefix = strtolower( str_replace( '_', '-', $prefix ) );
	$folder = isset( $folders[ $prefix ] ) ? $folders[ $prefix ] : $prefix;

	$class_name_part = strtolower( str_replace( '_', '-', $class_name_part ) );
	$class_name_part = $class_name_part ? '-' . $class_name_part : '';

	return SECUPRESS_CLASSES_PATH . $folder . '/class-secupress-' . $prefix . $class_name_part . '.php';
}


/**
 * Require a class.
 *
 * @since 1.0
 *
 * @param (string) $prefix          Only one possible value so far: "scan".
 * @param (string) $class_name_part The classes name is built as follow: "SecuPress_{$prefix}_{$class_name_part}".
 */
function secupress_require_class( $prefix, $class_name_part = '' ) {
	$path = secupress_class_path( $prefix, $class_name_part );

	if ( $path ) {
		require_once( $path );
	}
}


/**
 * Will load the async classes.
 *
 * @since 1.0
 */
function secupress_require_class_async() {
	/* https://github.com/A5hleyRich/wp-background-processing v1.0 */
	secupress_require_class( 'Admin', 'wp-async-request' );
	secupress_require_class( 'Admin', 'wp-background-process' );
}


/*------------------------------------------------------------------------------------------------*/
/* SCAN / FIX =================================================================================== */
/*------------------------------------------------------------------------------------------------*/

/**
 * Return all tests to scan
 *
 * @since 1.0
 *
 * @return (array) Tests to scan.
 */
function secupress_get_scanners() {
	$tests = array(
		'users-login' => array(
			'Admin_User',
			'Easy_Login',
			'Subscription',
			'Passwords_Strength',
			'Bad_Usernames',
			'Login_Errors_Disclose',
		),
		'plugins-themes' => array(
			'Plugins_Update',
			'Themes_Update',
			'Bad_Old_Plugins',
			'Bad_Vuln_Plugins',
			'Inactive_Plugins_Themes',
		),
		'wordpress-core' => array(
			'Core_Update',
			'Auto_Update',
			'Bad_Old_Files',
			'Bad_Config_Files',
			'WP_Config',
			'DB_Prefix',
			'Salt_Keys',
		),
		'sensitive-data' => array(
			'Discloses',
			'Readme_Discloses',
			'PHP_Disclosure',
		),
		'file-system' => array(
			'Chmods',
			'Directory_Listing',
			'Bad_File_Extensions',
			'DirectoryIndex',
		),
		'firewall' => array(
			'Common_Flaws',
			'Bad_User_Agent',
			'SQLi',
			'Anti_Scanner',
			'Anti_Front_Bruteforce',
			'Bad_Request_Methods',
			'Block_HTTP_1_0',
			'Block_Long_URL',
			'Bad_Url_Access',
			'PhpVersion',
		),
	);

	if ( ! secupress_users_can_register() ) {
		$tests['users-login'][] = 'Non_Login_Time_Slot';
	}

	if ( class_exists( 'SitePress' ) ) {
		$tests['sensitive-data'][] = 'Wpml_Discloses';
	}

	if ( class_exists( 'WooCommerce' ) ) {
		$tests['sensitive-data'][] = 'Woocommerce_Discloses';
	}

	return $tests;
}


/**
 * Get tests that can't be fixes from the network admin.
 *
 * @since 1.0
 *
 * @return (array) Array of "class name parts".
 */
function secupress_get_tests_for_ms_scanner_fixes() {
	return array(
		'Bad_Old_Plugins',
		'Subscription',
	);
}


/**
 * Get SecuPress scanner counter(s).
 *
 * @since 1.0
 *
 * @param (string) $type Info to retrieve: good, warning, bad, notscannedyet, grade, total.
 *
 * @return (string|array) The desired counter info if `$type` is provided and the info exists. An array of all counters otherwise.
 */
function secupress_get_scanner_counts( $type = '' ) {
	$tests_by_status = secupress_get_scanners();
	$scanners        = secupress_get_scan_results();
	$fixes           = secupress_get_fix_results();
	$empty_statuses  = array( 'good' => 0, 'warning' => 0, 'bad' => 0 );
	$scanners_count  = $scanners ? array_count_values( wp_list_pluck( $scanners, 'status' ) ) : array();
	$counts          = array_merge( $empty_statuses, $scanners_count );
	$total           = array_sum( array_map( 'count', $tests_by_status ) );

	$counts['notscannedyet'] = $total - array_sum( $counts );
	$counts['total']         = $total;
	$counts['percent']       = (int) floor( $counts['good'] * 100 / $counts['total'] );
	$counts['hasaction']     = 0;

	if ( $fixes ) {
		foreach ( $fixes as $test_name => $fix ) {
			if ( ! empty( $fix['has_action'] ) ) {
				++$counts['hasaction'];
			}
		}
	}

	if ( 100 === $counts['percent'] ) {
		$counts['grade'] = 'A';
	} elseif ( $counts['percent'] >= 80 ) { // 20 less
		$counts['grade'] = 'B';
	} elseif ( $counts['percent'] >= 65 ) { // 15 less
		$counts['grade'] = 'C';
	} elseif ( $counts['percent'] >= 52 ) { // 13 less
		$counts['grade'] = 'D';
	} elseif ( $counts['percent'] >= 42 ) { // 10 less
		$counts['grade'] = 'E';
	} elseif ( $counts['percent'] >= 34 ) { // 8 less
		$counts['grade'] = 'F';
	} elseif ( $counts['percent'] >= 28 ) { // 6 less
		$counts['grade'] = 'G';
	} elseif ( $counts['percent'] >= 22 ) { // 6 less
		$counts['grade'] = 'H';
	} elseif ( $counts['percent'] >= 16 ) { // 6 less
		$counts['grade'] = 'I';
	} elseif ( $counts['percent'] >= 10 ) { // 6 less
		$counts['grade'] = 'J';
	} elseif ( 0 === $counts['percent'] ) { // (ᕗ‶⇀︹↼)ᕗノ彡┻━┻
		$counts['grade'] = '∅';
	} else {
		$counts['grade'] = 'K'; // < 10 %
	}

	$counts['letter'] = '<span class="letter l' . $counts['grade'] . '">' . $counts['grade'] . '</span>';

	switch ( $counts['grade'] ) {
		case 'A':
			$counts['text'] = __( 'Congratulations! 🎉', 'secupress' );
			break;
		case 'B':
			$counts['text'] = __( 'Almost perfect!', 'secupress' );
			break;
		case 'C':
			$counts['text'] = __( 'Not bad, but try to fix more.', 'secupress' );
			break;
		case 'D':
			$counts['text'] = __( 'Well, it\'s not good yet.', 'secupress' );
			break;
		case 'E':
			$counts['text'] = __( 'Better than nothing, but still not good.', 'secupress' );
			break;
		case 'F':
			$counts['text'] = __( 'Not good at all, fix more things.', 'secupress' );
			break;
		case 'G':
			$counts['text'] = __( 'Bad, do not hesitate to fix!', 'secupress' );
			break;
		case 'H':
			$counts['text'] = __( 'Still very bad, start to fix things!', 'secupress' );
			break;
		case 'I':
			$counts['text'] = __( 'Very bad. You should move on.', 'secupress' );
			break;
		case 'J':
			$counts['text'] = __( 'Very very bad, please fix something!', 'secupress' );
			break;
		case 'K':
			$counts['text'] = __( 'Very very, really very bad.', 'secupress' );
			break;
		case '∅':
			$counts['text'] = '(ᕗ‶⇀︹↼)ᕗノ彡┻━┻'; // Easter egg if you got 0% (how is this possible oO).
			break;
	}

	$counts['subtext'] = sprintf( _n( 'Your note is %s — %d scanned item is good.', 'Your note is %s — %d scanned items are good.', $counts['good'], 'secupress' ), $counts['letter'], $counts['good'] );

	if ( isset( $counts[ $type ] ) ) {
		return $counts[ $type ];
	}

	return $counts;
}


/*------------------------------------------------------------------------------------------------*/
/* PLUGINS ====================================================================================== */
/*------------------------------------------------------------------------------------------------*/

/**
 * Check whether a plugin is active.
 *
 * @since 1.0
 *
 * @param (string) $plugin A plugin path, relative to the plugins folder.
 *
 * @return (bool)
 */
function secupress_is_plugin_active( $plugin ) {
	$plugins = (array) get_option( 'active_plugins', array() );
	$plugins = array_flip( $plugins );
	return isset( $plugins[ $plugin ] ) || secupress_is_plugin_active_for_network( $plugin );
}


/**
 * Check whether a plugin is active for the entire network.
 *
 * @since 1.0
 *
 * @param (string) $plugin A plugin path, relative to the plugins folder.
 *
 * @return (bool)
 */
function secupress_is_plugin_active_for_network( $plugin ) {
	if ( ! is_multisite() ) {
		return false;
	}

	$plugins = get_site_option( 'active_sitewide_plugins' );

	return isset( $plugins[ $plugin ] );
}


/*------------------------------------------------------------------------------------------------*/
/* DIE ========================================================================================== */
/*------------------------------------------------------------------------------------------------*/

/**
 * Die with SecuPress format.
 *
 * @since 1.0
 *
 * @param (string) $message Guess what.
 * @param (string) $title   Window title.
 * @param (array)  $args    An array of arguments.
 */
function secupress_die( $message = '', $title = '', $args = array() ) {
	$has_p   = strpos( $message, '<p>' ) !== false;
	$message = ( $has_p ? '' : '<p>' ) . $message . ( $has_p ? '' : '</p>' );
	$message = '<h1>' . SECUPRESS_PLUGIN_NAME . '</h1>' . $message;
	$url     = secupress_get_current_url( 'raw' );

	/**
	 * Filter the message.
	 *
	 * @since 1.0
	 *
	 * @param (string) $message The message displayed.
	 * @param (string) $url     The current URL.
	 * @param (array)  $args    Facultative arguments.
	 */
	$message = apply_filters( 'secupress.die.message', $message, $url, $args );

	/**
	 * Fires right before `wp_die()`.
	 *
	 * @since 1.0
	 *
	 * @param (string) $message The message displayed.
	 * @param (string) $url     The current URL.
	 * @param (array)  $args    Facultative arguments.
	 */
	do_action( 'secupress.before.die', $message, $url, $args );

	wp_die( $message, $title, $args );
}


/**
 * Block a request and die with more informations.
 *
 * @since 1.0
 *
 * @param (string)           $module The related module.
 * @param (array|int|string) $args   Contains the "code" (def. 403) and a "content" (def. empty), this content will replace the default message.
 *                                   $args can be used only for the "code" or "content" or both using an array.
 */
function secupress_block( $module, $args = array( 'code' => 403 ) ) {

	if ( is_int( $args ) ) {
		$args = array( 'code' => $args );
	} elseif ( is_string( $args ) ) {
		$args = array( 'content' => $args );
	}

	$ip   = secupress_get_ip();
	$args = wp_parse_args( $args, array( 'code' => 403, 'content' => '' ) );

	/**
	 * Fires before a user is blocked by a certain module.
	 *
	 * @since 1.0
	 *
	 * @param (string) $ip   The IP address.
	 * @param (array)  $args Contains the "code" (def. 403) and a "content" (def. empty), this content will replace the default message.
	 */
	do_action( 'secupress.block.' . $module, $ip, $args );

	/**
	 * Fires before a user is blocked.
	 *
	 * @since 1.0
	 *
	 * @param (string) $module The module.
	 * @param (string) $ip     The IP address.
	 * @param (array)  $args   Contains the "code" (def. 403) and a "content" (def. empty), this content will replace the default message.
	 */
	do_action( 'secupress.block', $module, $ip, $args );

	$module = ucwords( str_replace( '-', ' ', $module ) );
	$module = preg_replace( '/[^0-9A-Z]/', '', $module );

	$title   = $args['code'] . ' ' . get_status_header_desc( $args['code'] );
	$content = '<h4>' . $title . '</h4>';

	if ( ! $args['content'] ) {
		$content .= '<p>' . __( 'You are not allowed to access the requested page.', 'secupress' ) . '</p>';
	} else {
		$content .= '<p>' . $args['content'] . '</p>';
	}

	$content  = '<h4>' . __( 'Logged Details:', 'secupress' ) . '</h4><p>';
	$content .= sprintf( __( 'Your IP: %s', 'secupress' ), $ip ) . '<br>';
	$content .= sprintf( __( 'Time: %s', 'secupress' ), date_i18n( __( 'F j, Y g:i a' ) ) ) . '<br>';
	$content .= sprintf( __( 'Block ID: %s', 'secupress' ), $module ) . '</p>';

	secupress_die( $content, $title, array( 'response' => $args['code'] ) );
}


/*------------------------------------------------------------------------------------------------*/
/* OTHER TOOLS ================================================================================== */
/*------------------------------------------------------------------------------------------------*/

/**
 * Create a URL to easily access to our pages.
 *
 * @since 1.0
 *
 * @param (string) $page   The last word of the secupress page slug.
 * @param (string) $module The required module.
 *
 * @return (string) The URL.
 */
function secupress_admin_url( $page, $module = '' ) {
	$module = $module ? '&module=' . $module : '';
	$page   = str_replace( '&', '_', $page );
	$url    = 'admin.php?page=' . SECUPRESS_PLUGIN_SLUG . '_' . $page . $module;

	return is_multisite() ? network_admin_url( $url ) : admin_url( $url );
}


/**
 * Get the user capability required to work with the plugin.
 *
 * @since 1.0
 *
 * @param (bool) $force_mono Set to true to get the capability for monosite, whatever we're on multisite or not.
 *
 * @return (string) The capability.
 */
function secupress_get_capability( $force_mono = false ) {
	if ( $force_mono ) {
		return 'administrator';
	}
	return is_multisite() ? 'manage_network_options' : 'administrator';
}


/**
 * Get SecuPress logo.
 *
 * @since 1.0
 *
 * @param (array) $atts An array of HTML attributes.
 *
 * @return (string) The HTML tag.
 */
function secupress_get_logo( $atts = array() ) {
	$base_url = SECUPRESS_ADMIN_IMAGES_URL . 'logo' . ( secupress_is_pro() ? '-pro' : '' );

	$atts = array_merge( array(
		'src'    => "{$base_url}.png",
		'srcset' => "{$base_url}2x.svg 1x, {$base_url}2x.svg 2x",
		'alt'    => '',
	), $atts );

	$attributes = '';

	foreach ( $atts as $att => $value ) {
		$attributes .= " {$att}=\"{$value}\"";
	}

	return "<img{$attributes}/>";
}

/**
 * Get SecuPress logo word.
 *
 * @since 1.0
 *
 * @param (array) $atts An array of HTML attributes.
 *
 * @return (string) The HTML tag.
 */
function secupress_get_logo_word( $atts = array() ) {

	if ( SECUPRESS_PLUGIN_NAME !== 'SecuPress' ) {
		return SECUPRESS_PLUGIN_NAME;
	}

	$base_url = SECUPRESS_ADMIN_IMAGES_URL . 'secupress-word';

	$atts = array_merge( array(
		'src'    => "{$base_url}.png",
		'srcset' => "{$base_url}.svg 1x, {$base_url}.svg 2x",
		'alt'    => 'SecuPress',
	), $atts );

	$attributes = '';

	foreach ( $atts as $att => $value ) {
		$attributes .= " {$att}=\"{$value}\"";
	}

	return "<img{$attributes}/>";
}


/**
 * Tell if users can register, whatever we're in a Multisite or not.
 *
 * @since 1.0
 *
 * @return (bool)
 */
function secupress_users_can_register() {
	if ( ! is_multisite() ) {
		return (bool) get_option( 'users_can_register' );
	}

	$registration = get_site_option( 'registration' );

	return 'user' === $registration || 'all' === $registration;
}


/**
 * Get the email address used when the plugin send a message.
 *
 * @since 1.0
 *
 * @param (bool) $from_header True to return the "from" header.
 *
 * @return (string)
 */
function secupress_get_email( $from_header = false ) {
	$sitename = strtolower( $_SERVER['SERVER_NAME'] );

	if ( substr( $sitename, 0, 4 ) === 'www.' ) {
		$sitename = substr( $sitename, 4 );
	}

	$email = 'noreply@' . $sitename;

	return $from_header ? 'from: ' . SECUPRESS_PLUGIN_NAME . ' <' . $email . '>' : $email;
}


/**
 * Return the current URL.
 *
 * @since 1.0
 *
 * @param (string) $mode What to return: raw (all), base (before '?'), uri (before '?', without the domain).
 *
 * @return (string)
 */
function secupress_get_current_url( $mode = 'base' ) {
	$mode = (string) $mode;
	$port = (int) $_SERVER['SERVER_PORT'];
	$port = 80 !== $port && 443 !== $port ? ( ':' . $port ) : '';
	$url  = ! empty( $GLOBALS['HTTP_SERVER_VARS']['REQUEST_URI'] ) ? $GLOBALS['HTTP_SERVER_VARS']['REQUEST_URI'] : ( ! empty( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' );
	$url  = 'http' . ( is_ssl() ? 's' : '' ) . '://' . $_SERVER['HTTP_HOST'] . $port . $url;

	switch ( $mode ) :
		case 'raw' :
			return $url;
		case 'uri' :
			$home = set_url_scheme( home_url() );
			$url  = explode( '?', $url, 2 );
			$url  = reset( $url );
			$url  = str_replace( $home, '', $url );
			return trim( $url, '/' );
		default :
			$url  = explode( '?', $url, 2 );
			return reset( $url );
	endswitch;
}


/**
 * Store, get or delete static data.
 * Getter:   no need to provide a second parameter.
 * Setter:   provide a second parameter for the value.
 * Deletter: provide null as second parameter to remove the previous value.
 *
 * @since 1.0
 *
 * @param (string) $key An identifier key.
 *
 * @return (mixed) The stored data or null.
 */
function secupress_cache_data( $key ) {
	static $data = array();

	$func_get_args = func_get_args();

	if ( array_key_exists( 1, $func_get_args ) ) {
		if ( null === $func_get_args[1] ) {
			unset( $data[ $key ] );
		} else {
			$data[ $key ] = $func_get_args[1];
		}
	}

	return isset( $data[ $key ] ) ? $data[ $key ] : null;
}


/**
 * Get the main blog ID.
 *
 * @since 1.0
 *
 * @return (int)
 */
function secupress_get_main_blog_id() {
	static $blog_id;

	if ( ! isset( $blog_id ) ) {
		if ( ! is_multisite() ) {
			$blog_id = 1;
		}
		elseif ( ! empty( $GLOBALS['current_site']->blog_id ) ) {
			$blog_id = absint( $GLOBALS['current_site']->blog_id );
		}
		elseif ( defined( 'BLOG_ID_CURRENT_SITE' ) ) {
			$blog_id = absint( BLOG_ID_CURRENT_SITE );
		}
		$blog_id = ! empty( $blog_id ) ? $blog_id : 1;
	}

	return $blog_id;
}


/**
 * Is current WordPress version older than X.X.X?
 *
 * @since 1.0
 *
 * @param (string) $version The version to test.
 *
 * @return (bool) Result of the `version_compare()`.
 */
function secupress_wp_version_is( $version ) {
	global $wp_version;
	static $is = array();

	if ( isset( $is[ $version ] ) ) {
		return $is[ $version ];
	}

	return ( $is[ $version ] = version_compare( $wp_version, $version ) >= 0 );
}


/**
 * Check whether WordPress is in "installation" mode.
 *
 * @since 1.0
 *
 * @return (bool) true if WP is installing, otherwise false.
 */
function secupress_wp_installing() {
	return function_exists( 'wp_installing' ) ? wp_installing() : defined( 'WP_INSTALLING' ) && WP_INSTALLING;
}


/**
 * Tell if the site frontend is served over SSL.
 *
 * @since 1.0
 *
 * @return (bool)
 **/
function secupress_is_site_ssl() {
	static $is_site_ssl;

	if ( isset( $is_site_ssl ) ) {
		return $is_site_ssl;
	}

	if ( is_multisite() ) {
		switch_to_blog( secupress_get_main_blog_id() );
		$site_url = get_option( 'siteurl' );
		$home_url = get_option( 'home' );
		restore_current_blog();
	} else {
		$site_url = get_option( 'siteurl' );
		$home_url = get_option( 'home' );
	}

	$is_site_ssl = strpos( $site_url, 'https://' ) === 0 && strpos( $home_url, 'https://' ) === 0;
	/**
	 * Filter the value of `$is_site_ssl`, that tells if the site frontend is served over SSL.
	 *
	 * @since 1.0
	 *
	 * @param (bool) $is_site_ssl True if the site frontend is served over SSL.
	 */
	$is_site_ssl = apply_filters( 'secupress.front.is_site_ssl', $is_site_ssl );

	return $is_site_ssl;
}


/**
 * Like in_array but for nested arrays.
 *
 * @since 1.0
 *
 * @param (mixed) $needle   The value to find.
 * @param (array) $haystack The array to search.
 *
 * @return (bool)
 */
function secupress_in_array_deep( $needle, $haystack ) {
	if ( $haystack ) {
		foreach ( $haystack as $item ) {
			if ( $item === $needle || ( is_array( $item ) && secupress_in_array_deep( $needle, $item ) ) ) {
				return true;
			}
		}
	}
	return false;
}


/**
 * `array_intersect_key()` + `array_merge()`.
 *
 * @since 1.0
 * @author Grégory Viguier
 *
 * @param (array) $values  The array we're interested in.
 * @param (array) $default The array we use as boudaries.
 *
 * @return (array)
 */
function secupress_array_merge_intersect( $values, $default ) {
	$values = array_merge( $default, $values );
	return array_intersect_key( $values, $default );
}


/**
 * Tell if the consumer email is valid.
 *
 * @since 1.0
 *
 * @return (string|bool) The email if it is valid. False otherwise.
 */
function secupress_get_consumer_email() {
	return is_email( secupress_get_option( 'consumer_email' ) );
}


/**
 * Get the consumer key (if the consumer email is ok).
 *
 * @since 1.0
 *
 * @return (string)
 */
function secupress_get_consumer_key() {
	return secupress_get_consumer_email() ? secupress_get_option( 'consumer_key' ) : '';
}


/**
 * Return true if secupress pro is installed and the license is ok.
 *
 * @since 1.0
 *
 * @return (bool)
 */
function secupress_is_pro() {
	return defined( 'SECUPRESS_PRO_VERSION' ) && secupress_get_consumer_key() && (int) secupress_get_option( 'site_is_pro' );
}


/**
 * Tell if a feature is for pro version.
 *
 * @since 1.0
 *
 * @param (string) $feature The feature to test. Basically it can be:
 *                          - A field "name" when the whole field is pro: the result of `$this->get_field_name( $field_name )`.
 *                          - A field "name + value" when only one (or some) of the values is pro: the result of `$this->get_field_name( $field_name ) . "|" . $value`.
 *
 * @return (bool) True if the feature is in the white-list.
 */
function secupress_feature_is_pro( $feature ) {
	$features = array(
		// Field names.
		'login-protection_only-one-connection'   => 1,
		'login-protection_sessions_control'      => 1,
		'double-auth_type'                       => 1,
		'password-policy_password_expiration'    => 1,
		'password-policy_strong_passwords'       => 1,
		'plugins_activation'                     => 1,
		'plugins_deactivation'                   => 1,
		'plugins_deletion'                       => 1,
		'plugins_detect_bad_plugins'             => 1,
		'themes_activation'                      => 1,
		'themes_deletion'                        => 1,
		'themes_detect_bad_themes'               => 1,
		'uploads_uploads'                        => 1,
		'page-protect_profile'                   => 1,
		'page-protect_settings'                  => 1,
		'content-protect_hotlink'                => 1,
		'file-scanner_file-scanner'              => 1,
		'backup-files_backup-file'               => 1,
		'import-export_export_settings'          => 1,
		'import-export_import_settings'          => 1,
		'geoip-system_type'                      => 1,
		'bruteforce_activated'                   => 1,
		'schedules-backups_type'                 => 1,
		'schedules-backups_periodicity'          => 1,
		'schedules-backups_email'                => 1,
		'schedules-backups_scheduled'            => 1,
		'schedules-scan_type'                    => 1,
		'schedules-scan_periodicity'             => 1,
		'schedules-scan_email'                   => 1,
		'schedules-scan_scheduled'               => 1,
		'schedules-file-monitoring_type'         => 1,
		'schedules-file-monitoring_periodicity'  => 1,
		'schedules-file-monitoring_email'        => 1,
		'schedules-file-monitoring_scheduled'    => 1,
		// Field values.
		'notification-types_types|sms'           => 1,
		'notification-types_types|push'          => 1,
		'notification-types_types|slack'         => 1,
		'notification-types_types|twitter'       => 1,
		'login-protection_type|nonlogintimeslot' => 1,
		'backups-storage_location|ftp'           => 1,
		'backups-storage_location|amazons3'      => 1,
		'backups-storage_location|dropbox'       => 1,
		'backups-storage_location|rackspace'     => 1,
	);

	return isset( $features[ $feature ] );
}


/**
 * Tell if a user is affected by its role for the asked module.
 *
 * @since 1.0
 *
 * @param (string) $module    A module.
 * @param (string) $submodule A sub-module.
 * @param (object) $user      A WP_User object.
 *
 * @return (-1|bool) -1 = every role is affected, true = the user's role is affected, false = the user's role isn't affected.
 */
function secupress_is_affected_role( $module, $submodule, $user ) {
	$roles = secupress_get_module_option( $submodule . '_affected_role', array(), $module );

	if ( ! $roles ) {
		return -1;
	}

	return secupress_is_user( $user ) && ! array_intersect( $roles, $user->roles );
}


/**
 * This will be used with the filter hook 'nonce_user_logged_out' to create nonces for disconnected users.
 *
 * @since 1.0
 *
 * @param (int) $uid A userID.
 *
 * @return (int)
 */
function secupress_modify_userid_for_nonces( $uid ) {
	if ( $uid ) {
		return $uid;
	}
	return isset( $_GET['userid'] ) ? (int) $_GET['userid'] : 0;
}


/**
 * Tell if the param $user is a real user from your installation.
 *
 * @since 1.0
 * @author Julio Potier
 *
 * @param (mixed) $user The object to be tested to be a valid user.
 *
 * @return (bool)
 */
function secupress_is_user( $user ) {
	return is_a( $user, 'WP_User' ) && user_can( $user, 'exist' );
}


/**
 * Will return the current scanner step number
 *
 * @since 1.0
 * @author Julio Potier (Geoffrey)
 *
 * @return (integer) returns 1 if first scan never done
 */
function secupress_get_scanner_pagination() {
	$scans = array_filter( (array) get_site_option( SECUPRESS_SCAN_TIMES ) );

	if ( ! isset( $_GET['step'] ) || ! is_numeric( $_GET['step'] ) || absint( $_GET['step'] ) < 0 || empty( $scans ) ) {
		$step = 1;
	} else {
		$step = (int) $_GET['step'];
	}

	return $step;
}
