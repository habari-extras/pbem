<?php

/*
 * TODO: Make increment configurable
 * TODO: Make config nicer (server type/name/port/ssl dropdown instead of server string)
 * TODO: allow user to choose content status
 */
class pbem extends Plugin
{
	/**
	 * Save attached JPG, PNG, and GIF files to user/files/pbem
	 **/
	public static function store_attachment( $filename = null, $content = null ) {

		// Does pbem directory not exist? * Copied from Habari File Silo
		$pbemdir = Site::get_dir( 'user' ) . '/files/PBEM';
// Utils::debug( $pbemdir );
		if ( !is_dir( $pbemdir ) ) {

			// Create the pbem directory
			if ( !mkdir( $pbemdir, 0755 ) ) {
				return false;
			}
		}
		$filename = "$pbemdir/$filename";
// Utils::debug( $content );
// Utils::debug( $filename );
		$dest = fopen( $filename, 'w+') or die( 'cannot open for writing') ;
		fwrite( $dest, $content );
		return fclose( $dest );
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

			if ( !Options::get( 'user:pbem__class' ) ) {
				Options::set( 'user:pbem__class', 'mobile' );
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
				for ( $i = 1; $i <= $n; $i++ ) {

					$body = '';

					$header = imap_header( $mh, $i );
					$structure = imap_fetchstructure( $mh, $i );

					if ( !isset( $structure->parts )) {
						// message is not not multipart
						$body = imap_body( $mh, $i ); // fetchbody only works for single part messages.
						if ( $structure->encoding == 4 ) {
							$body = quoted_printable_decode( $body );

							// there's room here for more stuff, with strtoupper($structure->subtype...)
						}
// Utils::debug( 'not multipart!' );
					} 
					else {
						$attachments = array();
						if ( isset( $structure->parts ) && count( $structure->parts ) ) {

							for($j = 0; $j < count($structure->parts); $j++) {

								$attachments[$j] = array(
									'is_attachment' => false,
									'filename' => '',
									'subtype' => '',
									'name' => '',
									'attachment' => ''
								);
// Utils::debug($structure->parts[$j]->subtype); 		

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
// Utils::debug( 'PLAIN!!!' );			
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
										self::store_attachment( $attachments[$j]['filename'], $attachments[$j]['attachment'] );
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
					if ( stripos($body, 'tags:' ) === 0) {
						list($tags, $body) = explode("\n", $body, 2);
						$tags = trim(substr($tags, 5));
						$body = trim($body);
					}

					foreach( $attachments as $attachment ) {
						if ( !empty( $attachment[ 'filename' ] ) ) {
							$imgfile = $attachment['filename'];
							// Put the image at the beginning of the post
							$img_src = Site::get_url( 'user' ) . "/files/PBEM/" . $imgfile;
							$content_image = '<img src="' . $img_src .'" class="' . Options::get( 'user:pbem__class' ) . '">';
							$body = $content_image . $body;
						}
					}
// Utils::debug( $structure);
					$postdata = array(
						'slug' =>$header->subject,
						'title' => $header->subject,
						'tags' => $tags,
						'content' => $body,
						'user_id' => $user->id,
						'pubdate' => HabariDateTime::date_create( date( 'Y-m-d H:i:s', $header->udate ) ),
						'status' => Post::status('published'),
						'content_type' => Post::type('entry'),
					);
// Utils::debug( $postdata ); 
// Utils::debug( $attachments );
					// This htmlspecialchars makes logs with &lt; &gt; etc. Is there a better way to sanitize?
					EventLog::log( htmlspecialchars( sprintf( 'Mail from %1$s (%2$s): "%3$s" (%4$d bytes)', $header->fromaddress, $header->date, $header->subject, $header->Size ) ) );

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
					$ui->append( 'static', 'divider', '<hr>');

					$ui->append( 'text', 'class', 'user:pbem__class', _t( 'CSS Class for attached images', 'pbem' ) );

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
			Utils::redirect(); //Refresh page. Unfortunately, if not done so then results don't appear
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
