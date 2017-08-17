<?php
/*
Plugin Name: PDF Forms Filler for Contact Form 7
Plugin URI: https://github.com/maximum-software/wpcf7-pdf-forms
Description: Create Contact Form 7 forms from PDF forms.  Get PDF forms filled automatically and attached to email messages upon form submission on your website.  Uses https://pdf.ninja API for working with PDF files.
Version: 0.1.7
Author: Maximum.Software
Author URI: https://maximum.software/
Text Domain: wpcf7-pdf-forms
Domain Path: /languages
License: GPLv3
*/

require_once untrailingslashit( dirname( __FILE__ ) ) . '/inc/tgm-config.php';

if( ! class_exists( 'WPCF7_Pdf_Forms' ) )
{
	class WPCF7_Pdf_Forms
	{
		const VERSION = '0.1.7';
		
		private static $instance = null;
		private $pdf_ninja_service = null;
		private $service = null;
		private $registered_services = false;
		
		private function __construct()
		{
			load_plugin_textdomain( 'wpcf7-pdf-forms', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
			add_action( 'admin_notices', array( $this, 'admin_notices' ) );
			add_action( 'plugins_loaded', array( $this, 'plugin_init' ) );
		}
		
		/*
		 * Runs after all plugins have been loaded
		 */
		public function plugin_init()
		{
			if( ! class_exists('WPCF7') )
				return;
			
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
			
			add_action( 'wp_ajax_wpcf7_pdf_forms_upload', array( $this, 'wp_ajax_upload' ) );
			add_action( 'wp_ajax_wpcf7_pdf_forms_query_fields', array( $this, 'wp_ajax_query_fields' ) );
			add_action( 'wp_ajax_wpcf7_pdf_forms_query_attachments', array( $this, 'wp_ajax_query_attachments' ) );
			
			add_action( 'admin_init', array( $this, 'extend_tag_generator' ), 80 );
			add_action( 'admin_menu', array( $this, 'register_services') );
			
			add_action( 'wpcf7_before_send_mail', array( $this, 'fill_and_attach_pdfs' ) );
			add_action( 'wpcf7_after_create', array( $this, 'update_post_attachments' ) );
			add_action( 'wpcf7_after_update', array( $this, 'update_post_attachments' ) );
		}
		
		/*
		 * Returns a global instance of this class
		 */
		public static function get_instance()
		{
			if( !self::$instance )
				self::$instance = new self;
			
			return self::$instance;
		}
		
		/**
		 * Prints admin notices
		 */
		public function admin_notices()
		{
			if( ! class_exists('WPCF7') )
			{
				echo WPCF7_Pdf_Forms::render( 'notice_error', array(
					'label' => esc_html__( "PDF Forms Filler for CF7 plugin error", 'wpcf7-pdf-forms' ),
					'message' => esc_html__( "The required plugin 'Contact Form 7' is not installed!", 'wpcf7-pdf-forms' ),
				) );
				return;
			}
			
			if( ( $service = $this->get_service() ) )
				$service->admin_notices();
		}
		
		/**
		 * Loads the Pdf.Ninja service module
		 */
		private function load_pdf_ninja_service()
		{
			if( ! $this->pdf_ninja_service )
			{
				require_once untrailingslashit( dirname( __FILE__ ) ) . '/modules/pdf-ninja.php';
				$this->pdf_ninja_service = WPCF7_Pdf_Ninja::get_instance();
			}
			
			return $this->pdf_ninja_service;
		}
		
		/**
		 * Returns the service module instance
		 */
		public function get_service()
		{
			$this->register_services();
			
			if( ! $this->service )
				$this->set_service( $this->load_pdf_ninja_service() );
			
			return $this->service;
		}
		
		/**
		 * Sets the service module instance
		 */
		public function set_service( $service )
		{
			return $this->service = $service;
		}
		
		/**
		 * Adds necessary admin scripts and styles
		 */
		public function admin_enqueue_scripts( $hook )
		{
			if( false !== strpos($hook, 'wpcf7') )
			{
				wp_register_script( 'wpcf7_pdf_forms_admin_script', plugin_dir_url( __FILE__ ) . 'js/admin.js', array( 'jquery' ), self::VERSION );
				wp_register_style( 'wpcf7_pdf_forms_admin_style', plugin_dir_url( __FILE__ ) . 'css/admin.css', array( ), self::VERSION );
				
				wp_localize_script( 'wpcf7_pdf_forms_admin_script', 'wpcf7_pdf_forms', array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'ajax_nonce' => wp_create_nonce( 'wpcf7-pdf-forms-ajax-nonce' ),
					'__File_not_specified' => __( 'File not specified', 'wpcf7-pdf-forms' ),
					'__Get_Tags' => __( 'Get Tags', 'wpcf7-pdf-forms' ),
					'__Delete' => __( 'Delete', 'wpcf7-pdf-forms' ),
					'__Unknown_error' => __( 'Unknown error', 'wpcf7-pdf-forms' ),
					'__No_WPCF7' => __( 'Please copy/paste tags manually', 'wpcf7-pdf-forms' ),
				) );
				
				wp_enqueue_script( 'thickbox' );
				wp_enqueue_style( 'thickbox' );
				
				wp_enqueue_script( 'wpcf7_pdf_forms_admin_script' );
				wp_enqueue_style( 'wpcf7_pdf_forms_admin_style' );
			}
		}
		
		/**
		 * Registers PDF forms category and PDF.Ninja service with the Contact Form 7 integration class
		 */
		public function register_services()
		{
			if( $this->registered_services )
				return;
			
			require_once untrailingslashit( dirname( __FILE__ ) ) . '/modules/service.php';
			
			$integration = WPCF7_Integration::get_instance();
			$integration->add_category( 'pdf_forms', __('PDF Forms', 'wpcf7-pdf-forms') );
			
			$this->registered_services = true;
			
			$pdf_ninja_service = $this->load_pdf_ninja_service();
			if( $pdf_ninja_service )
				$integration->add_service( $pdf_ninja_service->get_service_name(), $pdf_ninja_service );
			
			do_action( 'wpcf7_pdf_forms_register_services' );
		}
		
		/**
		 * Attaches an attachment to a post
		 */
		public function post_add_pdf( $post_id, $attachment_id )
		{
			wp_update_post( array( 'ID' => $attachment_id, 'post_parent' => $post_id ) );
		}
		
		/**
		 * Retreives all PDF attachments of a post
		 */
		public function post_get_all_pdfs( $post_id )
		{
			$pdfs = array();
			foreach( get_attached_media( 'application/pdf', $post_id ) as $attachment )
				$pdfs[$attachment->ID] = $attachment->ID;
			return $pdfs;
		}
		
		/**
		 * Removes an attachment from a post
		 */
		public function post_del_pdf( $post_id, $attachment_id )
		{
			wp_update_post( array( 'ID' => $attachment_id, 'post_parent' => 0 ) );
		}
		
		/**
		 * Hook that runs on form save and attaches all PDFs that were attached to forms with the editor
		 */
		public function update_post_attachments( $contact_form )
		{
			$post_id = $contact_form->id();
			
			$new_attachments = json_decode( $_POST['wpcf7-pdf-forms-attachments'] );
			$old_attachments = $this->post_get_all_pdfs( $post_id );
			
			if( is_array( $new_attachments ) )
			{
				foreach( $new_attachments as &$aid )
					$attachment_id = intval( $aid );
					
				foreach( $new_attachments as $attachment_id )
					if( $attachment_id > 0 )
						if( current_user_can( 'edit_post', $attachment_id ) )
							if( ! in_array( $attachment_id, $old_attachments ) )
								$this->post_add_pdf( $post_id, $attachment_id );
				
				foreach( $old_attachments as $attachment_id )
				{
					if( ! in_array( $attachment_id, $new_attachments ) )
						$this->post_del_pdf( $post_id, $attachment_id );
				}
			}
		}
		
		/**
		 * Creates a temporary file path (but not the file itself)
		 */
		private static function create_wpcf7_tmp_filepath( $filename )
		{
			static $uploads_dir;
			if( ! $uploads_dir )
			{
				wpcf7_init_uploads(); // Confirm upload dir
				$uploads_dir = wpcf7_upload_tmp_dir();
				$uploads_dir = wpcf7_maybe_add_random_dir( $uploads_dir );
			}
			$filename = sanitize_file_name( wpcf7_canonicalize( $filename ) );
			$filename = wp_unique_filename( $uploads_dir, $filename );
			return trailingslashit( $uploads_dir ) . $filename;
		}
		
		/**
		 * When form data is posted, this function communicates with the API
		 * to fill the form data and get the PDF file with filled form fields
		 * 
		 * Files created and attached in this function will be deleted
		 * automatically by CF7 after it sends the email message
		 */
		public function fill_and_attach_pdfs( $contact_form )
		{
			$post_id = $contact_form->id();
			
			$submission = WPCF7_Submission::get_instance();
			
			$files = array();
			foreach( $this->post_get_all_pdfs( $post_id ) as $attachment_id )
			{
				$filepath = get_attached_file( $attachment_id );
				$destfile = self::create_wpcf7_tmp_filepath( basename( $filepath ) );
				$data = array();
				$posted_data = $submission->get_posted_data();
				foreach( $posted_data as $key => $value )
				{
					$field = self::wpcf7_field_name_decode( $attachment_id, $key );
					if( $field !== FALSE )
					{
						if( is_array( $value ) )
							$value = array_shift( $value );
						$data[$field] = $value;
					}
				}
				
				try
				{
					$service = $this->get_service();
					$filled = false;
					if( $service && count( $data ) > 0 )
						$filled = $service->api_fill( $destfile, $attachment_id, $data );
					if( ! $filled )
						copy( $filepath, $destfile );
					$files[] = $destfile;
				}
				catch(Exception $e)
				{
					if( ! file_exists( $destfile ) )
						copy( $filepath, $destfile );
					$files[] = $destfile;
					$destfile = self::create_wpcf7_tmp_filepath( basename( basename( $filepath . ".txt" ) ) );
					$text = "Error generating PDF: " . $e->getMessage() . "\n"
					      . "\n"
					      . "Form data:\n"
					      . "\n";
					foreach( $data as $field => $value )
						$text .= "$field: $value\n";
					file_put_contents( $destfile, $text );
					$files[] = $destfile;
				}
			}
			
			if( count( $files ) > 0 )
			{
				$mail = $contact_form->prop( "mail" );
				foreach( $files as $id => $file )
					if( file_exists( $file ) )
					{
						$submission->add_uploaded_file( "wpcf7-pdf-forms-$id", $file );
						$mail["attachments"] .= "[wpcf7-pdf-forms-$id]";
					}
				$contact_form->set_properties( array( "mail" => $mail ) );
			}
		}
		
		/**
		 * Used for uploading a pdf file to the server in wp-admin interface
		 */
		public function wp_ajax_upload()
		{
			try
			{
				if ( ! check_ajax_referer( 'wpcf7-pdf-forms-ajax-nonce', 'nonce', false ) )
					throw new Exception( __( "Nonce mismatch", 'wpcf7-pdf-forms' ) );
				
				if ( ! current_user_can( 'upload_files' ) )
					throw new Exception( __( "Permission denied", 'wpcf7-pdf-forms' ) );
				
				$file = $_FILES[ 'file' ];
				if( $file )
				{
					// TODO: check type of contents of the file instead of just extension
					if( wp_check_filetype( $file['name'] )['type'] !== 'application/pdf' )
						throw new Exception( __( "Invalid file mime type, must be 'application/pdf'", 'wpcf7-pdf-forms' ) );
					
					$overrides = array(
						'mimes'  => array( 'pdf' => 'application/pdf' ),
						'ext'    => array( 'pdf' ),
						'type'   => true,
						'action' => 'wpcf7_pdf_forms_upload',
					);
					
					$attachment_id = media_handle_upload( 'file', 0, array(), $overrides );
					
					if( is_wp_error( $attachment_id ) )
						throw new Exception( $attachment_id->errors['upload_error']['0'] );
					
					return wp_send_json( array(
						'success' => true,
						'attachment_id' => $attachment_id,
						'filename' => basename( get_attached_file( $attachment_id ) ),
					) );
				}
			}
			catch( Exception $e )
			{
				return wp_send_json( array(
					'success'  => false,
					'error_message' => $e->getMessage(),
				) );
			}
		}
		
		/**
		 * Used for generating tags in wp-admin interface
		 */
		public function wp_ajax_query_fields()
		{
			try
			{
				if ( ! check_ajax_referer( 'wpcf7-pdf-forms-ajax-nonce', 'nonce', false ) )
					throw new Exception( __( "Nonce mismatch", 'wpcf7-pdf-forms' ) );
				
				$attachment_id = isset( $_GET['attachment_id'] ) ? (int) $_GET['attachment_id'] : null;
				
				if( ! $attachment_id )
					throw new Exception( __( "Invalid attachment ID", 'wpcf7-pdf-forms' ) );
				
				if ( ! current_user_can( 'edit_post', $attachment_id ) )
					throw new Exception( __( "Permission denied", 'wpcf7-pdf-forms' ) );
				
				$service = $this->get_service();
				if( $service )
					$fields = $service->api_get_fields( $attachment_id );
				
				$tags = "";
				if( is_array( $fields ) )
				{
					if( count($fields) == 0 )
						$tags = __( "This PDF file does not appear to contain a PDF form.  See https://acrobat.adobe.com/us/en/acrobat/how-to/create-fillable-pdf-forms-creator.html for more information.", 'wpcf7-pdf-forms' );
					else
						foreach ( $fields as &$field )
						{
							if( isset( $field['type'] ) )
							{
								$type = $field['type'];
								$name = $field['name'];
								
								$tag = '<label>' . $name . '</label>' . "\n";
								
								if( $type == 'text' )
								{
									$tag .= '    [' . $field['type'] . ' ' . self::wpcf7_field_name_encode( $attachment_id, $field['name'] ) . ' ]';
								}
								else if( $type == 'radio' || $type == 'select' )
								{
									if( ! isset( $field['options'] ) )
										continue;
									
									$options = $field['options'];
									
									if( ( $off_key = array_search( 'Off', $options ) ) !== FALSE )
										unset( $options[ $off_key ] );
									
									if( count( $options ) == 1 )
										$type = 'checkbox';
									
									$tag .= '    [' . $type . ' ' . self::wpcf7_field_name_encode( $attachment_id, $name ) . ' ';
									foreach( $options as &$option )
										$tag .= '"' . $option . '" ';
									$tag .= ']';
								}
								else
									continue;
								
								$tags .= $tag . "\n\n";
							}
						}
				}
				return wp_send_json( array(
					'success' => true,
					'tags' => $tags,
				) );
			}
			catch( Exception $e )
			{
				return wp_send_json( array(
					'success'  => false,
					'error_message' => $e->getMessage(),
				) );
			}
		}
		
		/**
		 * Used for getting a list of attachments in wp-admin interface
		 */
		public function wp_ajax_query_attachments()
		{
			try
			{
				if ( ! check_ajax_referer( 'wpcf7-pdf-forms-ajax-nonce', 'nonce', false ) )
					throw new Exception( __( "Nonce mismatch", 'wpcf7-pdf-forms' ) );
				
				$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : null;
				
				if( ! $post_id )
					throw new Exception( __( "Invalid post ID", 'wpcf7-pdf-forms' ) );
				
				if ( ! current_user_can( 'wpcf7_edit_contact_form', $post_id ) )
					throw new Exception( __( "Permission denied", 'wpcf7-pdf-forms' ) );
				
				$attachments = array();
				foreach( $this->post_get_all_pdfs( $post_id ) as $attachment_id )
					$attachments[] = array(
						'attachment_id' => $attachment_id,
						'filename' => basename( get_attached_file( $attachment_id ) ),
					);
				
				return wp_send_json( array(
					'success' => true,
					'attachments' => $attachments,
				) );
			}
			catch( Exception $e )
			{
				return wp_send_json( array(
					'success'  => false,
					'error_message' => $e->getMessage(),
				) );
			}
		}
		
		/**
		 * Adds a tag to the form editor
		 */
		public function extend_tag_generator()
		{
			if( class_exists('WPCF7_TagGenerator') )
			{
				$tag_generator = WPCF7_TagGenerator::get_instance();
				$tag_generator->add(
					'pdf_form',
					__( 'PDF Form', 'wpcf7-pdf-forms' ),
					array( $this, 'render_tag_generator')
				);
			}
			// support for older CF7 versions
			else if( function_exists('wpcf7_add_tag_generator') )
			{
				wpcf7_add_tag_generator(
					'pdf_form',
					__( 'PDF Form', 'wpcf7-pdf-forms' ),
					'wpcf7-tg-pane-pdfninja',
					array( $this, 'render_tag_generator')
				);
			}
		}
		
		/**
		 * Takes html template from the html folder and renders it with the given attributes
		 */
		public static function render( $template, $attributes = array() )
		{
			return self::render_file( plugin_dir_path(__FILE__) . 'html/' . $template . '.html', $attributes );
		}
		
		/**
		 * Takes html template file and renders it with the given attributes
		 */
		public static function render_file( $template_filepath, $attributes = array() )
		{
			return str_replace(
				array_map( function( $a ) { return '{'.$a.'}'; }, array_keys( $attributes ) ),
				array_values( $attributes ),
				file_get_contents( $template_filepath )
			);
		}
		
		/**
		 * Renders the contents of a thickbox that comes up when user clicks the tag in the form editor
		 */
		public function render_tag_generator( $contact_form, $args = '' )
		{
			$args = wp_parse_args( $args, array() );
			if( class_exists('WPCF7_TagGenerator') )
				echo self::render( 'add_pdf', array(
					'post-id' => esc_html( $contact_form->id() ),
					'instructions' => esc_html__( "Attach a PDF file to your form and insert tags into your form that map to fields in the PDF file.", 'wpcf7-pdf-forms' ),
					'upload-button-label' => esc_html__( "Upload & Attach a PDF File", 'wpcf7-pdf-forms' ),
					'insert-button-label' => esc_html__( "Insert Tags", 'wpcf7-pdf-forms' ),
				) );
			// support for older CF7 versions
			else
				echo self::render( 'add_pdf_unsupported', array(
					'unsupported-message' => esc_html__( 'Your CF7 plugin is too out of date, please upgrade.', 'wpcf7-pdf-forms' ),
				) );
		}
		
		/**
		 * Helper functions that are used to convert between contact form field names and PDF form field names
		 */
		public static function base64url_encode( $data )
		{
			return rtrim( strtr( base64_encode( $data ), '+/', '._' ), '=' );
		}
		public static function base64url_decode( $data )
		{
			return base64_decode( str_pad(strtr( $data, '._', '+/' ), strlen( $data ) % 4, '=', STR_PAD_RIGHT ) );
		}
		public static function wpcf7_field_name_encode( $attachment_id, $pdf_field_name )
		{
			$slug = sanitize_title( $pdf_field_name );
			return "pdf-field-" . $attachment_id . "-" . $slug . "-" . self::base64url_encode( $pdf_field_name );
		}
		public static function wpcf7_field_name_decode( $attachment_id, $wpcf7_field_name )
		{
			$flag = "pdf-field-" . $attachment_id . "-";
			if( substr( $wpcf7_field_name, 0, strlen( $flag ) ) !== $flag )
				return FALSE;
			
			$str = strrchr( $wpcf7_field_name, '-' );
			if( $str == FALSE )
				return FALSE;
			
			$base64encoded_field_name = substr( $str, 1 );
			return self::base64url_decode( $base64encoded_field_name );
		}
	}
	
	WPCF7_Pdf_Forms::get_instance();
}
