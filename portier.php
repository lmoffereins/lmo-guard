<?php

/**
 * The Portier Plugin
 *
 * @package Portier
 * @subpackage Main
 */

/**
 * Plugin Name:       Portier
 * Description:       Limit user access to your (multi)site. Formerly Guard.
 * Plugin URI:        https://github.com/lmoffereins/portier
 * Author:            Laurens Offereins
 * Author URI:        https://github.com/lmoffereins
 * Version:           1.3.1
 * Text Domain:       portier
 * Domain Path:       /languages/
 * GitHub Plugin URI: lmoffereins/portier
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Portier' ) ) :
/**
 * Main Portier Class
 *
 * @since 1.0.0
 */
final class Portier {

	/** Singleton *************************************************************/

	/**
	 * Main Portier Instance
	 *
	 * @since 1.0.0
	 *
	 * @see portier()
	 * @return The one true Portier
	 */
	public static function instance() {

		// Store the instance locally to avoid private static replication
		static $instance = null;

		// Only run these methods if they haven't been ran previously
		if ( null === $instance ) {
			$instance = new Portier;
			$instance->setup_globals();
			$instance->includes();
			$instance->setup_actions();
		}

		// Always return the instance
		return $instance;
	}

	/**
	 * A dummy constructor to prevent Portier from being loaded more than once.
	 *
	 * @since 1.0.0
	 *
	 * @see Portier::instance()
	 * @see portier()
	 */
	private function __construct() { /* Do nothing here */ }

	/** Private Methods *******************************************************/

	/**
	 * Set default class globals
	 *
	 * @since 1.0.0
	 */
	private function setup_globals() {

		/** Versions **********************************************************/

		$this->version      = '1.3.1';
		$this->db_version   = 20201021;

		/** Paths *************************************************************/

		// Setup some base path and URL information
		$this->file         = __FILE__;
		$this->basename     = plugin_basename( $this->file );
		$this->plugin_dir   = plugin_dir_path( $this->file );
		$this->plugin_url   = plugin_dir_url ( $this->file );

		// Includes
		$this->includes_dir = trailingslashit( $this->plugin_dir . 'includes' );
		$this->includes_url = trailingslashit( $this->plugin_url . 'includes' );

		// Assets
		$this->assets_dir   = trailingslashit( $this->plugin_dir . 'assets' );
		$this->assets_url   = trailingslashit( $this->plugin_url . 'assets' );

		// Extensions
		$this->extend_dir   = trailingslashit( $this->includes_dir . 'extend' );
		$this->extend_url   = trailingslashit( $this->includes_url . 'extend' );

		// Languages
		$this->lang_dir     = trailingslashit( $this->plugin_dir . 'languages' );

		/** Misc **************************************************************/

		$this->extend       = new stdClass();
		$this->domain       = 'portier';
	}

	/**
	 * Include required files
	 *
	 * @since 1.0.0
	 */
	private function includes() {
		require( $this->includes_dir . 'actions.php'     );
		require( $this->includes_dir . 'functions.php'   );
		require( $this->includes_dir . 'sub-actions.php' );

		// Admin
		if ( is_admin() ) {
			require( $this->includes_dir . 'admin.php'    );
			require( $this->includes_dir . 'settings.php' );
			require( $this->includes_dir . 'update.php'   );
		}

		// Extensions
		require( $this->extend_dir . 'buddypress/buddypress.php' );
	}

	/**
	 * Setup the default hooks and actions
	 *
	 * @since 1.0.0
	 */
	private function setup_actions() {

		// Add actions to plugin activation and deactivation hooks
		add_action( 'activate_'   . $this->basename, 'portier_activation'   );
		add_action( 'deactivate_' . $this->basename, 'portier_deactivation' );

		// Plugin
		add_action( 'portier_loaded',        array( $this, 'load_textdomain'  ) );
		add_action( 'portier_loaded',        array( $this, 'load_for_network' ) );
		add_action( 'wp_enqueue_scripts',    array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Protection
		add_action( 'template_redirect', array( $this, 'site_protect'   ), 1 );
		add_filter( 'login_message',     array( $this, 'login_message'  ), 1 );
		add_action( 'admin_bar_menu',    array( $this, 'admin_bar_menu' )    );

		// Admin
		if ( is_admin() ) {
			add_action( 'portier_init', 'portier_admin' );
		}

		// Fire plugin loaded hook
		do_action( 'portier_loaded' );
	}

	/** Plugin ****************************************************************/

	/**
	 * Loads the textdomain file for this plugin
	 *
	 * @since 1.0.0
	 *
	 * @uses apply_filters() Calls 'plugin_locale' with {@link get_locale()} value
	 */
	public function load_textdomain() {

		// Traditional WordPress plugin locale filter
		$locale        = apply_filters( 'plugin_locale', get_locale(), $this->domain );
		$mofile        = sprintf( '%1$s-%2$s.mo', $this->domain, $locale );

		// Setup paths to current locale file
		$mofile_local  = $this->lang_dir . $mofile;
		$mofile_global = WP_LANG_DIR . '/portier/' . $mofile;

		// Look in global /wp-content/languages/portier folder first
		load_textdomain( $this->domain, $mofile_global );

		// Look in global /wp-content/languages/plugins/ and local plugin languages folder
		load_plugin_textdomain( $this->domain, false, 'portier/languages' );
	}

	/**
	 * Initialize network functions when network activated
	 *
	 * @since 1.0.0
	 */
	public function load_for_network() {

		// Load file to use plugin functions
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		// Bail when plugin is not network activated
		if ( ! is_plugin_active_for_network( $this->basename ) )
			return;

		// Load network functions
		require( $this->includes_dir . 'network/network.php' );

		// Setup network functionality
		$this->network = new Portier_Network;
	}

	/**
	 * Register plugin scripts and styles
	 *
	 * @since 1.3.0
	 *
	 * @global array $_wp_admin_css_colors
	 *
	 * @uses apply_filters() Calls 'portier_enqueue_style'
	 */
	public function enqueue_scripts() {
		global $_wp_admin_css_colors;

		// Register style
		wp_register_style( 'portier', $this->assets_url . 'css/portier.css', array(), portier_get_version() );

		// Enqueue style when displaying the admin bar badge
		if ( is_admin_bar_showing() && apply_filters( 'portier_enqueue_style', portier_show_admin_bar_badge() ) ) {

			// Get the icon focus color
			$color_scheme = get_user_meta( get_current_user_id(), 'admin_color', true );
			if ( isset( $_wp_admin_css_colors[ $color_scheme ] ) ) {
				$icon_color = $_wp_admin_css_colors[ $color_scheme ]->icon_colors['focus'];
			} else {
				$icon_color = '#00b9eb';
			}

			// Enqueue style
			wp_enqueue_style( 'portier' );
			wp_add_inline_style( 'portier', "
				#wpadminbar #wp-admin-bar-portier.site-protected > .ab-item .ab-icon:before { color: " . $icon_color . "; }
			" );
		}
	}

	/** Protection ************************************************************/

	/**
	 * Redirect users on accessing a page of your site
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Handle feed requests
	 *
	 * @uses do_action() Calls 'portier_site_protect'
	 */
	public function site_protect() {

		// Bail when protection is not active
		if ( ! portier_is_site_protected() || is_404() )
			return;

		// When user is not logged in or is not allowed
		if ( ! is_user_logged_in() || ! portier_is_user_allowed() ) {

			// Provide hook
			do_action( 'portier_site_protect' );

			// Handle feed requests
			if ( is_feed() ) {
				global $wp_query;

				// Block with 404 status
				$wp_query->is_feed = false;
				$wp_query->set_404();
				status_header( 404 );
			}

			// Logout user and redirect to login page
			auth_redirect();
		}
	}

	/**
	 * Append our custom login message to the login messages
	 *
	 * @since 1.0.0
	 * 
	 * @param string $message The current login messages
	 * @return string $message
	 */
	public function login_message( $message ) {

		// When protection is active
		if ( portier_is_site_protected() ) {
			$login_message = get_option( '_portier_login_message' );

			// Append message when it's provided
			if ( ! empty( $login_message ) ) {
				$message .= '<p class="message">' . $login_message . '<p>';
			}
		}

		return $message;
	}

	/**
	 * Add the plugin's admin bar menu item
	 * 
	 * @since 1.0.0
	 * 
	 * @param WP_Admin_Bar $wp_admin_bar
	 */
	public function admin_bar_menu( $wp_admin_bar ) {

		// Show the admin bar badge
		if ( portier_show_admin_bar_badge() ) {

			// When protection is active
			$active = portier_is_site_protected();
			$status = $active ? esc_html__( 'Site protection is active', 'portier' ) : esc_html__( 'Site protection is not active', 'portier' );
			$class  = $active ? 'site-protected' : 'site-not-protected';

			// Add site-is-protected menu notification
			$wp_admin_bar->add_menu( array(
				'id'        => 'portier',
				'parent'    => 'top-secondary',
				'title'     => '<span class="ab-icon"></span><span class="screen-reader-text">' . $status . '</span>',
				'href'      => add_query_arg( 'page', 'portier', admin_url( 'options-general.php' ) ),
				'meta'      => array(
					'class' => $class,
					'title' => $status
				),
			) );

			// Add nodes for details when site protection is active
			if ( $active ) {
				foreach ( portier_get_protection_details() as $key => $detail ) {
					$wp_admin_bar->add_node( array(
						'id'     => "portier-{$key}",
						'parent' => 'portier',
						'title'  => $detail
					) );
				}
			}

			// Add link to network settings
			if ( is_plugin_active_for_network( $this->basename ) && current_user_can( 'manage_network_options' ) ) {
				$wp_admin_bar->add_node( array(
					'id'     => 'portier-network-settings',
					'parent' => 'portier',
					'title'  => esc_html__( 'Manage network', 'portier' ),
					'href'   => add_query_arg( 'page', 'portier', network_admin_url( 'settings.php' ) )
				) );
			}
		}
	}
}

/**
 * The main public function responsible for returning the one true Portier instance
 * to functions everywhere.
 *
 * @since 1.0.0
 *
 * @return The one true Portier instance
 */
function portier() {
	return Portier::instance();
}

// Do the magic
portier();

endif; // class_exists
