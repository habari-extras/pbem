<?php
/*
 * TODO: Make increment configurable
 * TODO: Make config nicer (server type/name/port/ssl dropdown instead of server string)
 * TODO: allow user to choose content status
 * TODO: Store password with encryption
 * TODO: Optional pass phrase required for post to be published
 * TODO: Exclude sigs
 */
class pbem extends Plugin
{
	/**
	 * Save attached JPG, PNG, and GIF files to user/files/pbem
	 **/
	public function filter_pbem_store_local( $url, $filename = null, $content = null, $user = null ) {

		// Does pbem directory not exist? * Copied from Habari File Silo
		$pbemdir = Site::get_dir( 'user' ) . '/files/PBEM';
		if ( !is_dir( $pbemdir ) ) {

			// Create the pbem directory
			if ( !mkdir( $pbemdir, 0755 ) ) {
				return false;
			}
		}
		$tempfilename = "$pbemdir/temporary" . date('U');
		$dest = fopen( $tempfilename, 'w+') or die( 'cannot open for writing') ;
		fwrite( $dest, $content );
		fclose( $dest );

		$pi = pathinfo( $filename );

		$filename = "$pbemdir/" . $pi['filename'] . md5_file( $tempfilename ) . '.' . $pi['extension'];
		rename( $tempfilename, $filename );

		// now build up the img tag to return
		$img_src = str_replace( Site::get_dir( 'user' ), Site::get_url( 'user' ), $filename);

		return $img_src;
	}

	public function action_plugin_activation( $file )
	{
		if ( realpath( $file ) == __FILE__ ) {
			CronTab::add_cron( array(
				'name' => 'pbem_check_accounts',
				'callback' => array( __CLASS__, 'check_accounts' ),
				'increment' => 600,
				'description' => 'Check for new PBEM mail every 600 seconds.',
			) );
 			ACL::create_token( 'PBEM', 'Directly administer posts from the PBEM plugin', 'pbem' ); 
		}
	}

	public function action_plugin_deactivation( $file )
	{
		if ( realpath( $file ) == __FILE__ ) {
			CronTab::delete_cronjob( 'pbem_check_accounts' );

 			ACL::destroy_token( 'PBEM' ); 
		}
	}

	/* Go to http://your-blog/admin/pbem to immediately check the mailbox and post new posts AND SEE ERRORS. */
	function action_admin_theme_get_pbem( $handler, $theme )
	{
		self::check_accounts();
		exit;
	}

	public static function check_accounts() 
	{
		$accounts = array();
		foreach ( Users::get() as $user ) {
			if ( ! $user->info->pbem_active ) continue;
			$accounts[$user->name] = array(
				'server' => $user->info->pbem_server,
				'protocol' => $user->info->pbem_protocol,
				'security' => $user->info->pbem_security,
				'mailbox'  => $user->info->pbem_mailbox,
				'username' => $user->info->pbem_username,
				'password' => $user->info->pbem_password,
				'whitelist' => $user->info->pbem_whitelist,
				'class'    => $user->info->pbem_class,
				'status' => $user->info->pbem_status,
				'user' => $user,
				);
		}

		foreach ($accounts as $user => $account) {
			extract( $account );
			// build the $server_string used by imap_open()
			$server_string = '{' . $server;
			switch ( "$protocol+$security" ) {
				case 'imap+none':
					$server_string .= ':143/imap}';
					break;
				case 'imap+ssl':
					$server_string .= ':993/imap/ssl}';
					break;
				case 'imap+tls':
					$server_string .= ':143/imap/tls}';
					break;
				case 'pop3+none':
					$server_string .= ':110/pop3}';
					break;
				case 'pop3+ssl':
					$server_string .= ':995/pop3/ssl}';
					break;
				case 'pop3+tls':
					$server_string .= ':110/pop3/tls}';
			}
			$server_string .= $mailbox;
			$mh = imap_open( $server_string, $username, $password, OP_SILENT | OP_DEBUG )
				or Eventlog::log( _t( 'Unable to connect' ) ); // get a better error, one with imap_*
			$n = imap_num_msg( $mh );

			$whitelist = explode( "\n", $whitelist );
			$messages_skipped = 0;

			for ( $i = 1; $i <= $n; $i++ ) {

				$body = '';
				$attachments = array();

				$header = imap_header( $mh, $i );

				$whitelist_passed = false;
				foreach ( $whitelist as $item ) {
					$item = trim( strtolower( $item ) );
					if ( '' == $item ) { continue; } // blanks in whitelist
					if ( false != strpos( strtolower( $header->fromaddress ), $item ) ) { 
						$whitelist_passed = true; 
						break;
					}
				}
				if ( $whitelist_passed == false ) { 
					++$messages_skipped;
					// Move onto the next message.
					continue; 
				}

				// get the message structure
				$structure = imap_fetchstructure( $mh, $i );

				if ( !isset( $structure->parts ) ) {
					// message is not not multipart
					$body = imap_body( $mh, $i ); // fetchbody only works for single part messages.
					if ( $structure->encoding == 4 ) {
						$body = quoted_printable_decode( $body );

						// there's room here for more stuff, with strtoupper($structure->subtype...)
					}
				} 
				else {
					if ( isset( $structure->parts ) && count( $structure->parts ) ) {

						for($j = 0; $j < count($structure->parts); $j++) {

							$attachments[$j] = array(
								'is_attachment' => false,
								'filename' => '',
								'subtype' => '',
								'name' => '',
								'attachment' => '',
								'url' => '',
							);

							if ( $structure->parts[$j]->ifdparameters ) {
								foreach( $structure->parts[$j]->dparameters as $object ) {
									if ( strtolower( $object->attribute ) == 'filename' ) {
										$attachments[$j]['is_attachment'] = true;
										$attachments[$j]['filename'] = $object->value;
										$attachments[$j]['subtype'] = $structure->parts[$j]->subtype;
									}
								}
							}
							elseif ( strtolower ($structure->parts[$j]->subtype)  == 'plain' ) {
								$body .= imap_fetchbody( $mh, $i, $j+1 );			
							}

							if ( $structure->parts[$j]->ifparameters ) {
								foreach( $structure->parts[$j]->parameters as $object ) {
									if( strtolower( $object->attribute ) == 'name') {
										$attachments[$j]['is_attachment'] = true;
										$attachments[$j]['name'] = $object->value;
										if ( !isset( $attachments[$j]['subtype'] ) ) { // may not be necessary
											$attachments[$j]['subtype'] = $structure->parts[$j]->subtype; // may not be necessary
										} // may not be necessary
									}
								}
							}

							if( $attachments[$j]['is_attachment'] ) {
								$attachments[$j]['attachment'] = imap_fetchbody($mh, $i, $j+1);

								if( $structure->parts[$j]->encoding == 3 ) { // 3 = BASE64
									$attachments[$j]['attachment'] = base64_decode( $attachments[$j]['attachment'] );
									$url = '';
									$storage = $user->info->pbem_storage;
									$url = Plugins::filter("pbem_store_$storage", $url, $attachments[$j]['filename'], $attachments[$j]['attachment'], $user );
									$attachments[$j]['url'] = $url;
								}
								elseif ( $structure->parts[$j]->encoding == 4) { // 4 = QUOTED-PRINTABLE
									$attachments[$j]['attachment'] = quoted_printable_decode($attachments[$j]['attachment']);
									$body .= $attachments[$j]['attachment'];
								}
							}
						}
					}
				}

				$tags = $user->info->pbem_tags;

				// if the first line of the message starts with 'tags:', read that line into tags.
				if ( stripos( $body, 'tags:' ) === 0 ) {
					list( $additional_tags, $body ) = explode( "\n", $body, 2 );
					$tags .= ',' . trim( substr( $additional_tags, 5 ) );
					$body = trim( $body );
				}

				foreach( $attachments as $attachment ) {
					if ( !empty( $attachment[ 'url' ] ) ) {
						// Put the image at the beginning of the post
						$content_image = '<img src="' . $attachment[ 'url' ] .'"';
						if ( $class ) {
							$content_image .= ' class="' . $class . '"';
						}
						$content_image .= '>';
						$body = $content_image . $body;
					}
				}
				$postdata = array(
					'slug' =>$header->subject,
					'title' => $header->subject,
					'tags' => $tags,
					'content' => $body,
					'user_id' => $user->id,
					'pubdate' => HabariDateTime::date_create(),
					'status' => Post::status( $status ),
					'content_type' => Post::type( 'entry' ),
				);

				$headerdate = new DateTime( $header->date ); // now in explicit format
				$headerdate = $headerdate->format( _t( 'Y-m-d H:i:s' ) );

				EventLog::log( _t( 'Mail from %1$s (%2$s): "%3$s" (%4$d bytes)', 
					array(  Inputfilter::filter( $header->from[0]->mailbox . '@' . $header->from[0]->host ), $headerdate, 
						Inputfilter::filter( $header->subject ), $header->Size ) ) );
				$post = Post::create( $postdata );

				if ($post) {
					// done with the message, now delete it. Comment out if you're testing.
					imap_delete( $mh, $i );
				}
				else {
					EventLog::log( 'Failed to create a new post?' );
				}
			}
			imap_expunge( $mh );
			imap_close( $mh );
			if ( $messages_skipped > 0 ) {
				EventLog::log( _t( 'Skipped %d messages from senders not on the whitelist.', array( $messages_skipped ) ) );
			}
		}
	}

	public function filter_plugin_config( $actions, $plugin_id )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			if ( User::identify()->can( 'PBEM' ) ) {
				// only users with the proper permission
				// should be able to retrieve the emails
				$actions[]= _t( 'Execute Now' );
			}
		}
		return $actions;
	}

	public function action_form_user( $form, $edit_user )
	{
		$pbem = $form->insert('page_controls', 'wrapper','pbem', _t('Post By Email', 'pbem'));
		$pbem->class = 'container settings';
		$pbem->append( 'static', 'pbem', '<h2>' . htmlentities( _t( 'Post By Email', 'pbem' ), ENT_COMPAT, 'UTF-8' ) . '</h2>' );

		// allow users to turn off PBEM without destroying config
		$pbem_active = $form->pbem->append( 'select', 'pbem_active', 'null:null', _t('Enable post by email: ', 'pbem') );
		$pbem_active->class[] = 'item clear';
		$pbem_active->options = array( 1 => 'Yes', 0 => 'No' );
		$pbem_active->template = 'optionscontrol_select';
		$pbem_active->value = $edit_user->info->pbem_active;

		$pbem_server = $form->pbem->append( 'text', 'pbem_server', 'null:null', _t('Fully qualified domain name of server: ', 'pbem'), 'optionscontrol_text' );
		$pbem_server->class[] = 'item clear';
		$pbem_server->charlimit = 50;
		$pbem_server->value = $edit_user->info->pbem_server;

		$pbem_mailbox = $form->pbem->append( 'text', 'pbem_mailbox', 'null:null', _t( 'Mailbox name: ', 'pbem' ), 'optionscontrol_text' );
		$pbem_mailbox->class[] = 'item clear';
		$pbem_mailbox->value = $edit_user->info->pbem_mailbox;
		$pbem_mailbox->charlimit = 50;

		$pbem_protocol = $form->pbem->append( 'select', 'pbem_protocol', 'null:null', _t('Protocol: ', 'pbem') );
		$pbem_protocol->class[] = 'item clear';
		$pbem_protocol->options = array( 'imap' => 'IMAP', 'pop3' => 'POP3' );
		$pbem_protocol->template = 'optionscontrol_select';
		$pbem_protocol->value = $edit_user->info->pbem_protocol;

		$pbem_security = $form->pbem->append( 'select', 'pbem_security', 'null:null', _t('Security: ', 'pbem') );
		$pbem_security->class[] = 'item clear';
		$pbem_security->options = array( 'none' => 'None', 'ssl' => 'SSL', 'tls' => 'TLS' );
		$pbem_security->template = 'optionscontrol_select';
		$pbem_security->value = $edit_user->info->pbem_security;


		$pbem_username = $form->pbem->append( 'text', 'pbem_username', 'null:null', _t('Username: ', 'pbem'), 'optionscontrol_text' );
		$pbem->pbem_username->add_validator( 'validate_required' );
		$pbem_username->value = $edit_user->info->pbem_username;
		$pbem_username->charlimit = 50;
		$pbem_username->class[] = 'item clear';

		$pbem_password = $form->pbem->append( 'text', 'pbem_password', 'null:null', _t('Password: ', 'pbem'), 'optionscontrol_text' );
		$pbem->pbem_password->add_validator( 'validate_required' );
		$pbem_password->value = $edit_user->info->pbem_password;
		$pbem_password->charlimit = 50;
		$pbem_password->class[] = 'item clear';

		$pbem_whitelist = $form->pbem->append( 'textarea', 'pbem_whitelist', 'null:null', _t( 'Senders to accept, one per line:' ) );
		$pbem_whitelist->rows = 2;
		$pbem_whitelist->class[] = 'resizable';
		$pbem_whitelist->value = $edit_user->info->pbem_whitelist;
		$pbem_whitelist->class[] = 'item clear';

		$pbem_storage = $form->pbem->append( 'select', 'pbem_storage', 'null:null', _t('Where to store attachments: ', 'pbem' ) );
		$pbem_storage->class[] = 'item clear';
		$storage_options = array( 'local' => 'Local' );
		$pbem_storage->options = Plugins::filter('pbem_storage_provider', $storage_options );
		$pbem_storage->template = 'optionscontrol_select';
		$pbem_storage->value = $edit_user->info->pbem_storage;

		$pbem_class = $form->pbem->append( 'text', 'pbem_class', 'null:null', _t( 'CSS Class for attached images:', 'pbem' ), 'optionscontrol_text' );
		$pbem_class->class[] = 'item clear';
		$pbem_class->value = $edit_user->info->pbem_class;
		$pbem_class->charlimit = 50;

		$pbem_tags = $form->pbem->append( 'text', 'pbem_tags', 'null:null', _t('Tags to automatically apply to PBeM posts: ', 'pbem'), 'optionscontrol_text' );
		$pbem_tags->class[] = 'item clear';
		$pbem_tags->value = $edit_user->info->pbem_tags;
		$pbem_tags->charlimit = 50;

		$pbem_status = $form->pbem->append( 'select', 'pbem_status', 'null:null', _t( 'Save posts as:', 'pbem' ) );
		$pbem_status->options = array( 'published' => 'published', 'draft' => 'draft' );
		$pbem_status->template = 'optionscontrol_select';
		$pbem_status->value = $edit_user->info->pbem_status;
		$pbem_status->class[] = 'item clear';
	}

	/**
	 * add the PBEM user options to the list of valid field names
	**/
	public function filter_adminhandler_post_user_fields( $fields )
	{
		$fields['pbem_active'] = 'pbem_active';
		$fields['pbem_server'] = 'pbem_server';
		$fields['pbem_protocol'] = 'pbem_protocol';
		$fields['pbem_security'] = 'pbem_security';
		$fields['pbem_mailbox'] = 'pbem_mailbox';
		$fields['pbem_username'] = 'pbem_username';
		$fields['pbem_password'] = 'pbem_password';
		$fields['pbem_whitelist'] = 'pbem_whitelist';
		$fields['pbem_storage'] = 'pbem_storage';
		$fields['pbem_class'] = 'pbem_class';
		$fields['pbem_tags'] = 'pbem_tags';
		$fields['pbem_status'] = 'pbem_status';
		return $fields;
	}
			
	public function action_plugin_ui( $plugin_id, $action )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			switch ( $action ) {
				case _t('Execute Now','pbem') :
					$this->check_accounts();
					Utils::redirect( URL::get( 'admin', 'page=plugins' ) );
					break;
			}
		}
	}

	/** 
	 * filter the permissions so that admin users can use this plugin 
 	 **/ 
	public function filter_admin_access_tokens( $require_any, $page, $type ) 
		{ 
		// we only need to filter if the Page request is for our page 
		if ( 'pbem' == $page ) { 
			// we can safely clobber any existing $require_any 
			// passed because our page didn't match anything in 
			// the adminhandler case statement 
			$require_any= array( 'super_user' => true, 'pbem' => true ); 
		} 
		return $require_any; 
	}

	/**
	 * Initialize some internal values when plugin initializes
	 * Copied from Habari File Silo
	 */
	public function action_init()
	{
		$user_path = HABARI_PATH . '/' . Site::get_path('user', true);
		$this->root = $user_path . 'files'; //Options::get('simple_file_root');
		$this->url = Site::get_url('user', true) . 'files';  //Options::get('simple_file_url');
	}

	/**
 	 * Ensure the environment supports this plugin before permitting it
	 * to be activated
	**/
	public function filter_activate_plugin( $ok, $file )
	{
		if ( Plugins::id_from_file($file) == Plugins::id_from_file(__FILE__) ) {
			if ( !$this->check_files() ) {
				Session::error( _t( "The web server does not have permission to create the 'files' directory for saving attachments. (This directory is also used for the Habari Media Silo.)" ) );
				EventLog::log( _t( "The web server does not have permission to create the 'files' directory for saving attachments. (This directory is also used for the Habari Media Silo.)" ), 'warning', 'plugin' );
				return false;
			}
		}
		return $ok;
	}

/*
DONT DO LOCAL MEDIA HANDLING. USE HABARI MEDIA OBJECTS TO INTERACT WITH SILOS
*/

	/**
	 * Checks if files directory is usable
	 * Copied from Habari File Silo
	 */
	private function check_files() {
		$user_path = HABARI_PATH . '/' . Site::get_path('user', true);
		$this->root = $user_path . 'files'; 
		$this->url = Site::get_url('user', true) . 'files'; 

		if ( !is_dir( $this->root ) ) {
			if ( is_writable( $user_path ) ) {
				mkdir( $this->root, 0755 );
			}
			else {
				return false;
			}
		}

		return true;
	}

}
?>
