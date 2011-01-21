<?php

/**
 * BuddyPress User Loader
 *
 * A users component to help contain all of the user specific slugs
 *
 * @package BuddyPress
 * @subpackage User Core
 */

class BP_User_Component extends BP_Component {

	/**
	 * Start the users component creation process
	 *
	 * @since BuddyPress {unknown}
	 */
	function BP_User_Component() {
		parent::start( 'members', __( 'Members', 'buddypress' ) );
	}

	/**
	 * Setup globals
	 *
	 * The BP_MEMBERS_SLUG constant is deprecated, and only used here for
	 * backwards compatibility.
	 *
	 * @since BuddyPress {unknown}
	 * @global obj $bp
	 */
	function _setup_globals() {
		global $bp, $current_user, $displayed_user_id;

		// Define a slug, if necessary
		if ( !defined( 'BP_MEMBERS_SLUG' ) )
			define( 'BP_MEMBERS_SLUG', $this->id );

		// Do some slug checks
		$this->slug      = BP_MEMBERS_SLUG;
		$this->root_slug = isset( $bp->pages->members->slug ) ? $bp->pages->members->slug : $this->slug;

		// Tables
		$this->table_name      = $bp->table_prefix . 'bp_users';
		$this->table_name_meta = $bp->table_prefix . 'bp_users_meta';

		/** Logged in user ****************************************************/

		// Logged in user is the 'current_user'
		$current_user                      = wp_get_current_user();

		// The user ID of the user who is currently logged in.
		$bp->loggedin_user->id             = $current_user->ID;

		// The domain for the user currently logged in. eg: http://domain.com/members/andy
		$bp->loggedin_user->domain         = bp_core_get_user_domain( $bp->loggedin_user->id );

		// The core userdata of the user who is currently logged in.
		$bp->loggedin_user->userdata       = bp_core_get_core_userdata( $bp->loggedin_user->id );

		// Fetch the full name for the logged in user
		$bp->loggedin_user->fullname       = bp_core_get_user_displayname( $bp->loggedin_user->id );

		// is_super_admin() hits the DB on single WP installs, so we need to get this separately so we can call it in a loop.
		$bp->loggedin_user->is_super_admin = is_super_admin();
		$bp->loggedin_user->is_site_admin  = $bp->loggedin_user->is_super_admin; // deprecated 1.2.6

		/**
		 * Used to determine if user has admin rights on current content. If the
		 * logged in user is viewing their own profile and wants to delete
		 * something, is_item_admin is used. This is a generic variable so it
		 * can be used by other components. It can also be modified, so when
		 * viewing a group 'is_item_admin' would be 1 if they are a group
		 * admin, 0 if they are not.
		 */
		$bp->is_item_admin = bp_user_has_access();

		// Used to determine if the logged in user is a moderator for
		// the current content.
		$bp->is_item_mod = false;

		/** Displayed user ****************************************************/

		// The user id of the user currently being viewed:
		// $bp->displayed_user->id is set in /bp-core/bp-core-catchuri.php
		if ( empty( $bp->displayed_user->id ) )
			$bp->displayed_user->id = 0;

		// The domain for the user currently being displayed
		$bp->displayed_user->domain   = bp_core_get_user_domain( $bp->displayed_user->id );

		// The core userdata of the user who is currently being displayed
		$bp->displayed_user->userdata = bp_core_get_core_userdata( $bp->displayed_user->id );

		// Fetch the full name displayed user
		$bp->displayed_user->fullname = bp_core_get_user_displayname( $bp->displayed_user->id );

		/** Active Component **************************************************/

		// Users is active
		$bp->active_components[$this->id] = $this->id;
		
		/** Default Profile Component *****************************************/
		if ( !$bp->current_component && $bp->displayed_user->id )
			$bp->current_component = $bp->default_component;

	}

	/**
	 * Include files
	 *
	 * @global obj $bp
	 */
	function _includes() {
		require_once( BP_PLUGIN_DIR . '/bp-users/bp-users-signup.php'        );
		require_once( BP_PLUGIN_DIR . '/bp-users/bp-users-actions.php'       );
		require_once( BP_PLUGIN_DIR . '/bp-users/bp-users-filters.php'       );
		require_once( BP_PLUGIN_DIR . '/bp-users/bp-users-screens.php'       );
		require_once( BP_PLUGIN_DIR . '/bp-users/bp-users-template.php'      );
		require_once( BP_PLUGIN_DIR . '/bp-users/bp-users-functions.php'     );
		require_once( BP_PLUGIN_DIR . '/bp-users/bp-users-notifications.php' );
	}

	/**
	 * Setup BuddyBar navigation
	 *
	 * @global obj $bp
	 */
	function _setup_nav() {
		global $bp;

		return false;

		// Add 'User' to the main navigation
		bp_core_new_nav_item( array(
			'name'                => __( 'User', 'buddypress' ),
			'slug'                => $this->slug,
			'position'            => 10,
			'screen_function'     => 'bp_users_screen_my_users',
			'default_subnav_slug' => 'just-me',
			'item_css_id'         => $this->id
		) );

		// Stop if there is no user displayed or logged in
		if ( !is_user_logged_in() && !isset( $bp->displayed_user->id ) )
			return;

		// User links
		$user_domain   = ( isset( $bp->displayed_user->domain ) )               ? $bp->displayed_user->domain               : $bp->loggedin_user->domain;
		$user_login    = ( isset( $bp->displayed_user->userdata->user_login ) ) ? $bp->displayed_user->userdata->user_login : $bp->loggedin_user->userdata->user_login;
		$users_link    = $user_domain . $this->slug . '/';

		// Add the subnav items to the users nav item if we are using a theme that supports this
		bp_core_new_subnav_item( array(
			'name'            => __( 'Personal', 'buddypress' ),
			'slug'            => 'just-me',
			'parent_url'      => $users_link,
			'parent_slug'     => $this->slug,
			'screen_function' => 'bp_users_screen_my_users',
			'position'        => 10
		) );

		// Additional menu if friends is active
		if ( bp_is_active( 'friends' ) ) {
			bp_core_new_subnav_item( array(
				'name'            => __( 'Friends', 'buddypress' ),
				'slug'            => BP_FRIENDS_SLUG,
				'parent_url'      => $users_link,
				'parent_slug'     => $this->slug,
				'screen_function' => 'bp_users_screen_friends',
				'position'        => 20,
				'item_css_id'     => 'users-friends'
			) );
		}

		// Additional menu if groups is active
		if ( bp_is_active( 'groups' ) ) {
			bp_core_new_subnav_item( array(
				'name'            => __( 'Groups', 'buddypress' ),
				'slug'            => BP_GROUPS_SLUG,
				'parent_url'      => $users_link,
				'parent_slug'     => $this->slug,
				'screen_function' => 'bp_users_screen_groups',
				'position'        => 30,
				'item_css_id'     => 'users-groups'
			) );
		}

		// Favorite users items
		bp_core_new_subnav_item( array(
			'name'            => __( 'Favorites', 'buddypress' ),
			'slug'            => 'favorites',
			'parent_url'      => $users_link,
			'parent_slug'     => $this->slug,
			'screen_function' => 'bp_users_screen_favorites',
			'position'        => 40,
			'item_css_id'     => 'users-favs'
		) );

		// @ mentions
		bp_core_new_subnav_item( array(
			'name'            => sprintf( __( '@%s Mentions', 'buddypress' ), $user_login ),
			'slug'            => 'mentions',
			'parent_url'      => $users_link,
			'parent_slug'     => $this->slug,
			'screen_function' => 'bp_users_screen_mentions',
			'position'        => 50,
			'item_css_id'     => 'users-mentions'
		) );

		// Adjust title based on view
		if ( bp_is_users_component() ) {
			if ( bp_is_my_profile() ) {
				$bp->bp_options_title = __( 'My User', 'buddypress' );
			} else {
				$bp->bp_options_avatar = bp_core_fetch_avatar( array(
					'item_id' => $bp->displayed_user->id,
					'type'    => 'thumb'
				) );
				$bp->bp_options_title  = $bp->displayed_user->fullname;
			}
		}
	}
}
// Create the users component
$bp->members = new BP_User_Component();

?>