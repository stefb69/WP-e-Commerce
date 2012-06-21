<?php

class WPSC_Page_Password_Reminder extends WPSC_Page_SSL
{
	public function __construct( $callback ) {
		if ( is_user_logged_in() ) {
			$redirect_to = wp_get_referer();

			if ( ! $redirect_to )
				$redirect_to = wpsc_get_catalog_url();
			wp_redirect( $redirect_to );
			exit;
		}

		parent::__construct( $callback, wpsc_get_password_reminder_url() );
	}

	public function process_new_password() {
		global $wpdb;

		$validation_rules = array(
			'username' => array(
				'title' => __( 'username', 'wpsc' ),
				'rules' => 'trim|required|valid_username_or_email|allow_password_reset',
			),
		);

		$validation = wpsc_validate_form( $validation_rules );

		do_action('lostpassword_post');

		if ( is_wp_error( $validation ) ) {
			$this->set_validation_errors( $validation );
			return;
		}

		extract( $_POST, EXTR_SKIP );

		$field = strpos( $username, '@' ) ? $field = 'email' : 'login';
		$user_data = get_user_by( $field, $username );
		$user_login = $user_data->user_login;
		$user_email = $user_data->user_email;

		do_action('retrieve_password', $user_login);

		$allow = apply_filters('allow_password_reset', true, $user_data->ID);
		if ( ! $allow ) {
			$this->set_validation_errors( new WP_Error( 'username', __( 'Password reset is not allowed for this user', 'wpsc' ) ) );
		} else if ( is_wp_error( $allow ) ) {
			$this->set_validation_errors( $allow );
		}

		$key = $wpdb->get_var( $wpdb->prepare("SELECT user_activation_key FROM $wpdb->users WHERE user_login = %s", $user_login ) );
		if ( empty( $key ) ) {
			// Generate something random for a key...
			$key = wp_generate_password( 20, false );
			do_action( 'retrieve_password_key', $user_login, $key );
			// Now insert the new md5 key into the db
			$wpdb->update ($wpdb->users, array('user_activation_key' => $key ), array( 'user_login' => $user_login ) );
		}
		$message = __( 'Someone requested that the password be reset for the following account:', 'wpsc' ) . "\r\n\r\n";
		$message .= home_url( '/' ) . "\r\n\r\n";
		$message .= sprintf( __( 'Username: %s', 'wpsc' ), $user_login ) . "\r\n\r\n";
		$message .= __( 'If this was a mistake, just ignore this email and nothing will happen.', 'wpsc' ) . "\r\n\r\n";
		$message .= __( 'To reset your password, visit the following address:', 'wpsc' ) . "\r\n\r\n";
		$message .= '<' . wpsc_get_password_reminder_url( "reset/{$user_login}/{$key}" ) . ">\r\n";

		if ( is_multisite() )
			$blogname = $GLOBALS['current_site']->site_name;
		else
			$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		$title = sprintf( __( '[%s] Password Reset', 'wpsc' ), $blogname );

		$title = apply_filters( 'wpsc_retrieve_password_title', $title );
		$message = apply_filters( 'wpsc_retrieve_password_message', $message, $key );

		if ( $message && ! wp_mail( $user_email, $title, $message ) )
			$this->message_collection->add( __( "Sorry, but due to an unexpected technical issue, we couldn't send you the e-mail containing password reset directions. Most likely the web host we're using have disabled e-mail features. Please contact us and we'll help you fix this. Or you can simply try again later.", 'wpsc' ), 'error' ); // by "us", we mean the site owner.

		$this->message_collection->add( __( "We just sent you an e-mail containing directions to reset your password. If you don't receive it in a few minutes, check your Spam folder or simply try again.", 'wpsc' ), 'success' );
	}

	private function invalid_key_error() {
		return new WP_Error( 'invalid_key', __( 'The username and reset key combination in the URL are incorrect. Please make sure that you are using the correct URL specified in your Password Reset confirmation email.', 'wpsc' ) );
	}

	private function check_password_reset_key( $key, $login ) {
		global $wpdb;

		$key = preg_replace('/[^a-z0-9]/i', '', $key);

		if ( empty( $key ) || ! is_string( $key ) || empty( $login ) || ! is_string( $login ) )
			return $this->invalid_key_error();

		$user = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->users WHERE user_activation_key = %s AND user_login = %s", $key, $login ) );

		if ( empty( $user ) )
			return $this->invalid_key_error();

		return $user;
	}

	public function filter_fields_dont_match_message() {
		return __( 'The password fields do not match.', 'wpsc' );
	}

	public function reset_password( $user, $new_pass ) {
		do_action('password_reset', $user, $new_pass);
		wp_set_password( $new_pass, $user->ID );
		wp_password_change_notification( $user );
	}

	public function process_reset_password( $username = null, $key = null ) {
		$user = $this->reset( $username, $key );

		if ( is_wp_error( $user ) )
			return;

		$validation_rules = array(
			'pass1' => array(
				'title' => __( 'new password', 'wpsc' ),
				'rules' => 'trim|required',
			),
			'pass2' => array(
				'title' => __( 'confirm new password', 'wpsc' ),
				'rules' => 'trim|required|matches[pass1]',
			),
		);

		add_filter( 'wpsc_validation_rule_fields_dont_match_message', array( $this, 'filter_fields_dont_match_message' ) );
		$validation = wpsc_validate_form( $validation_rules );
		remove_filter( 'wpsc_validation_rule_fields_dont_match_message', array( $this, 'filter_fields_dont_match_message' ) );

		if ( is_wp_error( $validation ) ) {
			$this->set_validation_errors( $validation );
			return;
		}

		$this->reset_password( $user, $_POST['pass1'] );
		$message = apply_filters( 'wpsc_reset_password_success_message', __( 'Your password has been reset successfully. Please log in with the new password.', 'wpsc' ), $user );
		$this->message_collection->add( sprintf( $message, wpsc_get_login_url() ), 'success', 'login', 'flash' );

		wp_redirect( wpsc_get_login_url() );
		exit;
	}

	public function reset( $username = null, $key = null ) {
		if ( empty( $username ) || empty( $key ) ) {
			wp_redirect( wpsc_get_password_reminder_url() );
			exit;
		}
		$user = $this->check_password_reset_key( $key, $username );

		if ( is_wp_error( $user ) ) {
			$this->set_validation_errors( $user, 'check password reset key' );
			return $user;
		}

		return $user;
	}
}