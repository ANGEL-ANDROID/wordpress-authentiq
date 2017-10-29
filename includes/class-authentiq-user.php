<?php

/**
 * Authentiq User class.
 *
 * Helper methods for User manipulation
 *
 * @since      1.0.0
 * @package    Authentiq
 * @subpackage Authentiq/includes
 * @author     The Authentiq Team <hello@authentiq.com>
 */

class Authentiq_User
{
	/**
	 * Parse WP user info from Authentiq userinfo.
	 *
	 * @param $userinfo
	 *
	 * @return array
	 */
	private static function get_user_data_from_userinfo($userinfo) {
		$email = null;
		if (isset($userinfo->email) && is_email($userinfo->email)) {
			$email = sanitize_email($userinfo->email);
		}

		$first_name = null;
		$last_name = null;
		$user_name = null;
		$display_name = null;

		// Try to get username from Authentiq ID, when set
		if (isset($userinfo->preferred_username)) {
			$user_name = $userinfo->preferred_username;
			$display_name = $user_name;
		}

		if (!$display_name && isset($userinfo->name)) {
			$display_name = $userinfo->name;
		}

		if (isset($userinfo->given_name)) {
			$first_name = $userinfo->given_name;

			if (!$user_name) $user_name = strtolower($first_name);
		}

		if (isset($userinfo->family_name)) {
			$last_name = $userinfo->family_name;
		}

		// WP doesn't allow user_login to contain non english chars
		// as a fallback remove non english chars, so as email name can be used
		// with //translit you get a meaningful conversion to ASCII (e.g. ß -> ss)
		$user_name = iconv('UTF-8', 'ASCII//TRANSLIT', $user_name);

		// if no username set so far, try to set one using the email
		if (!$user_name) {
			$email_parts = explode('@', $email);
			$user_name = $email_parts[0];
		}

		// Create the user data array for updating WP user info
		$user_data = array(
			'user_email' => trim($email),
			'user_login' => trim($user_name),
			'first_name' => trim($first_name),
			'last_name' => trim($last_name),
			'display_name' => trim($display_name),
			'nickname' => trim($user_name),
		);

		return $user_data;
	}

	/**
	 * Parse Authentiq userinfo that doesn't exist in a WP user.
	 *
	 * @param $userinfo
	 *
	 * @return array
	 */
	private static function get_authentiq_userinfo($userinfo) {
		$user_data = array();

		if (isset($userinfo->phone_number)) {
			$user_data['phone_number'] = $userinfo->phone_number;

			if (isset($userinfo->phone_number_verified)) {
				$user_data['phone_number_verified'] = $userinfo->phone_number_verified;
			}

			if (isset($userinfo->phone_type)) {
				$user_data['phone_type'] = $userinfo->phone_type;
			}
		}

		if (isset($userinfo->address)) {
			$user_data['address'] = (array)$userinfo->address;
		}

		$twitter_scope = 'aq:social:twitter';
		if (isset($userinfo->$twitter_scope)) {
			$user_data['twitter'] = (array)$userinfo->$twitter_scope;
		}

		$facebook_scope = 'aq:social:facebook';
		if (isset($userinfo->$facebook_scope)) {
			$user_data['facebook'] = (array)$userinfo->$facebook_scope;
		}

		$linkedin_scope = 'aq:social:linkedin';
		if (isset($userinfo->$linkedin_scope)) {
			$user_data['linkedin'] = (array)$userinfo->$linkedin_scope;
		}

		return $user_data;
	}

	/**
	 * Create a new WP user from an Authentiq signin.
	 *
	 * @param      $userinfo
	 * @param null $role
	 *
	 * @return int|WP_Error
	 */
	public static function create_user($userinfo) {
		// Get WP user info from Authentiq userinfo
		$user_data = Authentiq_User::get_user_data_from_userinfo($userinfo);

		// FIXME: check if email is required
		if (empty($userinfo->email)) {
			$msg = __('Email is required by your site administrator.', AUTHENTIQ_LANG);
			throw new Authentiq_User_Creation_Failed_Exception($msg);
		}

		// Generate a random password, otherwise account creation fails
		$password = wp_generate_password(22);
		$user_data['user_pass'] = $password;

		// Check if username is already taken, and use another
		while (username_exists($user_data['user_login'])) {
			$user_data['user_login'] .= rand(1, 99);
		}

		/**
		 * Filters if we can create this user
		 *
		 * @param bool $allow
		 * @param int  $userinfo
		 */
		$valid_user = apply_filters('authentiq_should_create_user', true, $user_data);
		if (!$valid_user) {
			return -2;
		}

		// Create the user
		$user_id = wp_insert_user($user_data);

		// Link Authentiq ID profile sub to WP user
		Authentiq_User::update_authentiq_id($user_id, $userinfo);

		// Add Authentiq extra info to WP user profile
		$authentiq_userinfo = Authentiq_User::get_authentiq_userinfo($userinfo);
		if (!empty($authentiq_userinfo)) {
			Authentiq_User::update_userinfo($user_id, $authentiq_userinfo);
		}

		if (!is_numeric($user_id)) {
			return $user_id;
		}

		/**
		 * Fires after a WP user is created from an Authentiq signin
		 *
		 * @param int    $user_id
		 * @param object $user_data WP User data
		 */
		do_action('authentiq_user_created', $user_id, $user_data);

		return $user_id;
	}

	/**
	 * Update a WP user after an Authentiq signin.
	 *
	 * @param $user
	 * @param $userinfo
	 *
	 * @return int|WP_Error
	 * @throws Authentiq_User_Exception
	 */
	public static function update_user($user, $userinfo) {
		if (is_null($user)) {
			$msg = __('No user set to be updated.', AUTHENTIQ_LANG);
			throw new Authentiq_User_Exception($msg);
		}

		// Get WP user info from Authentiq userinfo
		$user_data = Authentiq_User::get_user_data_from_userinfo($userinfo);
		$user_data['ID'] = $user->data->ID;

		// Update the WP user
		$user_id = wp_update_user($user_data);

		// Link Authentiq ID profile sub to WP user
		Authentiq_User::update_authentiq_id($user_id, $userinfo);

		// Add Authentiq extra info to WP user profile
		$authentiq_userinfo = Authentiq_User::get_authentiq_userinfo($userinfo);
		if (!empty($authentiq_userinfo)) {
			Authentiq_User::update_userinfo($user_id, $authentiq_userinfo);
		}

		if (!is_numeric($user_id)) {
			return $user_id;
		}

		/**
		 * Fires after a WP user is updated from an Authentiq signin
		 *
		 * @param int    $user_id
		 * @param object $user_data WP User data
		 */
		do_action('authentiq_user_updated', $user_id, $user_data);

		return $user_id;
	}

	/**
	 * Get a WP user by email
	 *
	 * @param $email
	 *
	 * @return false|null|WP_User
	 */
	public static function get_user_by_email($email) {
		global $wpdb;

		if (empty($email)) {
			return null;
		}

		$user = get_user_by('email', $email);

		if ($user instanceof WP_Error) {
			return null;
		}

		return $user;
	}

	/**
	 * Get a WP user by Authentiq ID sub
	 *
	 * @param $id
	 *
	 * @return null
	 */
	public static function get_user_by_sub($id) {
		global $wpdb;

		// TODO: throw error if no query

		if (empty($id)) {
			return null;
		}

		$query = array(
			'meta_key' => $wpdb->prefix . 'authentiq_id',
			'meta_value' => $id,
			'blog_id' => false,
			'number' => 1,
			'count_total' => false,
		);

		$users = get_users($query);

		if ($users instanceof WP_Error) {
			return null;
		}

		if (!empty($users)) {
			return $users[0];
		}

		return null;
	}

	public static function has_authentiq_id($user_id) {
		return !empty(self::get_authentiq_id($user_id));
	}

	public static function get_authentiq_id($user_id) {
		global $wpdb;
		return get_user_meta($user_id, $wpdb->prefix . 'authentiq_id', true);
	}

	public static function update_authentiq_id($user_id, $userinfo) {
		global $wpdb;
		update_user_meta($user_id, $wpdb->prefix . 'authentiq_id', $userinfo->sub);
	}

	public static function delete_authentiq_id($user_id) {
		global $wpdb;
		delete_user_meta($user_id, $wpdb->prefix . 'authentiq_id');
	}

	public static function get_userinfo($user_id) {
		global $wpdb;
		return get_user_meta($user_id, $wpdb->prefix . 'authentiq_obj', true);
	}

	public static function update_userinfo($user_id, $data) {
		global $wpdb;
		update_user_meta($user_id, $wpdb->prefix . 'authentiq_obj', $data);
	}

	public static function delete_userinfo($user_id) {
		global $wpdb;
		delete_user_meta($user_id, $wpdb->prefix . 'authentiq_obj');
	}
}
