<?php
/*
 * Plugin Name: bbPress - Topic Lock
 * Description: Warns moderators if another moderator is currently viewing the same topic
 * Author: Pippin Williamson
 * Version: 1.0
 */

class BBP_Topic_Lock {

	/**
	 * Get things going. Load our actions and filters
	 *
	 * @access public
	 * @since 1.0
	 * @return void
	 */
	public function __construct() {

		add_action( 'init', array( $this, 'text_domain' ) );
		add_action( 'bbp_enqueue_scripts', array( $this, 'scripts' ) );
		add_action( 'wp_footer', array( $this, 'lock_dialog' ) );
		add_action( 'bbp_new_reply', array( $this, 'clear_topic_lock' ), 10, 7 );
		add_filter( 'heartbeat_received', array( $this, 'heartbeat_received' ), 10, 2 );

	}

	/**
	 * Text domain for localization
	 *
	 * @access public
	 * @since 1.0
	 * @return void
	 */
	public function text_domain() {
		// Set filter for plugin's languages directory
		$lang_dir = dirname( plugin_basename( __FILE__ ) ) . '/languages/';
		$lang_dir = apply_filters( 'bbp_topic_lock_languages', $lang_dir );

		// Traditional WordPress plugin locale filter
		$locale        = apply_filters( 'plugin_locale',  get_locale(), 'bbp-topic-lock' );
		$mofile        = sprintf( '%1$s-%2$s.mo', 'bbp-topic-lock', $locale );

		// Setup paths to current locale file
		$mofile_local  = $lang_dir . $mofile;
		$mofile_global = WP_LANG_DIR . '/bbp-topic-lock/' . $mofile;

		if ( file_exists( $mofile_global ) ) {
			// Look in global /wp-content/languages/bbp-topic-lock folder
			load_textdomain( 'bbp-topic-lock', $mofile_global );
		} elseif ( file_exists( $mofile_local ) ) {
			// Look in local /wp-content/plugins/bbp-topic-lock/languages/ folder
			load_textdomain( 'bbp-topic-lock', $mofile_local );
		} else {
			// Load the default language files
			load_plugin_textdomain( 'bbp-topic-lock', false, $lang_dir );
		}
	}

	/**
	 * Load JS and CSS
	 *
	 * @access public
	 * @since 1.0
	 * @return void
	 */
	public function scripts() {
		if( ! bbp_is_single_topic() )
			return;

		wp_enqueue_script( 'heartbeat' );
		wp_enqueue_script( 'bbp-other-mods-viewing', plugins_url( 'js/front.js' , __FILE__ ), array( 'jquery' ) );
		wp_localize_script( 'bbp-other-mods-viewing', 'bbp_mods_viewing', array(
			'topic_id' => get_the_ID()
		) );

		wp_enqueue_style( 'bbp-other-mods-viewing', plugins_url( 'css/front.css' , __FILE__ ));
	}

	/**
	 * Tie into the heartbeat received in order to lock topics
	 *
	 * @access public
	 * @since 1.0
	 * @return array
	 */
	public function heartbeat_received( $response, $data ) {

		if( ! isset( $data['bbp-mods-viewing'] ) )
			return $response;

		$topic_id = $data['bbp-mods-viewing']['topic_id'];
		$user_id  = get_current_user_id();

		if( ! current_user_can( 'moderate' ) )
			return $response; // Only set the lock for moderators

		$mod = $this->check_topic_lock( $topic_id );

		if( ! $mod ) {
			// Only lock the topic if it is not already locked
			$this->set_topic_lock( $topic_id );
		}

		return $response;

	}

	/**
	 * Display the lock dialog
	 *
	 * @access public
	 * @since 1.0
	 * @return void
	 */
	public function lock_dialog() {

		if( ! class_exists( 'bbPress' ) )
			return;

		if( ! bbp_is_single_topic() || ! current_user_can( 'moderate' ) )
			return;

		$mod = $this->check_topic_lock( bbp_get_topic_id() );

		if( $mod ) {
			$user_data = get_userdata( $mod );
			echo '<div id="topic-lock-dialog">';
				do_action( 'bbp_topic_lock_dialog_top', bbp_get_topic_id() );
				echo sprintf( __( '<p>%s is currently viewing this topic.</p>', 'bbpress-topic-lock' ), $user_data->display_name );
				echo '<p><a href="' . bbp_get_forums_url() . '">' . __( 'Get out of here', 'bbpress-topic-lock' ) . '</a> | <a href="#" class="bbp-topic-lock-close">' . __( 'Ignore, close notice', 'bbpress-topic-lock' ) . '</a></p>';
				do_action( 'bbp_topic_lock_dialog_bottom', bbp_get_topic_id() );
			echo '</div>';
		}
	}

	/**
	 * Set the topic lock
	 *
	 * @access public
	 * @since 1.0
	 * @return array
	 */
	private function set_topic_lock( $topic_id = 0 ) {

		if ( 0 == ( $user_id = get_current_user_id() ) )
			return false;

		$now  = time();
		$lock = "$now:$user_id";

		update_post_meta( $topic_id, '_edit_lock', $lock );

		return array( $now, $user_id );
	}

	/**
	 * Check if a topic is locked
	 *
	 * @access public
	 * @since 1.0
	 * @return int $user_id The ID of the moderator viewing the topic
	 */
	private function check_topic_lock( $topic_id = 0 ) {

		if ( ! $lock = get_post_meta( $topic_id, '_edit_lock', true ) )
			return false;

		$lock = explode( ':', $lock );
		$time = $lock[0];
		$user = isset( $lock[1] ) ? $lock[1] : 0;

		$time_window = apply_filters( 'bbp_check_topic_lock_window', 120 );

		if ( $time && $time > time() - $time_window && $user != get_current_user_id() )
			return $user;
		return false;
	}

	/**
	 * Clear a topic lock when a new reply is published by a moderator
	 *
	 * @access public
	 * @since 1.0
	 * @return void
	 */
	public function clear_topic_lock( $reply_id = 0, $topic_id = 0, $forum_id = 0, $anonymous_data = false, $author_id = 0, $is_edit = false, $reply_to = 0 ) {

		if( user_can( $author_id, 'moderate' ) ) {
			$lock = $this->check_topic_lock( $topic_id );
			if( $lock == $author_id ) {
				// Only delete the lock if the new reply is mod it is locked to
				delete_post_meta( $topic_id, '_edit_lock' );
			}
		}
	}

}
new BBP_Topic_Lock;