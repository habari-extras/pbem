<?php
/*
 * TODO: Make increment configurable
 * TODO: Make config nicer (server type/name/port/ssl dropdown instead of server string)
 * TODO: allow user to choose content status
 * TODO: Store password with encryption
 * TODO: Exclude sigs
 */
class pbem extends Plugin
{
	/**
	 * Save attached JPG, PNG, and GIF files to user/files/pbem
	 **/
	public static function store_attachment( $filename = null, $content = null ) {

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
		return $filename;
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
				$user = User::identify();

 			if ( empty( $user->info->pbem__class ) ) {
				$user->info->pbem__class = 'mobile';
				$user->info->commit();
 			}

 			if ( empty( $user->info->pbem__whitelist ) ) {
				$user->info->pbem__whitelist = $user->email;
				$user->info->commit();
 			}

 			if ( empty( $user->info->pbem__content_status ) ) {
				$user->info->pbem__content_status = 'published'; // does this need to be localized?
				$user->info->commit();
 			}
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
		$users = Users::get();

		foreach ($users as $user) {
			$server_string = $user->info->pbem__server_string;
			$server_username = $user->info->pbem__server_username;
			$server_password = $user->info->pbem__server_password;

			if ($server_string) {
				$mh = imap_open( $server_string, $server_username, $server_password, OP_SILENT | OP_DEBUG )
					or Eventlog::log( _t( 'Unable to connect' ) ); // get a better error, one with imap_*
				$n = imap_num_msg( $mh );

				$whitelist = explode( "\n", $user->info->pbem__whitelist );
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
									'filepath' => '',
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
										$thisfilename = self::store_attachment( $attachments[$j]['filename'], $attachments[$j]['attachment'] );
										$attachments[$j]['filepath'] = $thisfilename;
									}
									elseif ( $structure->parts[$j]->encoding == 4) { // 4 = QUOTED-PRINTABLE
										$attachments[$j]['attachment'] = quoted_printable_decode($attachments[$j]['attachment']);
										$body .= $attachments[$j]['attachment'];
									}
								}
							}
						}
					}

					$tags = '';

					// if the first line of the message starts with 'tags:', read that line into tags.
					if ( stripos( $body, 'tags:' ) === 0 ) {
						list( $tags, $body ) = explode( "\n", $body, 2 );
						$tags = trim( substr( $tags, 5 ) );
						$body = trim( $body );
					}

					foreach( $attachments as $attachment ) {
						if ( !empty( $attachment[ 'filepath' ] ) ) {
							$imgfile = $attachment[ 'filepath' ];
							// Put the image at the beginning of the post
							$img_src = str_replace( Site::get_dir( 'user' ), Site::get_url( 'user' ), $imgfile);
							$content_image = '<img src="' . $img_src .'" class="' . $user->info->pbem__class . '">';
							$body = $content_image . $body;
						}
					}
					$postdata = array(
						'slug' =>$header->subject,
						'title' => $header->subject,
						'tags' => $tags,
						'content' => $body,
						'user_id' => $user->id,
						'pubdate' => HabariDateTime::date_create( date( 'Y-m-d H:i:s', $header->udate ) ),
						'status' => Post::status( $user->info->pbem__content_status ),
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
//						imap_delete( $mh, $i );
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

		return true;
	}

	public function filter_plugin_config( $actions, $plugin_id )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			$actions[] = _t('Configure', 'pbem');
			if ( User::identify()->can( 'PBEM' ) ) {
				// only users with the proper permission
				// should be able to retrieve the emails
				$actions[]= _t( 'Execute Now' );
			}
		}
		return $actions;
	}

	public function action_plugin_ui( $plugin_id, $action )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			switch ( $action ) {
				case _t('Configure', 'pbem') :
					$ui = new FormUI( 'pbem' );

					$server_string = $ui->append( 'text', 'server_string', 'user:pbem__server_string', _t('Mailbox (<a href="http://php.net/imap_open">imap_open</a> format): ', 'pbem') );
					$server_string->add_validator( 'validate_required' );

					$server_username = $ui->append( 'text', 'server_username', 'user:pbem__server_username', _t('Username: ', 'pbem') );
					$server_username->add_validator( 'validate_required' );

					$server_password = $ui->append( 'password', 'server_password', 'user:pbem__server_password', _t('Password: ', 'pbem') );
					$server_password->add_validator( 'validate_required' );

					$whitelist = $ui->append( 'textarea', 'whitelist', 'user:pbem__whitelist', _t( 'Senders to accept (messages sent by any others will be discarded):' ) );
					$whitelist->rows = 2;
					$whitelist->class[] = 'resizable';

					$ui->append( 'static', 'divider', '<hr>');

					$ui->append( 'text', 'class', 'user:pbem__class', _t( 'CSS Class for attached images:', 'pbem' ) );
					$ui->append( 'select', 'content_status', 'user:pbem__content_status', _t( 'Save posts as:', 'pbem' ) );
					$ui->content_status->options = array( 'published' => 'published', 'draft' => 'draft' );

					$ui->append( 'submit', 'save', _t( 'Save', 'pbem' ) );
					$ui->set_option( 'success_message', _t( 'Configuration saved', 'pbem' ) );
					$ui->out();
					break;
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

		if ( !$this->check_files() ) {
			Session::error( _t( "The web server does not have permission to create the 'files' directory for saving attachments (THis directory is also used for the Habari Media Silo." ) );
			Plugins::deactivate_plugin( __FILE__ ); //Deactivate plugin
			Utils::redirect(); // Refresh page. Unfortunately, if not done so then results don't appear
		}
	}

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
