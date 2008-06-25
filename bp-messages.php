<?php

define ( 'BP_MESSAGES_VERSION', '0.2' );

$bp_messages_table_name 		= $wpdb->base_prefix . 'bp_messages';
$bp_messages_table_name_deleted = $bp_messages_table_name . '_deleted';
$bp_messages_image_base 		= get_option('siteurl') . '/wp-content/mu-plugins/bp-messages/images';
$bp_messages_slug 				= 'messages';

include_once( 'bp-messages/bp-messages-classes.php' );
include_once( 'bp-messages/bp-messages-ajax.php' );
include_once( 'bp-messages/bp-messages-cssjs.php' );
include_once( 'bp-messages/bp-messages-admin.php' );
include_once( 'bp-messages/bp-messages-templatetags.php' );

/**************************************************************************
 messages_install()
 
 Sets up the database tables ready for use on a site installation.
 **************************************************************************/

function messages_install( $version ) {
	global $wpdb, $bp_messages_table_name, $bp_messages_table_name_deleted;

	$sql[] = "CREATE TABLE ". $bp_messages_table_name ." (
		  		id int(11) NOT NULL AUTO_INCREMENT,
		  		sender_id int(11) NOT NULL,
		  		recipient_id int(11) NOT NULL,
		  		thread_id int(11) NOT NULL,
		  		subject varchar(200) NOT NULL,
		  		message longtext NOT NULL,
		  		is_read bool DEFAULT 0,
		  		date_sent int(11) NOT NULL,
		  		PRIMARY KEY id (id)
		 	   );";
	
	$sql[] = "CREATE TABLE ". $bp_messages_table_name_deleted ." (
		  		id int(11) NOT NULL AUTO_INCREMENT,
		  		thread_id int(11) NOT NULL,
		  		user_id int(11) NOT NULL,
		  		is_deleted tinyint(1) NOT NULL DEFAULT 0,
		  		PRIMARY KEY id (id)
				);";

	require_once( ABSPATH . 'wp-admin/upgrade-functions.php' );
	dbDelta($sql);
	
	add_site_option('bp-messages-version', $version);
}

/**************************************************************************
 messages_add_admin_menu()
 
 Creates the administration interface menus and checks to see if the DB
 tables are set up.
 **************************************************************************/

function messages_add_admin_menu() {	
	global $wpdb, $bp_messages_table_name, $bp_messages, $userdata;

	if ( $wpdb->blogid == $userdata->primary_blog ) {	
		if ( $inbox_count = BP_Messages_Thread::get_inbox_count() ) {
			$count_indicator = ' <span id="awaiting-mod" class="count-1"><span class="message-count">' . $inbox_count . '</span></span>';
		}
		
		add_menu_page    ( __('Messages'), sprintf( __('Messages%s'), $count_indicator ), 1, basename(__FILE__), "messages_inbox" );
		add_submenu_page ( basename(__FILE__), __('Messages &rsaquo; Inbox'), __('Inbox'), 1, basename(__FILE__), "messages_inbox" );	
		add_submenu_page ( basename(__FILE__), __('Messages &rsaquo; Sent Messages'), __('Sent Messages'), 1, "messages_sentbox", "messages_sentbox" );	
		add_submenu_page ( basename(__FILE__), __('Messages &rsaquo; Compose'), __('Compose'), 1, "messages_write_new", "messages_write_new" );

		// Add the administration tab under the "Site Admin" tab for site administrators
		add_submenu_page ( 'wpmu-admin.php', __('Messages'), __('Messages'), 1, basename(__FILE__), "messages_settings" );
	}
	
	/* Need to check db tables exist, activate hook no-worky in mu-plugins folder. */
	if ( ( $wpdb->get_var( "show tables like '%" . $bp_messages_table_name . "%'" ) == false ) || ( get_site_option('bp-messages-version') < BP_MESSAGES_VERSION ) )
		messages_install(BP_MESSAGES_VERSION);
}
add_action( 'admin_menu', 'messages_add_admin_menu' );


/**************************************************************************
 messages_admin_setup()
 
 Setup CSS, JS and other things needed for the admin side
 of the messaging component.
 **************************************************************************/

function messages_admin_setup() {		
	add_action( 'admin_head', 'messages_add_css' );
	add_action( 'admin_head', 'messages_add_js' );
}
add_action( 'admin_menu', 'messages_admin_setup' );


/**************************************************************************
 messages_setup_nav()
 
 Set up front end navigation.
 **************************************************************************/

function messages_setup_nav() {
	global $loggedin_userid, $loggedin_domain;
	global $current_userid, $current_domain;
	global $bp_nav, $bp_options_nav, $bp_users_nav;
	global $bp_messages_slug, $bp_options_avatar, $bp_options_title;
	global $current_component;

	$bp_nav[2] = array(
		'id'	=> $bp_messages_slug,
		'name'  => 'Messages', 
		'link'  => $loggedin_domain . $bp_messages_slug
	);

	if ( $current_component == $bp_messages_slug ) {
		if ( bp_is_home() ) {
			$bp_options_title = __('My Messages');
			$bp_options_nav[$bp_messages_slug] = array(
				'inbox'	   => array( 
					'name' => __('Inbox'),
					'link' => $loggedin_domain . $bp_messages_slug . '/' ),
				'sentbox'  => array(
					'name' => __('Sent Messages'),
					'link' => $loggedin_domain . $bp_messages_slug . '/sentbox' ),
				'compose' => array( 
					'name' => __('Compose'),
					'link' => $loggedin_domain . $bp_messages_slug . '/compose' )
			);
		} else {
			$bp_options_avatar = xprofile_get_avatar( $current_userid, 1 );
			$bp_options_title = bp_user_fullname( $current_userid, false ); 
		}
	}
}
add_action( 'wp', 'messages_setup_nav' );


/**************************************************************************
 messages_catch_action()
 
 Catch actions via pretty urls.
 **************************************************************************/

function messages_catch_action() {
	global $bp_messages_slug, $current_component, $current_blog;
	global $loggedin_userid, $current_userid, $current_action;
	global $bp_options_nav, $action_variables, $thread_id;

	if ( $current_component == $bp_messages_slug && $current_blog->blog_id > 1 && $loggedin_userid == $current_userid ) {
		if ( $current_action == '' )
			$current_action = 'inbox';
		
		if ( $current_action == 'inbox' ) {
			bp_catch_uri( 'messages/index' );
		} else if ( $current_action == 'sentbox' ) {
			bp_catch_uri( 'messages/sentbox' );
		} else if ( $current_action == 'compose' ) {
			bp_catch_uri( 'messages/compose' );
		} else if ( $current_action == 'view' && !empty($action_variables) ) {
			$thread_id = $action_variables[0];
		
			if ( !$thread_id || !is_numeric($thread_id) || !BP_Messages_Thread::check_access($thread_id) ) {
				$current_action = 'inbox';
				bp_catch_uri( 'messages/index' );
			} else {
				$bp_options_nav[$bp_messages_slug]['view'] = array(
					'name' => __('From: ' . BP_Messages_Thread::get_sender($thread_id)),
					'link' => $loggedin_domain . $bp_messages_slug . '/'			
				);
			
				bp_catch_uri( 'messages/view' );
			}
		} else {
			$current_action = 'inbox';
			bp_catch_uri( 'messages/index' );
		}
	}
}
add_action( 'wp', 'messages_catch_action' );

/**************************************************************************
 messages_template()
 
 Set up template tags for use in templates.
 **************************************************************************/

function messages_template() {
	global $messages_template, $current_userid;
	global $current_component, $bp_messages_slug;
	global $current_action, $loggedin_domain;
	
	if ( $current_component == $bp_messages_slug ) {
		if ( $current_action == 'inbox' || $current_action == 'sentbox' )
			$messages_template = new BP_Messages_Template($current_action, $current_userid);
		
		if ( $current_action == 'view' || $current_action == 'compose' ) {
			echo "<script type='text/javascript' src='" . $loggedin_domain . "wp-includes/js/jquery/jquery.js?ver=1.2.3'></script>";
			messages_add_js();
		}
	}
	
}
add_action( 'wp_head', 'messages_template' );


/**************************************************************************
 messages_write_new()
 
 Handle and display the write new messages screen.
 **************************************************************************/

function messages_write_new( $username = '', $subject = '', $content = '', $type = '', $message = '' ) { ?>
	<?php
	global $messages_write_new_action;
	
	if ( $messages_write_new_action == '' )
		$messages_write_new_action = 'admin.php?page=bp-messages.php&amp;mode=send';
	?>
	
	<div class="wrap">
		<h2><?php _e('Compose Message') ?></h2>
		
		<?php
			if ( $message != '' ) {
				$type = ( $type == 'error' ) ? 'error' : 'updated';
		?>
			<div id="message" class="<?php echo $type; ?> fade">
				<p><?php echo $message; ?></p>
			</div>
		<?php } ?>
						
		<form action="<?php echo $messages_write_new_action ?>" method="post" id="send_message_form">
		<div id="poststuff">
			<p>			
			<div id="titlediv">
				<h3><?php _e("Send To") ?> <small>(Use username - autocomplete coming soon)</small></h3>
				<div id="titlewrap">
					<input type="text" name="send_to" id="send_to" value="<?php echo $username; ?>" />
				</div>
			</div>
			</p>

			<p>
			<div id="titlediv">
				<h3><?php _e("Subject") ?></h3>
				<div id="titlewrap">
					<input type="text" name="subject" id="subject" value="<?php echo $subject; ?>" />
				</div>
			</div>
			</p>
			
			<p>
				<div id="postdivrich" class="postarea">
					<h3><?php _e("Message") ?></h3>
					<div id="editorcontainer">
						<textarea name="content" id="message_content" rows="15" cols="40"><?php echo $content; ?></textarea>
					</div>
				</div>
			</p>
			
			<p class="submit">
					<input type="submit" value="<?php _e("Send") ?> &raquo;" name="send" id="send" style="font-weight: bold" />
			</p>
			
			<input type="hidden" name="thread_id" id="thread_id" value="<?php BP_Messages_Thread::get_new_thread_id() ?>" />
		
		</div>
		</form>
		<script type="text/javascript">
			document.getElementById("send_to").focus();
		</script>
		
	</div>
	<?php
}

function messages_inbox() {
	messages_box( 'inbox', __('Inbox') );
}

function messages_sentbox() {
	messages_box( 'sentbox', __('Sent Messages') );
}


/**************************************************************************
 messages_box()
  
 Handles and displays the messages in a particular box for the current user.
 **************************************************************************/

function messages_box( $box = 'inbox', $display_name = 'Inbox', $message = '', $type = '' ) {
	global $bp_messages_image_base, $userdata;
	
	if ( isset($_GET['mode']) && isset($_GET['thread_id']) && $_GET['mode'] == 'view' ) {
		messages_view_thread( $_GET['thread_id'], 'inbox' );
	} else if ( isset($_GET['mode']) && isset($_GET['thread_id']) && $_GET['mode'] == 'delete' ) {
		messages_delete_thread( $_GET['thread_id'], $box, $display_name );
	} else if ( isset($_GET['mode']) && isset($_POST['thread_ids']) && $_GET['mode'] == 'delete_bulk' ) {
		messages_delete_thread( $_POST['thread_ids'], $box, $display_name );
	} else if ( isset($_GET['mode']) && $_GET['mode'] == 'send' ) {
		messages_send_message( $_POST['send_to'], $_POST['subject'], $_POST['content'], $_POST['thread_id'] );
	} else {
	?>
		<div class="wrap">
			<h2><?php echo $display_name ?></h2>
			<form action="admin.php?page=bp-messages.php&amp;mode=delete_bulk" method="post">

			<?php
				if ( $message != '' ) {
					$type = ( $type == 'error' ) ? 'error' : 'updated';
			?>
				<div id="message" class="<?php echo $type; ?> fade">
					<p><?php echo $message; ?></p>
				</div>
			<?php } ?>
	
			<table class="widefat" id="message-list" style="margin-top: 10px;">
				<tbody id="the-list">
		<?php

		$threads = BP_Messages_Thread::get_threads_for_user( $box, $userdata->ID, false, $userdata->ID );
		
		if ( $threads && $threads['have_messages'] ) {
			$counter = 0;
			foreach ( $threads as $thread ) {
				if ( $thread->messages ) {
					if ( $thread->unread_count ) { 
						$is_read = '<img src="' . $bp_messages_image_base .'/email.gif" alt="New Message" /><a href="admin.php?page=bp-messages.php&amp;mode=view&amp;thread_id=' . $thread->thread_id . '"><span id="awaiting-mod" class="count-1"><span class="message-count">' . $thread->unread_count . '</span></span></a>';
						$new = " unread";
					} else { 
						$is_read = '<img src="' . $bp_messages_image_base .'/email_open.gif" alt="Older Message" />'; 
					}
					
					if ( $counter % 2 == 0 ) 
						$class = "alternate";
					?>
						<tr class="<?php echo $class . $new ?>" id="<?php echo $message->id ?>">
							<td class="is-read"><?php echo $is_read ?></td>
							<td class="avatar">
								<?php if ( function_exists('xprofile_get_avatar') )
										echo xprofile_get_avatar($thread->creator_id, 1);
								?>
							</td>
							<td class="sender-details">
								<?php if ( $box == 'sentbox') { ?>
									<h3>To: <?php echo $thread->recipients ?></h3>
								<?php } else { ?>
									<h3>From: <?php echo bp_core_get_userlink($thread->creator_id) ?></h3>
								<?php } ?>
								<?php echo bp_format_time($thread->last_post_date) ?>
							</td>
							<td class="message-details">
								<h4><a href="admin.php?page=bp-messages.php&amp;mode=view&amp;thread_id=<?php echo $thread->thread_id ?>"><?php echo stripslashes($thread->subject) ?></a></h4>
								<?php echo bp_create_excerpt($thread->message, 20); ?>
							</td>
							<td width="50"><a href="admin.php?page=bp-messages.php&amp;mode=view&amp;thread_id=<?php echo $thread->thread_id ?>">View</a></td>
							<td width="50"><a href="admin.php?page=bp-messages.php&amp;mode=delete&amp;thread_id=<?php echo $thread->thread_id ?>">Delete</a></td>
							<td width="25"><input type="checkbox" name="thread_ids[]" value="<?php echo $thread->thread_id ?>" /></td>
						</tr>
					<?php
		
					$counter++;
					unset($class);
					unset($new);
					unset($is_read);
				}
			}
			
			echo '
				</tbody>
				</table>
				<p class="submit">
					<input id="deletebookmarks" class="button" type="submit" onclick="return confirm(\'You are about to delete these messages permanently.\n[Cancel] to stop, [OK] to delete.\')" value="Delete Checked Messages &raquo;" name="deletebookmarks"/>
				</p>
				</form>	
			</div>';
			
		} else {
			?>
				<tr class="alternate">
				<td colspan="7" style="text-align: center; padding: 15px 0;">
					<?php _e('You have no messages in your'); echo ' ' . $display_name . '.'; ?>
				</td>
				</tr>
			<?php
		}
		?>
			</tbody>
			</table>
			</form>	
		</div>
		<?php
	}
}

/**************************************************************************
 messages_send_message()
  
 Send a message.
 **************************************************************************/

function messages_send_message($to_user, $subject, $content, $thread_id, $from_ajax = false, $from_template = false) {
	global $userdata;
	global $messages_write_new_action;
	global $message, $type;

	if ( is_numeric($to_user) ) {
		$to_username = bp_core_get_username($to_user);
	} else {
		$to_username = $to_user;
		$to_user = bp_core_get_userid($to_user);
	}

	if ( is_null($to_user) ) {
		if ( !$from_ajax ) {
			messages_write_new( '', $subject, $content, 'error', __('The username you provided was invalid.'), $messages_write_new_action );
		} else {
			return array('status' => 0, 'message' => __('There was an error sending the reply, please try again.'));
		}
	} else if ( $subject == '' || $content == '' || $thread_id == '' ) {
		if ( !$from_ajax ) {
			messages_write_new( $to_user, $subject, $content, 'error', __('Please make sure you fill in all the fields.'), $messages_write_new_action );
		} else {
			return array('status' => 0, 'message' => __('Please make sure you have typed a message before sending a reply.'));
		}
	} else {
		$message = new BP_Messages_Message;
		$message->recipient_id = $to_user;
		$message->recipient_username = $to_username;
		$message->subject = $subject;
		$message->message = $content;
		$message->is_read = 0;
		$message->thread_id = $thread_id;

		unset($_GET['mode']);

		if ( !$message->send() ) {
			if ( $from_ajax ) {
				return array('status' => 0, 'message' => __('Message could not be sent, please try again.'));
			} else if ( $from_template ) {
				// TODO
				echo 'Message could not be sent, please try again.';
			} else {
				messages_box( 'inbox', __('Inbox'), __('Message could not be sent, please try again.'), 'error' );	
			}
		} else {
			if ( $from_ajax ) {
				return array('status' => 1, 'message' => __('Message sent successfully!'), 'reply' => $message);
			} else if ( $from_template ) {
				// TODO
				echo 'Message sent successfully!';
			} else {
				messages_box( 'inbox', __('Inbox'), __('Message sent successfully!'), 'success' );
			}
		}
	}
}

/**************************************************************************
 messages_delete_thread()
  
 Handles the deletion of a single or multiple threads.
 **************************************************************************/

function messages_delete_thread( $thread_ids, $box, $display_name ) {
	global $wpdb;
	
	$type = 'success';
	
	if ( is_array($thread_ids) ) {
		$message = __('Messages deleted successfully!');
		
		for ( $i = 0; $i < count($thread_ids); $i++ ) {
			if ( !$status = BP_Messages_Thread::delete($thread_ids[$i]) ) {
				$message = __('There was an error when deleting messages. Please try again.');
				$type = 'error';
			}
		}
	} else {
		$message = __('Message deleted successfully!');
		
		if ( !$status = BP_Messages_Thread::delete($thread_ids) ) {
			$message = __('There was an error when deleting that message. Please try again.');
			$type = 'error';
		}
	}
	
	unset($_GET['mode']);
	messages_box( $box, $display_name, $message, $type );
}


function messages_view_thread( $thread_id ) {
	global $bp_messages_image_base, $userdata;

	$thread = new BP_Messages_Thread($thread_id, true, null, 'all');
	
	if ( !$thread->has_access ) {
		unset($_GET['mode']);
		messages_inbox( __('There was an error viewing this message, please try again.'), 'error' );
	} else {
		if ( $thread->messages ) { ?>	
			<div class="wrap">
				<h2 id="message-subject"><?php echo $thread->subject; ?></h2>
				<table class="form-table">
					<tbody>
						<tr>
							<td>
								<img src="<?php echo $bp_messages_image_base ?>/email_open.gif" alt="Message" style="vertical-align: top;" /> &nbsp;
								<?php _e('Sent by') ?> <?php echo bp_core_get_userlink($thread->creator_id) ?>
								<?php _e('to') ?> <?php echo $thread->recipients ?>. 
								<?php _e('Most recently on') ?> <?php echo bp_format_time($thread->last_post_date) ?>
							</td>
						</tr>
					</tbody>
				</table>
		<?php
			foreach ( $thread->messages as $message ) {
				$message->mark_as_read();				
				?>
					<a name="<?php echo 'm-' . $message->id ?>"></a>
					<div class="message-box">
						<div class="avatar-box">
							<?php if ( function_exists('xprofile_get_avatar') ) 
								echo xprofile_get_avatar($message->sender_id, 1);
							?>
				
							<h3><?php echo bp_core_get_userlink($message->sender_id) ?></h3>
							<small><?php echo bp_format_time($message->date_sent) ?></small>
						</div>
						<?php echo stripslashes($message->message); ?>
						<div class="clear"></div>
					</div>
				<?php
			}
		
			?>
				<form id="send-reply" action="<?php echo get_option('home'); ?>/wp-admin/admin.php?page=bp-messages.php&amp;mode=send" method="post">
					<div class="message-box">
							<div id="messagediv">
								<div class="avatar-box">
									<?php if ( function_exists('xprofile_get_avatar') ) 
										echo xprofile_get_avatar($userdata->ID, 1);
									?>
					
									<h3><?php _e("Reply: ") ?></h3>
								</div>
								<label for="reply"></label>
								<div>
									<textarea name="content" id="message_content" rows="15" cols="40"><?php echo $content; ?></textarea>
								</div>
							</div>
							<p class="submit">
								<input type="submit" name="send" value="Send Reply &raquo;" id="send_reply_button" />
							</p>
					</div>
					<?php if ( function_exists('wp_nonce_field') )
						wp_nonce_field('messages_sendreply');
					?>
					<input type="hidden" name="thread_id" id="thread_id" value="<?php echo $thread->thread_id ?>" />
					<input type="hidden" name="send_to" id="send_to" value="<?php echo $thread->creator_id ?>" />
					<input type="hidden" name="subject" id="subject" value="<?php _e('Re: '); echo $thread->subject; ?>" />
				</form>
			</div>
			<?php
		}
	}
}



?>