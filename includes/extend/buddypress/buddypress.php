<?php

/**
 * Portier Extension for BuddyPress
 * 
 * @package Portier
 * @subpackage BuddyPress
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Portier_BuddyPress' ) ) :
/**
 * BuddyPress extension for Portier
 *
 * @since 1.0.0
 */
class Portier_BuddyPress {

	/**
	 * Class constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->setup_globals();
		$this->includes();
		$this->setup_actions();
	}

	/**
	 * Define default class globals
	 *
	 * @since 1.0.0
	 */
	public function setup_globals() {

		/** Paths *************************************************************/

		// Includes
		$this->includes_dir = trailingslashit( portier()->extend_dir . 'buddypress' );
		$this->includes_url = trailingslashit( portier()->extend_url . 'buddypress' );

		/** Misc **************************************************************/

		$this->bp_group_hierarchy = defined( 'BP_GROUP_HIERARCHY_VERSION' );
	}

	/**
	 * Include required files
	 *
	 * @since 1.3.0
	 */
	public function includes() {
		require( $this->includes_dir . 'functions.php' );
	}

	/**
	 * Define default class hooks
	 * 
	 * @since 1.0.0
	 */
	public function setup_actions() {

		// Settings
		add_filter( 'portier_settings',         array( $this, 'register_settings' ) );
		add_filter( 'portier_network_settings', array( $this, 'register_settings' ) );

		// Protection
		add_filter( 'portier_is_user_allowed',                 array( $this, 'is_user_allowed'    ), 10, 3 );
		add_filter( 'portier_network_is_user_allowed',         array( $this, 'is_user_allowed'    ), 10, 2 );
		add_filter( 'portier_get_protection_details',          array( $this, 'protection_details' ), 10, 2 );
		add_filter( 'portier_network_get_protection_details',  array( $this, 'protection_details' ), 10, 2 );

		// Admin
		add_filter( 'portier_network_sites_columns',       array( $this, 'sites_columns'       )        );
		add_action( 'portier_network_sites_custom_column', array( $this, 'sites_custom_column' ), 10, 2 );
	}

	/**
	 * Register BuddyPress extension settings
	 *
	 * @since 1.0.0
	 * 
	 * @param array $settings Settings
	 * @return array Settings
	 */
	public function register_settings( $settings ) {

		// Get whether these are the network settings
		$network = current_filter() == 'portier_network_settings';

		// Member types
		if ( bp_get_member_types() ) {

			// Allowed member types
			$settings['_portier_bp_allowed_member_types'] = array(
				'label'             => esc_html__( 'Allowed member types', 'portier' ),
				'callback'          => 'portier_bp_setting_allowed_member_types',
				'section'           => 'portier-options-access',
				'page'              => $network ? 'portier_network' : 'portier',
				'sanitize_callback' => false
			);
		}

		// Groups component
		if ( bp_is_active( 'groups' ) ) {

			// Allowed groups
			$settings['_portier_bp_allowed_groups'] = array(
				'label'             => esc_html__( 'Allowed groups', 'portier' ),
				'callback'          => 'portier_bp_setting_allowed_groups',
				'section'           => 'portier-options-access',
				'page'              => $network ? 'portier_network' : 'portier',
				'sanitize_callback' => 'portier_setting_sanitize_ids'
			);
		}

		return $settings;
	}

	/**
	 * Filter whether the user is allowed when the site or network is protected
	 *
	 * @since 1.0.0
	 * 
	 * @param bool $allowed Is the user allowed
	 * @param int $user_id User ID
	 * @param int $site_id Optional. Site ID
	 * @return bool User is allowed
	 */
	public function is_user_allowed( $allowed, $user_id, $site_id = 0 ) {

		// Check for member types
		if ( ! $allowed && bp_get_member_types() ) {

			// Get the allowed member types
			$getter = current_filter() == 'portier_network_is_user_allowed'
				? 'portier_bp_get_network_allowed_member_types'
				: 'portier_bp_get_allowed_member_types';
			$types  = call_user_func_array( $getter, array( $site_id ) );

			foreach ( $types as $type ) {
				if ( bp_has_member_type( $user_id, $type ) ) {
					$allowed = true;
					break;
				}
			}
		}

		// Check for groups
		if ( ! $allowed && bp_is_active( 'groups' ) ) {

			// Get the allowed groups
			$getter    = current_filter() == 'portier_network_is_user_allowed'
				? 'portier_bp_get_network_allowed_groups'
				: 'portier_bp_get_allowed_groups';
			$group_ids = call_user_func_array( $getter, array( $site_id ) );

			// Only check for selected groups
			if ( ! empty( $group_ids ) ) {

				// Account for group hierarchy
				if ( $this->bp_group_hierarchy ) {

					// Walk hierarchy
					$hierarchy = new ArrayIterator( $group_ids );
					foreach ( $hierarchy as $gid ) {

						// Add child group ids when found
						if ( $children = @BP_Groups_Hierarchy::has_children( $gid ) ) {
							foreach ( $children as $child_id )
								$hierarchy->append( (int) $child_id );
						}
					}

					// Set hierarchy group id collection
					$group_ids = $hierarchy->getArrayCopy();
				}

				// Find any group memberships
				$groups = groups_get_groups( array(
					'user_id'         => $user_id,
					'include'         => $group_ids,
					'show_hidden'     => true,
					'per_page'        => false,
					'populate_extras' => false,
				) );

				// Allow when the user's group(s) were found
				$allowed = ! empty( $groups['groups'] );
			}
		}

		return $allowed;
	}

	/**
	 * Modify the protection details of the site or network
	 *
	 * @since 1.0.0
	 * 
	 * @param array $details Site protection details
	 * @param int $site_id Site ID
	 * @return array Site protection details
	 */
	public function protection_details( $details, $site_id = 0 ) {

		// Get allowed member type count
		if ( bp_get_member_types() ) {

			// Get allowed types
			$getter = current_filter() == 'portier_network_get_protection_details'
				? 'portier_bp_get_network_allowed_member_types'
				: 'portier_bp_get_allowed_member_types';
			$types  = call_user_func_array( $getter, array( $site_id ) );

			$type_count = count( $types );
			if ( $type_count ) {
				$details['bp_allowed_member_types'] = sprintf( _n( '%d allowed member type', '%d allowed member types', $type_count, 'portier' ), $type_count );
			}
		}

		// Get allowed group count
		if ( bp_is_active( 'groups' ) ) {

			// Get the allowed groups
			$getter    = current_filter() == 'portier_network_get_protection_details'
				? 'portier_bp_get_network_allowed_groups'
				: 'portier_bp_get_allowed_groups';
			$group_ids = call_user_func_array( $getter, array( $site_id ) );

			$group_count = count( $group_ids );
			if ( $group_count ) {
				$details['bp_allowed_groups'] = sprintf( _n( '%d allowed group', '%d allowed groups', $group_count, 'portier' ), $group_count );
			}
		}

		return $details;
	}

	/**
	 * Append custom sites columns
	 *
	 * @since 1.1.0
	 * 
	 * @param array $columns Columns
	 * @return array Columns
	 */
	public function sites_columns( $columns ) {

		// Allowed member types
		if ( bp_get_member_types() ) {
			$columns['allowed_bp-member-types'] = esc_html__( 'Allowed member types', 'portier' );
		}

		// Allowed groups
		if ( bp_is_active( 'groups' ) ) {
			$columns['allowed_bp-groups'] = esc_html__( 'Allowed groups', 'portier' );
		}

		return $columns;
	}

	/**
	 * Output the custom columns content
	 *
	 * @since 1.1.0
	 * 
	 * @param string $column_name Column name
	 * @param int $site_id Site ID
	 */
	public function sites_custom_column( $column_name, $site_id ) {

		switch ( $column_name ) {

			// Allowed member types
			case 'allowed_bp-member-types' :
				$types = portier_bp_get_allowed_member_types( $site_id );
				$count = count( $types );

				if ( $count ) {
					$title = implode( ', ', wp_list_pluck(
						wp_list_pluck(
							array_map( 'bp_get_member_type_object', array_slice( $types, 0, 5 ) ),
							'labels'
						),
						'name'
					) );
					if ( 0 < $count - 5 ) {
						$title = sprintf( esc_html__( '%s and %d more', 'portier' ), $title, $count - 5 );
					}
					?>
		<span class="count"><?php echo $title; ?></span>
					<?php
				} else {
					echo '&mdash;';
				}

				break;

			// Allowed groups
			case 'allowed_bp-groups' :
				$groups = portier_bp_get_allowed_groups( $site_id );
				$count  = count( $groups );

				if ( $count ) {
					$title = implode( ', ', wp_list_pluck(
						array_map( 'groups_get_group', array_map( function( $id ) {
							return array( 'group_id' => $id );
						}, array_slice( $groups, 0, 5 ) ) ),
						'name'
					) );
					if ( 0 < $count - 5 ) {
						$title = sprintf( esc_html__( '%s and %d more', 'portier' ), $title, $count - 5 );
					}
					?>
		<span class="count"><?php echo $title; ?></span>
					<?php
				} else {
					echo '&mdash;';
				}

			break;
		}
	}
}

/**
 * Initiate the BuddyPress extension
 *
 * @since 1.3.0
 *
 * @uses Portier_BuddyPress
 */
function portier_buddypress() {
	portier()->extend->bp = new Portier_BuddyPress();
}

endif;
