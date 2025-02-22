<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

if(file_exists(PUSH_NOTIFICATION_PLUGIN_DIR."inc/admin/class-function.php")){
	require_once PUSH_NOTIFICATION_PLUGIN_DIR."inc/admin/class-function.php";
}
class Push_Notification_Admin{

	public function __construct(){}

	public function init(){
		add_action('admin_notices', array($this, 'admin_notices_opt') );
		add_action( 'admin_menu', array( $this, 'add_menu_links') );
		add_action( 'admin_init', array( $this, 'settings_init') );
		add_action( 'admin_enqueue_scripts', array( $this, 'load_admin_scripts' ) );
		add_action( 'wp_ajax_pn_verify_user', array( $this, 'pn_verify_user' ) ); 
		add_action( 'wp_ajax_pn_refresh_user', array( $this, 'pn_refresh_api_key' ) ); 
		add_action( 'wp_ajax_pn_revoke_keys', array( $this, 'pn_revoke_keys' ) ); 
		add_action( 'wp_ajax_pn_subscribers_data', array( $this, 'pn_subscribers_data' ) ); 
		add_action( 'wp_ajax_pn_send_notification', array( $this, 'pn_send_notification' ) ); 		
		add_action('wp_ajax_pn_send_query_message', 'pn_send_query_message');
		add_action('wp_ajax_pn_get_compaigns', array( $this, 'pn_get_compaigns' ));
		add_action( 'wp_ajax_pn_delete_campaign', array( $this, 'pn_delete_campaigns' ) ); 
		add_action('wp_ajax_pn_subscribe_newsletter',array( $this, 'pn_subscribe_newsletter' ) );
		//on oreder status change
		add_action('woocommerce_order_status_changed', array( $this, 'pn_order_send_notification'), 10, 4);


		/** pwaforwp installed than work with that */
		if( function_exists('pwaforwp_init_plugin') ){
			/** service worker change */
			$settings = pwaforwp_defaultSettings();
			$auth_settings = push_notification_auth_settings();
			if($settings['notification_feature']==1 && $settings['notification_options']=='pushnotifications_io' 
				//For Auth settings check
				&& isset($auth_settings['user_token']) && isset($auth_settings['token_details']['validated']) && $auth_settings['token_details']['validated'] == 1){
				add_filter( "pwaforwp_sw_js_template", array($this, 'add_sw_js_content') , 10, 1);
				add_filter( "pwaforwp_pn_config", array($this, 'add_pn_config') , 10, 1);
				add_filter( "pwaforwp_pn_use_sw", array($this, 'add_pn_use_sw') , 10, 1);
				add_filter( "pwaforwp_sw_register_template", array($this, 'add_sw_register_template') , 10, 1);
			}
		}
	}
	/*
	* This function will Messaging functions in service worker
	* 
	*/
	function add_sw_js_content($swJsContent){
        	$messageSw = $this->pn_get_layout_files('messaging-sw.js');
			$settings = $this->json_settings();
			$messageSw = str_replace('{{pnScriptSetting}}', wp_json_encode($settings), $messageSw);
			$swJsContent .= $messageSw;

		return $swJsContent;
	}

	function add_pn_config($firebaseconfig){
    	$firebaseconfig   = 'var config = pnScriptSetting.pn_config;'
                            .'if (!firebase.apps.length) {firebase.initializeApp(config);}  
                            if(!messaging){const messaging = firebase.messaging();}';
    	return $firebaseconfig;
  	}

	function add_pn_use_sw($useserviceworker){
		 $useserviceworker = 'messaging.useServiceWorker(reg); pushnotification_load_messaging();';
		return $useserviceworker;
	}

	function add_sw_register_template($swHtmlContent){
		//its similar to app.js, not contain sercive worker installation
		$sw_registerContent = $this->pn_get_layout_files('public/app-pwaforwp.js');
		//Concatnate content in main service worker register
		$swHtmlContent .= $sw_registerContent;
		return $swHtmlContent;
	}

	public function json_settings(){
		if ( is_multisite() ) {
            $link = get_site_url();              
        }
        else {
            $link = home_url();
        }    
        $auth_settings = push_notification_auth_settings();
        $messageConfig = '';
        if(isset($auth_settings['user_token']) && isset($auth_settings['token_details']['validated']) && $auth_settings['token_details']['validated'] == 1){
        	$messageConfig = json_decode($auth_settings['messageManager'], true);
        }
        $settings = array(
					'nonce' =>  wp_create_nonce("pn_notification"),
					'pn_config'=> $messageConfig,
					"swsource" => esc_url_raw(trailingslashit($link)."?push_notification_sw=1"),
					"scope" => esc_url_raw(trailingslashit($link)),
					"ajax_url"=> esc_url_raw(admin_url('admin-ajax.php'))
					);
        return $settings;
	}

	function load_admin_scripts($hook_suffix){

		if( $hook_suffix=='toplevel_page_push-notification' ) {

			$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '';	

			wp_enqueue_media();						
			wp_enqueue_style( 'wp-color-picker' );
   		 	wp_enqueue_script( 'push_notification_script',  PUSH_NOTIFICATION_PLUGIN_URL."assets/main-admin-script{$min}.js", array( 'wp-color-picker' ), PUSH_NOTIFICATION_PLUGIN_VERSION, true );
			wp_enqueue_script('push_notification_script', PUSH_NOTIFICATION_PLUGIN_URL."assets/main-admin-script{$min}.js", array('jquery'), PUSH_NOTIFICATION_PLUGIN_VERSION, true);
			wp_enqueue_style('push-notification-style', PUSH_NOTIFICATION_PLUGIN_URL."assets/main-admin-style{$min}.css", array('dashboard'), PUSH_NOTIFICATION_PLUGIN_VERSION, 'all');
			wp_enqueue_style('push-notification_select2', PUSH_NOTIFICATION_PLUGIN_URL.'assets/select2.min.css', array('dashboard'), PUSH_NOTIFICATION_PLUGIN_VERSION, 'all' );
			wp_enqueue_script('push_notification_select2', PUSH_NOTIFICATION_PLUGIN_URL.'assets/select2.min.js', array(),PUSH_NOTIFICATION_PLUGIN_VERSION, true );
			wp_enqueue_script('select2-extended-script', PUSH_NOTIFICATION_PLUGIN_URL. 'assets/select2-extended.min.js', array( 'jquery' ), PUSH_NOTIFICATION_PLUGIN_VERSION, true);

			wp_enqueue_script('pn-meteremoji', PUSH_NOTIFICATION_PLUGIN_URL."assets/meterEmoji.min.js", array('jquery'), PUSH_NOTIFICATION_PLUGIN_VERSION, true);
			wp_enqueue_script('pn-emoji-custom', PUSH_NOTIFICATION_PLUGIN_URL."assets/emoji-custom.js", array('jquery'), PUSH_NOTIFICATION_PLUGIN_VERSION, true);
							
			if ( is_multisite() ) {
	            $link = get_site_url();              
	        }
	        else {
	            $link = home_url();
	        }    
	        $object = array(
							"home_url"			=> esc_url_raw($link),
							"ajax_url"			=> esc_url_raw(admin_url('admin-ajax.php')),
							"remote_nonce"		=> wp_create_nonce("pn_notification"),
							'uploader_title'    => esc_html__('Application Icon', 'push-notification'),
            				'uploader_button'   => esc_html__('Select Icon', 'push-notification'),
						);

	        $object = apply_filters('pushnotification_localize_filter',$object, 'pn_setings');
			wp_localize_script("push_notification_script", 'pn_setings', $object);
						
		}
	}

	public function add_menu_links(){
		// Main menu page
		add_menu_page( esc_html__( 'Push Notification', 'push-notification' ), 
	                esc_html__( 'Push Notifications', 'push-notification' ), 
	                'manage_options',
	                'push-notification',
	                array($this, 'admin_interface_render'),
	                '', 100 );
		
		// Settings page - Same as main menu page
		add_submenu_page( 'push-notification',
	                esc_html__( 'Push Notifications Options', 'push-notification' ),
	                esc_html__( 'Settings', 'push-notification' ),
	                'manage_options',
	                'push-notification',
	                array($this, 'admin_interface_render')
	            );

			if(PN_Server_Request::getProStatus() != 'active'){
				global $submenu;
				$permalink = 'javasctipt:void(0);';
				$submenu['push-notification'][] = array( '<div style="color:#fff176;" onclick="window.open(\'https://pushnotifications.io/pricing\')">'.esc_html__( 'Upgrade To Premium', 'push-notification' ).'</div>', 'manage_options', $permalink);
			}
	}
	function admin_interface_render(){
		// Authentication
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?><div class="wrap push_notification-settings-wrap">
			<h1 class="page-title"><?php echo esc_html__('Push Notifications Options', 'push-notification'); ?></h1>
			<div class="push-notification-main-wrapper">
				<h2 class="nav-tab-wrapper push-notification-tabs">
					<?php
					$authData = push_notification_auth_settings();
					if( (isset($authData['token_details']) && $authData['token_details']['validated']==1) ){
						$plugin_icon_color = "#008416;";
					}else{
						$plugin_icon_color = "#000;";
					}
					if(isset($authData['token_details']['validated']) && $authData['token_details']['validated']==1){
						echo '<a href="' . esc_url('#pn_connect') . '" link="pn_connect" class="nav-tab nav-tab-active"><span class="dashicons dashicons-admin-plugins" style="color:'.esc_attr( $plugin_icon_color ).'"></span> ' . esc_html__('Connect','push-notification') . '</a>';
						echo '<a href="' . esc_url('#pn_dashboard') . '" link="pn_dashboard" class="nav-tab"><span class="dashicons dashicons-dashboard"></span> ' . esc_html__('Dashboard','push-notification') . '</a>';
						echo '<a href="' . esc_url('#pn_notification_bell') . '" link="pn_notification_bell" class="nav-tab js_notification"><span class="dashicons dashicons-bell"></span> ' . esc_html__('Notification','push-notification') . '</a>';
						if( !empty($authData['token_details']) && !empty($authData['token_details']['user_pro_status']) ){
							if( (isset($authData['token_details']) && $authData['token_details']['user_pro_status']=='active') ){
								echo '<a href="' . esc_url('#pn_segmentation') . '" link="pn_segmentation" class="nav-tab"><span class="dashicons dashicons-admin-generic"></span> ' . esc_html__('Segmentation','push-notification') . '</a>';
							}
						}
						echo '<a href="' . esc_url('#pn_campaings') . '" link="pn_campaings" class="nav-tab"><span class="dashicons dashicons-megaphone"></span> ' . esc_html__('Campaigns','push-notification') . '</a>';
						echo '<a href="' . esc_url('#pn_help') . '" link="pn_help" class="nav-tab"><span class="dashicons dashicons-editor-help"></span> ' . esc_html__('Help','push-notification') . '</a>';
					}
					?>
				</h2>
			</div>

				<form action="options.php" method="post" enctype="multipart/form-data" class="push_notification-settings-form">		
					<div class="form-wrap">
						<?php
						settings_fields( 'push_notification_setting_dashboard_group' );
						echo "<div class='push_notification-dashboard'>";
							// Status
							echo "<div id='pn_connect' class='pn-tabs'>";
								do_settings_sections( 'push_notification_dashboard_section' );	// Page slug
							echo "</div>";
							if(isset($authData['token_details']['validated']) && $authData['token_details']['validated']==1){
								$this->shownotificationData();
							}
						echo "</div>";
						?>
					</div>
				</form>
		</div><?php
	} 

	public function settings_init(){
		register_setting( 'push_notification_setting_dashboard_group', 'push_notification_settings' );

		add_settings_section('push_notification_dashboard_section',
					 ' ', 
					 '__return_false', 
					 'push_notification_dashboard_section');

			add_settings_field(
				'pn_key_validate_status',	// ID
				esc_html__('API', 'push-notification'),			// Title
				array( $this, 'pn_key_validate_status_callback'),// Callback
				'push_notification_dashboard_section',	// Page slug
				'push_notification_dashboard_section'	// Settings Section ID
			);
		add_settings_section('push_notification_segment_settings_section',
					 esc_html__('Notification Segment','push-notification'), 
					 '__return_false', 
					 'push_notification_segment_settings_section');
			add_settings_field(
				'pn_key_segment_select',								// ID
				'<label for="pn_push_on_category_checkbox"><b>'.esc_html__('Segmentation', 'push-notification').'</b></label>',// Title
				array( $this, 'pn_key_segment_select_callback'),// Callback
				'push_notification_segment_settings_section',	// Page slug
				'push_notification_segment_settings_section'	// Settings Section ID
			);
			$notification = push_notification_settings();
			$s_display="style='display:none;'";
			if(isset($notification['on_category']) && $notification['on_category']){
				$s_display="style='display:block;'";
			}
			add_settings_field(
				'pn_key_segment_on_categories',								// ID
				'<label class="js_category_selector_wrapper" for="pn_push_segment_on_category_checkbox" '.$s_display.'><b>'.esc_html__('On All Categories', 'push-notification').'</b></label>',// Title
				array( $this, 'pn_key_segment_on_categories_callback'),// Callback
				'push_notification_segment_settings_section',	// Page slug
				'push_notification_segment_settings_section'	// Settings Section ID
			);

			$soc_display="style='display:none;'";
			if(isset($notification['on_category']) && $notification['on_category']){
				$soc_display="style='display:block;'";
			}
			add_settings_field(
				'pn_select_custom_categories',								// ID
				'<label class="js_custom_category_selector_wrapper" for="pn_select_custom_categories" '.$soc_display.'><b>'.esc_html__('On Selected Categories', 'push-notification').'</b></label>',// Title
				array( $this, 'pn_select_specific_categories_callback'),// Callback
				'push_notification_segment_settings_section',	// Page slug
				'push_notification_segment_settings_section'	// Settings Section ID
			);

		add_settings_section('push_notification_user_settings_section',
					 ' ', 
					 '__return_false', 
					 'push_notification_user_settings_section');
            if (!function_exists('pwaforwp_defaultSettings')) {
                add_settings_field(
					'pn_key__notification_icon_edit',					// ID
					esc_html__('Notification Icon URL', 'push-notification'),// Title
					array( $this, 'user_settings_notification_icon_callback'),// Callback
					'push_notification_user_settings_section',	// Page slug
					'push_notification_user_settings_section'	// Settings Section ID
				);
            }
			
			add_settings_field(
				'pn_key_sendpush_publish',								// ID
				'<label for="pn_push_on_publish"><b>'.esc_html__('Send Notification on Publish', 'push-notification').'</b></label>',
				array( $this, 'user_settings_onpublish_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_user_settings_section'	// Settings Section ID
			);
			add_settings_field(
				'pn_key_posttype_select',								// ID
				esc_html__('Send Notification On', 'push-notification'),// Title
				array( $this, 'pn_key_posttype_select_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_user_settings_section'	// Settings Section ID
			);

		add_settings_section('push_notification_utm_tracking_settings_section',
					 esc_html__('UTM tracking','push-notification'), 
					 '__return_false', 
					 'push_notification_user_settings_section');
			add_settings_field(
				'pn_utm_tracking_select',								// ID
				'<label for="utm_tracking_checkbox"><b>'.esc_html__('Enable', 'push-notification').'</b></label>',
				array( $this, 'pn_utm_tracking_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_utm_tracking_settings_section'	// Settings Section ID
			);	
			add_settings_section('push_notification_url_capturing_settings_section',
					 esc_html__('URL capturing','push-notification'), 
					 '__return_false', 
					 'push_notification_user_settings_section');
			add_settings_field(
			'pn_key_url_capture_select',								// ID
			esc_html__('Save page url on subscribe', 'push-notification'),// Title
			array( $this, 'pn_key_url_capture_select_callback'),// Callback
			'push_notification_user_settings_section',	// Page slug
			'push_notification_url_capturing_settings_section'	// Settings Section ID
		);			

		add_settings_field(
			'pn_key_url_capture_manual',								// ID
			esc_html__('URL where capture will work', 'push-notification'),// Title
			array( $this, 'pn_key_url_manual_capture_callback'),// Callback
			'push_notification_user_settings_section',	// Page slug
			'push_notification_url_capturing_settings_section'	// Settings Section ID
		);	

		

		add_settings_section('push_notification_notification_settings_section',
					 esc_html__('Notification Subscription Popup','push-notification'), 
					 '__return_false', 
					 'push_notification_user_settings_section');
			add_settings_field(
				'pn_key_message_position_select',								// ID
				esc_html__('Where would you like to display Pop Up', 'push-notification'),// Title
				array( $this, 'pn_key_position_select_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_notification_settings_section'	// Settings Section ID
			);						
			add_settings_field(
				'pn_key_popup_message_select',								// ID
				esc_html__('Popup Banner Message', 'push-notification'),// Title
				array( $this, 'pn_key_banner_message_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_notification_settings_section'	// Settings Section ID
			);
			add_settings_field(
				'pn_key_popup_accept_btn',								// ID
				esc_html__('Popup Banner Accept', 'push-notification'),// Title
				array( $this, 'pn_key_banner_accept_btn_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_notification_settings_section'	// Settings Section ID
			);
			add_settings_field(
				'pn_key_popup_decline_btn',								// ID
				esc_html__('Popup Banner Decline', 'push-notification'),// Title
				array( $this, 'pn_key_banner_decline_btn_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_notification_settings_section'	// Settings Section ID
			);
			add_settings_field(
				'pn_key_show_again_on',								// ID
				esc_html__('Popup Show Again', 'push-notification'),// Title
				array( $this, 'pn_key_popupshowagain_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_notification_settings_section'	// Settings Section ID
			);
			add_settings_field(
				'pn_key_prompt_after_nseconds',								// ID
				esc_html__('Popup show after n seconds', 'push-notification'),// Title
				array( $this, 'pn_key_popupshowafternseconds_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_notification_settings_section'	// Settings Section ID
			);
			add_settings_field(
				'pn_key_prompt_after_npageview',								// ID
				esc_html__('Popup show after n pages view', 'push-notification'),// Title
				array( $this, 'pn_key_popupshowafternpageview_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_notification_settings_section'	// Settings Section ID
			);
			if(PN_Server_Request::getProStatus()=='active'){
			add_settings_field(
				'pn_key_actions_buttons_position',								// ID
				esc_html__('Where would you like to display Buttons', 'push-notification'),// Title
				array( $this, 'pn_key_actions_buttons_position_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_notification_settings_section'	// Settings Section ID
			);
			add_settings_field(
				'pn_key__notification_pop_up_icon_edit',					// ID
				esc_html__('Pop Up icon', 'push-notification'),// Title
				array( $this, 'user_settings_notification_pop_up_icon_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_notification_settings_section'	// Settings Section ID
			);
		}
			if(PN_Server_Request::getProStatus()=='active'){
			add_settings_section('push_notification_notification_pop_display_settings_section',
					 esc_html__('Notification Subscription Popup Appearance','push-notification'), 
					 '__return_false', 
					 'push_notification_user_settings_section');
			add_settings_field(
				'pn_key_popup_display_setings_title_color',								// ID
				esc_html__('Popup Banner Title Color', 'push-notification'),// Title
				array( $this, 'pn_key_popup_display_settings_title_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_notification_pop_display_settings_section'	// Settings Section ID
			);
			add_settings_field(
				'pn_key_popup_display_setings_ok_color',								// ID
				esc_html__('Popup Banner Accept Color', 'push-notification'),// Title
				array( $this, 'pn_key_popup_display_settings_ok_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_notification_pop_display_settings_section'	// Settings Section ID
			);
			add_settings_field(
				'pn_key_popup_display_setings_no_thanks_color',								// ID
				esc_html__('Popup Banner Decline Color', 'push-notification'),// Title
				array( $this, 'pn_key_popup_display_settings_no_thanks_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_notification_pop_display_settings_section'	// Settings Section ID
			);
			add_settings_field(
				'pn_key_popup_display_setings_text_color',								// ID
				esc_html__(' Popup Text Color', 'push-notification'),// Title
				array( $this, 'pn_key_popup_display_settings_text_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_notification_pop_display_settings_section'	// Settings Section ID
			);
			add_settings_field(
				'pn_key_popup_display_setings_bg_color',								// ID
				esc_html__(' Popup Background Color', 'push-notification'),// Title
				array( $this, 'pn_key_popup_display_settings_bg_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_notification_pop_display_settings_section'	// Settings Section ID
			);			

			add_settings_field(
				'pn_key_popup_display_setings_border_radius',								// ID
				esc_html__(' Popup Border Radius', 'push-notification'),// Title
				array( $this, 'pn_key_popup_display_setings_border_radius_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_notification_pop_display_settings_section'	// Settings Section ID
			);
		}
			
			if( class_exists( 'WooCommerce', false )){
		//WC compatiblility
			add_settings_section('push_notification_user_wc_settings_section',
					 esc_html__('WooCommerce settings','push-notification'), 
					 '__return_false', 
					 'push_notification_user_settings_section');
            add_settings_field(
				'pn_wc_notification_orderchgn_edit',					// ID
				esc_html__('Notification on order change', 'push-notification'),// Title
				array( $this, 'user_notification_order_change_callback'),// Callback
				'push_notification_user_settings_section',	// Page slug
				'push_notification_user_wc_settings_section'	// Settings Section ID
			); 
		}
	}

	function mobile_notification_preview(){
		echo '<div class="pn-iphone">
        			<div class="pn-notch"></div>
					<div class="pn-screen">
						
						<div class="pn-notification-container">
							<div class="pn-web-container">
								
								<span class="pn-bell-icon"></span>
								<div class="pn-web-name">pushnotifications.io</div>
								<span class="dashicons dashicons-arrow-up-alt2 pn-arrow_icon"></span>
							</div>
							
							<div class="pn-notification-header">
								<div class="pn-notification-content">
									<div class="pn-notification-title">'.esc_html__('Notification Title','push-notification').'</div>
									<div class="pn-notification-description">'.esc_html__('This is the content of the push notification. You can customize it based on your needs','push-notification').'.</div>
								</div>
								<div class="pn-image-icon js_all" id="js_pn_icon"></div>
							</div>
							<div class="pn-notification-banner js_all" id="js_pn_banner"></div>
						</div>
					</div>
    			</div>';
	}

	function shownotificationData(){
		$auth_settings = push_notification_auth_settings();
		$detail_settings = push_notification_details_settings();
		$notification_settings = push_notification_settings();
		$campaigns = [];
		if( !$detail_settings && isset( $auth_settings['user_token'] ) ){
			 PN_Server_Request::getsubscribersData( $auth_settings['user_token'] );
			 $detail_settings = push_notification_details_settings();
		}
		if(isset( $auth_settings['user_token'] ) && !empty($auth_settings['user_token']) ){
			$campaigns = PN_Server_Request::getCompaignsData( $auth_settings['user_token'] );
		}

		$updated_at = '';
		if(isset($detail_settings['updated_at'])){
			$updated_at = human_time_diff( strtotime( $detail_settings['updated_at'] ), strtotime( gmdate('Y-m-d H:i:s') ) );
			if(!empty( $updated_at ) ){
				$updated_at .= " ago";
			}

		}
		$subscriber_count = 0;
		$active_count = 0;
		$expired_count = 0;
		if(isset($detail_settings['subscriber_count'])){ $subscriber_count = $detail_settings['subscriber_count']; }
		if(isset($detail_settings['active_count'])){ $active_count = $detail_settings['active_count']; }
		if(isset($detail_settings['expired_count'])){ $expired_count = $detail_settings['expired_count']; }
		
		echo '<div id="pn_dashboard" style="display:none;" class="pn-tabs">
		<section class="pn_general_wrapper">
				<div class="action-wrapper"><span style="line-height: 1.4;"> '.esc_html($updated_at).'</span> <button type="button" class="button" id="grab-subscribers-data" class="dashicons dashicons-update" >'.esc_html__('Refresh data', 'push-notification').'</button>
				</div>
				<div class="pn-content">
					<div class="pn-card-wrapper">
						<div class="pn-card">
							<div class="title-name">'.esc_html__('Total Subscribers', 'push-notification').':</div>
							<div class="desc column-description">
								'.esc_html($subscriber_count).'
							</div>
						</div>
						<div class="pn-card" style="display:none;">
							<div class="title-name">'.esc_html__('Active Subscribers', 'push-notification').':</div>
							<div class="desc column-description" style="color:green;">
								'.esc_html($active_count).'
							</div>
						</div>
						<div class="pn-card" style="display:none;">
							<div class="title-name">'.esc_html__('Expired Subscribers', 'push-notification').':</div>
							<div class="desc column-description" style="color:red;">
								'.esc_html($expired_count).'
							</div>
						</div>
					</div>
				</div>
				';
				do_settings_sections( 'push_notification_user_settings_section' );
		echo   '<input type="submit" value="'.esc_html__('Save Settings', 'push-notification').'" class="button pn-submit-button">
			</section>
			</div>
			';

		echo '<div id="pn_segmentation" style="display:none" class="pn-tabs">
		<section class="pn_general_wrapper">';
				do_settings_sections( 'push_notification_segment_settings_section' );
		echo   '<input type="submit" value="'.esc_html__('Save Settings', 'push-notification').'" class="button pn-submit-button">
			</section>
			</div>
			';
		if ( class_exists( 'WooCommerce' ) ) {
			echo '<div id="pn_wc_settings_section" style="display:none;" class="pn-tabs">
				<section style="margin-top:20px"><div class="postbox" style="padding:20px">';
					do_settings_sections( 'push_notification_user_wc_settings_section' );
					echo   '<input type="submit" value="'.esc_html__('Save Settings', 'push-notification').'" class="button pn-submit-button">';
				echo '</div></section>
			</div>';
		}
		echo '<br/><br/>
			<div id="pn_notification_bell" class="pn-other-settings-options pn-tabs" style="display:none">
					<div id="dashboard_right_now" class="postbox" >
						<h2 class="pn_select_design" style="margin-top:6px;">'.esc_html__('Select push notification design', 'push-notification').'</h2>
						<div class="pn-image-container">
							<img src="'.esc_url( PUSH_NOTIFICATION_PLUGIN_URL ).'assets/image/m.png" alt="Image 1" class="pn-clickable-image pn-clickable-image-selected" notification_type="message">
							<img src="'.esc_url( PUSH_NOTIFICATION_PLUGIN_URL ).'assets/image/m_i.png" alt="Image 2" class="pn-clickable-image" notification_type="message-with-icon">
							<img src="'.esc_url( PUSH_NOTIFICATION_PLUGIN_URL ).'assets/image/m_b.png" alt="Image 3" class="pn-clickable-image" notification_type="message-with-banner">
							<img src="'.esc_url( PUSH_NOTIFICATION_PLUGIN_URL ).'assets/image/m_i_b.png" alt="Image 1" class="pn-clickable-image" notification_type="message-with-icon-and-banner">
						</div>
						<div class="inside device-container-test">
						<div class="pn-form-part">
							<div class="main">';
							do_action('push_notification_pro_notifyform_before');
							echo '<div class="form-group">
									<label for="notification-title">'.esc_html__('Title','push-notification').'</label>
									<input type="text" id="notification-title" class="regular-text js_pn_custom" data-meteor-emoji="true">
								</div>
								<div class="form-group">
									<label for="notification-link">'.esc_html__('Link', 'push-notification').'</label>
									<input type="text" id="notification-link" class="regular-text js_pn_custom">
								</div>
								<input type="hidden" id="js_notification_icon" notification_icon="'.esc_url( $notification_settings['notification_icon'] ).'" />
								<div class="form-group message-with-icon message-with-icon-and-banner js_all">
									<label for="notification-link">'.esc_html__('Icon url', 'push-notification').'</label>
									<input type="text" id="notification-iconurl" class="regular-text"  value="">
									<button type="button" class="button upload_icon_url" data-editor="content">
									<span class="dashicons dashicons-format-image" style="margin-top: 4px;"></span>'.esc_html__(' Upload an Icon', 'push-notification').'
								</button>
								</div>

								<div class="form-group message-with-banner message-with-icon-and-banner js_all">
									<label for="notification-imageurl">'.esc_html__('Banner url', 'push-notification').'</label>
									<input type="text" id="notification-imageurl" class="regular-text">
									<button type="button" class="button upload_image_url" data-editor="content">
										<span class="dashicons dashicons-format-image" style="margin-top: 4px;"></span>'.esc_html__('Upload an image', 'push-notification').'
									</button>
								</div>
								<div class="form-group">
									<label for="notification-message">'.esc_html__('Message', 'push-notification').'</label>
									<textarea type="text" rows="3" id="notification-message" class="regular-text js_pn_custom" data-meteor-emoji="true"></textarea>
								</div>
								<div class="submit inline-edit-save">
									<input type="button" class="button pn-submit-button" id="'. /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaping while calling this filter  */ apply_filters('push_notification_submit_id','pn-send-custom-notification').'" value="'.esc_html__('Send Notification', 'push-notification').'"><span class="spinner"></span>
									<div class="pn-send-messageDiv"></div>
								</div>
							</div></div><div class="pn-iphone-device-part">';
							$this->mobile_notification_preview();
						echo'</div></div>
					</div>
				</div>
				<div id="pn_campaings" style="display:none;" class="pn-tabs">
				<div id="pn_cam_loading" style="display:none; position:absolute; text-align: center; width: 100%; top: 500px;">
				  <img style="width: 100px;" src="'.esc_url( PUSH_NOTIFICATION_PLUGIN_URL ).'assets/image/pn_camp_loading.gif" title="loading" />
				</div>

					<div class="row">
						<div class="action-wrapper" style="float:right; padding-bottom: -10px;">
							<a href="#pn_notification_bell" link="pn_notification_bell" class="button dashicons-before pn-submit-button" style="margin-bottom:10px;" id="js_notification_button"> ' . esc_html__('Add Campaign','push-notification') . '</a>
						</div>
					</div>
					<div class="row" id="pn_campaings_custom_div">
					<h3>'.esc_html__('Campaigns', 'push-notification').'</h3>
					<div class="table-responsive">
					<a onclick="pn_delete_bulk_campaign(this)" class="button btn btn-danger pn_bulk_delete text-white mb-2" style="display: none">Bulk Delete</a>
                    <a onclick="pn_delete_all_campaign(this)" class="button btn btn-danger pn_delete_all text-white mb-2" style="display: none">Delete All</a>
					<br/><br/>
					<table class="wp-list-table widefat fixed striped table-view-list">
						<thead>
							<tr>
								<th width="20px"><input type="checkbox" class="pn_check_all" value="all"></th>
								<th width="200px">'.esc_html__('Title', 'push-notification').'</th>
								<th>'.esc_html__('Message', 'push-notification').'</th>
								<th width="120px">'.esc_html__('Sent on', 'push-notification').'</th>
								<th width="80px">'.esc_html__('Status', 'push-notification').'</th>
								<th width="120px">'.esc_html__('Clicks', 'push-notification').'</th>
								<th width="160px">'.esc_html__('Actions', 'push-notification').'</th>
							</tr>
						</thead>
						<tbody>';
						$current_count_start = 0;
						$timezone_string = get_option('timezone_string');
						$timezone = 'UTC';
						$clickCount = 0;
						if (!$timezone_string) {
							$gmt_offset = get_option('gmt_offset');
							$timezone = sprintf('%+d:00', $gmt_offset);
						}
						if (!empty($campaigns['campaigns']['data'])) {
	                        foreach ($campaigns['campaigns']['data'] as $key => $campaign){
								$clickCount = 0;
								if(isset($campaign['campaign_response'][0])){
									foreach ($campaign['campaign_response'] as $key => $campaign_response) {
										if ($campaign_response['meta_key'] == 'Response') {
											$resposeData = json_decode( $campaign['campaign_response'][0]['meta_value'], true);
										}else if($campaign_response['meta_key'] == 'Clicks'){
											$clickCount = $campaign_response['meta_value'];
										}
									}
								}
								$message = wp_strip_all_tags( $campaign['message'] );
								if (strlen($message) > 100) {
									$stringCut = substr($message, 0, 100);
									$endPoint = strrpos($stringCut, ' ');
									$message = $endPoint? substr($stringCut, 0, $endPoint) : substr($stringCut, 0);
									$message = nl2br($message);
									$message .= '... <a href="javascript:void(0)" class="pn_js_read_more">'.esc_html__('read more', 'push-notification').'</a>';
								}else{
									$message = nl2br($campaign['message']);
								}
								$time_in_seconds = new DateTime($campaign['created_at'], new DateTimeZone($timezone) );
								echo '<tr>
									<td style="padding-left:18px;"><input type="checkbox" class="pn_check_single" value="'.esc_attr($campaign['id']).'"></td>
									<td>'.esc_html( $campaign['title'] ).'</td>
									<td><p class="less_text">'. /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- alredy escaped  */ $message.'</p>                        
									<p class="full_text" style="display:none;">'.esc_html( wp_strip_all_tags( $campaign['message'] ) ).' <a href="javascript:void(0)" class="pn_js_read_less">'.esc_html__('read less', 'push-notification').'</a> 
									</p></td>
									<td>'.esc_html( $time_in_seconds->format( 'Y-m-d H:i:s' )  ).'</td>
									<td>';
									if ( $campaign['status'] === 'Done' ) {
										echo '<span class="badge badge-pill badge-success" style="color:green">'.esc_html($campaign['status']).'</span>';
									}elseif ($campaign['status'] === 'Failed'){
										echo '<span class="badge badge-pill badge-danger" style="color:red">'.esc_html($campaign['status']).'</span>';
									}else{
										echo '<span class="badge badge-pill badge-secondary" style="color:blue">'.esc_html($campaign['status']).'</span>';
									}
								echo'</td><td>';
									echo esc_html($clickCount);
								echo'</td>';
									echo'<td><a class="button pn_delete_button" onclick="pn_delete_campaign(this)" data-id="'.esc_attr($campaign['id']).'">'.esc_html__('Delete', 'push-notification').'</a>
									<a class="button pn_reuse_button" data-json="'.esc_attr(wp_json_encode($campaign)).'">'.esc_html__('Reuse', 'push-notification').'</a></td>';
								
								echo'</tr>';
							}
						}else{
							echo'<tr><td colspan="7" align="center">'.esc_html__('No data found', 'push-notification').'</td></tr>';
						}
						echo'</tbody></table></div>';
						if (isset($campaigns['campaigns']['data']) && !empty($campaigns['campaigns']['data']) && !empty($campaigns['campaigns']['next_page_url'])) {
						if (empty($campaigns['campaigns']['prev_page_url'])) {
							$pre_html_escaped = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>
										<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>';
						}else{
							$pre_html_escaped = '<a class="first-page button js_custom_pagination" page="1" href="'.esc_attr($campaigns['campaigns']['first_page_url']).'">
											<span class="screen-reader-text">'.esc_html__('First page', 'push-notification').'</span>
											<span aria-hidden="true">«</span>
										</a>
										<a class="prev-page button js_custom_pagination" page="'.esc_attr(($campaigns['campaigns']['current_page']-1)).'" href="'.esc_attr($campaigns['campaigns']['prev_page_url']).'">
											<span class="screen-reader-text">'.esc_html__('Previous page', 'push-notification').'</span>
											<span aria-hidden="true">‹</span>
										</a>';
						}
						if (empty($campaigns['campaigns']['next_page_url'])) {
							$next_html_escaped = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>
										<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>';
						}else{
							$next_html_escaped = '<a class="next-page button js_custom_pagination"  page="'.esc_attr(($campaigns['campaigns']['current_page']+1)).'" href="'.esc_attr($campaigns['campaigns']['next_page_url']).'">
											<span class="screen-reader-text">'.esc_html__('Next page', 'push-notification').'</span>
											<span aria-hidden="true">›</span>
										</a>
										<a class="last-page button js_custom_pagination"  page="'.esc_attr(($campaigns['campaigns']['current_page']+1)).'" href="'.esc_attr($campaigns['campaigns']['last_page_url']).'">
											<span class="screen-reader-text">'.esc_html__('Last page', 'push-notification').'</span>
											<span aria-hidden="true">»</span>
										</a>';
						}
						// already used esc_html for $pre_html_escaped and $next_html_escaped variable
						echo '<div class="tablenav bottom">
								<div class="alignleft actions bulkactions">
								</div>
								<div class="alignleft actions">
								</div>
								<div class="tablenav-pages">
									<span class="displaying-num">'.esc_html($campaigns['campaigns']['total']).' '.esc_html__('items', 'push-notification').'</span>
									<span class="pagination-links">'. /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped  */ $pre_html_escaped.'<span class="screen-reader-text">'.esc_html__('Current Page', 'push-notification').'</span>
										<span id="table-paging" class="paging-input">
											<span class="tablenav-paging-text">'.esc_html($campaigns['campaigns']['current_page']).' '.esc_html__('of', 'push-notification').'
												<span class="total-pages">'.esc_html($campaigns['campaigns']['last_page']).'</span>
											</span>
										</span>'. /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped  */ $next_html_escaped.'
									</span>
								</div>
								<br class="clear">
							</div>';
					}                
		        echo '</div></div>

				<div id="pn_help" style="display:none;" class="pn-tabs">
					<h3>'.esc_html__('Documentation', 'push-notification').'</h3>
					<a target="_blank" class="button pn-submit-button" href="https://pushnotifications.helpscoutdocs.com/">'.esc_html__('View Setup Documentation', 'push-notification').'</a>

                   	<h3>'.esc_html__('Ask for Technical Support', 'push-notification') .'</h3>
                   	<p>'.esc_html__('We are always available to help you with anything', 'push-notification').'</p>
		            <ul>
					<li>
					<label for="pn_query_email">'.esc_html__('Email', 'push-notification').'</label>
					<input type="text" id="pn_query_email" class="regular-text" name="pn_query_email" placeholder="youremail@example.com" >
				 	</li>
		                <li><label for="pn_help_query_customer">'.esc_html__('Are you existing Premium Customer?', 'push-notification').'</label>
		                    <select class="regular-select" id="pn_help_query_customer" name="pn_help_query_customer">
		                    	<option value="">Select</option>
		                    	<option value="Yes">'.esc_html__('Yes', 'push-notification').'</option>
		                    	<option value="No">'.esc_html__('No', 'push-notification').'</option>
		                    </select>
		                </li> 
		                <li><label for="pn_help_query_message">'.esc_html__('Message', 'push-notification').'</label>
		                    <textarea rows="5" id="pn_help_query_message" name="pn_help_query_message" class="regular-textarea"></textarea>
		                    <br>
		                    <p class="pn_help-query-success" style="display:none;">'.esc_html__('Message sent successfully, Please wait we will get back to you shortly', 'push-notification').'</p>
		                    <p class="pn_help-query-error" style="display:none;">'.esc_html__('Message not sent. please check your network connection', 'push-notification').'</p>
		                </li> 
		                <li><button class="button pn_help-send-query pn-submit-button">'.esc_html__('Send Message', 'push-notification').'</button></li>
		            </ul>            		                  
		        </div>
				';
	}

	public function pn_key_validate_status_callback(){
		$authData = push_notification_auth_settings();
		if( !isset($authData['token_details']['validated']) 
			|| (isset($authData['token_details']) && $authData['token_details']['validated']!=1) ){
			echo "<fieldset>";
			PN_Field_Generator::get_input_password('user_token', 'user_auth_token_key');
			PN_Field_Generator::get_button('Validate', 'user_auth_vadation');
			echo '<span class="resp_message"></span></fieldset>
			<p>'.esc_html__('This plugin requires a free API key form PushNotification.io', 'push-notification').' <a target="_blank" href="'.esc_url_raw(PN_Server_Request::$notificationlanding."register").'">'.esc_html__('Get the Key', 'push-notification').'</a></p>';
		}else{
			echo "<input type='text' class='regular-text' value='xxxxxxxxxxxxxxxxxx'>";
			if(PN_Server_Request::getProStatus()=='active'){
				echo "<span class='text-success resp_message' style='color:green;'>".esc_html__('Premium API Activated', 'push-notification')."</span>";
				echo "<div><b>".esc_html__('Plan Type : ', 'push-notification')."</b>";
				if(isset($authData['token_details']['plan'])){
					echo esc_html($authData['token_details']['plan']);
				}
				echo "</div><br/>";
				echo "<div><b>".esc_html__('Plan Expiry Date : ', 'push-notification')."</b>";
				if(isset($authData['token_details']['plan_end_date'])){
					echo esc_html($authData['token_details']['plan_end_date']);
				}
				echo "</div><br/>";
			}
			else{
				echo "<span class='text-success resp_message' style='color:green;'>".esc_html__('User Verified', 'push-notification')."</span><br/><br/>";				
			}
		
			echo "<button type='button' class='button dashicons-before dashicons-no-alt pn-submit-button' id='pn-remove-apikey' style='line-height: 1.4;' >".esc_html__('Revoke key', 'push-notification')."</button>";
		}
		if(!empty($authData['token_details']['validated']) && $authData['token_details']['validated']=='1'){
			echo "<button type='button' class='button dashicons-before dashicons-update pn-submit-button' id='pn-refresh-apikey' style='margin-left:2%; line-height: 1.4;'>".esc_html__('Refresh', 'push-notification')."</button>";
		}

		echo "<br/><br/><div>".esc_html__('Need help! Read the Complete ', 'push-notification')."<a href='https://pushnotifications.helpscoutdocs.com/' target='_blank'>".esc_html__('Documentation', 'push-notification')."</a>.</div><br/>";
	}//function closed

	public function user_settings_notification_icon_callback(){
		PN_Field_Generator::get_input('notification_icon', 'notification_icon', 'pn_push_on_edit', 'pn-checkbox pn_push_on_edit');
	}
	public function user_settings_notification_pop_up_icon_callback(){
		PN_Field_Generator::get_input('notification_pop_up_icon', 'notification_pop_up_icon', 'pn_push_on_edit', 'pn-checkbox pn_push_on_edit');
	}

	public function user_settings_onpublish_callback(){		
		PN_Field_Generator::get_input_checkbox('on_publish', '1', 'pn_push_on_publish', 'pn-checkbox pn_push_on_publish');
	}

	public function pn_key_posttype_select_callback(){		
		$data = get_post_types();
		if(is_array($data) && !empty($data)){
			$data = array_merge(array('none'=>'None'), $data);
		}
		PN_Field_Generator::get_input_multi_select('posttypes', array('post'), $data, 'pn_push_on_publish', '');
	}
	public function pn_key_segment_select_callback(){		
		$notification = push_notification_settings();
		$on_category_checked = "";
		if (!empty($notification['on_category'])) {
			$on_category_checked = "checked";
		}
		echo '<div class="pn-field_wrap">';
			echo'<div class="checkbox_wrapper">
					<input type="checkbox" class="regular-text checkbox_operator" id="pn_push_on_category_checkbox" name="push_notification_settings[on_category]"  value="1" '.esc_attr($on_category_checked).'/></div></div>';
	}

	public function pn_key_segment_on_categories_callback() {

		$segment_on_category_checked = "";
		$notification = push_notification_settings();		
		
		if( isset( $notification['segment_on_category'] ) && $notification['segment_on_category'] ) {

			$segment_on_category_checked = "checked";

		}	

		if( isset( $notification['on_category'] ) && $notification['on_category'] ) {

			echo '<div id="category_selector_wrapper" style="display:block;">';

		}else{

			echo '<div id="category_selector_wrapper" style="display:none;">';

		}		
		
			echo '<div class="pn-field_wrap">
			<div class="checkbox_wrapper">
					<input type="checkbox" class="regular-text checkbox_operator" id="pn_push_segment_on_category_checkbox" name="push_notification_settings[segment_on_category]"  value="1" '.esc_attr($segment_on_category_checked).'/>
				</div></div>';
	}
	
	public function pn_select_specific_categories_callback() {

			$notification = push_notification_settings();		

			if ( isset( $notification['on_category'] ) && $notification['on_category'] ) {
				echo '<div id="category_selector_wrapper" class="js_custom_category_selector_wrapper" style="display:block;">';
			}else{
				echo '<div id="category_selector_wrapper" class="js_custom_category_selector_wrapper" style="display:none;">';
			}
		
			echo '<div class="pn-field_wrap">';
				
			$settings = push_notification_settings();
			
			$category_val = isset($settings['category'])?$settings['category']:array();
			$selected_category = !is_array($category_val) ? explode(',',$category_val ) : $category_val;
			$category_data = push_notification_category(null,$selected_category);
			echo "<div id='segment_category_selector_wrapper'>";
				echo '<select name="push_notification_settings[category][]" id="js_category" class="regular-text pn_category_select2">';
					foreach ($category_data as $key => $value) {
						$selected_option ='';
						if (in_array($value['id'],$selected_category) ||  in_array(get_cat_name($value['id']),$selected_category)) {
							$selected_option ='selected=selected';
						}
						
						echo '<option value="'.esc_attr($value['id']).'"  '.esc_attr($selected_option).'>'. esc_html($value['text']).'</option>';
					}
			echo '</select></div></div>';
	}
	public function pn_utm_tracking_callback() {

		$notification = push_notification_settings();
		$name = 'utm_tracking_checkbox';
		$value = 1;$class = $id = 'utm_tracking_checkbox';

		PN_Field_Generator::get_input_checkbox($name, $value, $id, $class);		
		
		if(isset($notification['utm_tracking_checkbox']) && $notification['utm_tracking_checkbox']){

			echo '<div id="utm_tracking_wrapper" style="display:block;">';

		}else{

			echo '<div id="utm_tracking_wrapper" style="display:none;">';

		}	

			echo '<div class="pn-field_wrap"><label>'.esc_html__('UTM source', 'push-notification').'</label>';
				PN_Field_Generator::get_input('notification_utm_source', 'notification_utm_source', '');
			echo '</div>';
			echo '<div class="pn-field_wrap"><label>'.esc_html__('UTM Medium', 'push-notification').'</label>';
			PN_Field_Generator::get_input('notification_utm_medium', 'notification_utm_medium', '');
			echo '</div>';
			echo '<div class="pn-field_wrap"><label>'.esc_html__('UTM Campaign', 'push-notification').'</label>';
			PN_Field_Generator::get_input('notification_utm_campaign', 'notification_utm_campaign', '');
			echo '</div>';
			echo '<div class="pn-field_wrap"><label>'.esc_html__('UTM Term', 'push-notification').'</label>';
			PN_Field_Generator::get_input('notification_utm_term', 'notification_utm_term', '');
			echo '</div>';
			echo '<div class="pn-field_wrap" style="display:flex;"><label>'.esc_html__('UTM Content', 'push-notification').'</label>';
			PN_Field_Generator::get_input('notification_utm_content', 'notification_utm_content', '');
			echo '</div>';
		echo "</div>";
	}
	public function pn_key_position_select_callback(){		
		$data = array(
			'top-left'=> esc_html__('Top left', 'push-notification'),
			'top-right'=> esc_html__('Top right', 'push-notification'),
			'bottom-right'=> esc_html__('Bottom right', 'push-notification'),
			'bottom-left'=> esc_html__('Bottom Left', 'push-notification'),
		);
		PN_Field_Generator::get_input_select('notification_position', 'bottom-left', $data, 'pn_push_on_publish', '');
	}

	public function pn_key_url_capture_select_callback(){		
		$data = array(
			'off'=> esc_html__('Select Method', 'push-notification'),
			'auto'=> esc_html__('Automatic', 'push-notification'),
			'manual'=> esc_html__('Manual', 'push-notification'),
		);
		PN_Field_Generator::get_input_select('pn_url_capture', 'off', $data, 'pn_url_capture', '');
	}

	public function pn_key_url_manual_capture_callback(){
	
		PN_Field_Generator::get_input_textarea('pn_url_capture_manual', 'pn_url_capture_manual','//Add URLs in separate line'.PHP_EOL.'https://example.com/page1 '.PHP_EOL.'https://example.com/page2',50,8);
	}

	public function pn_key_actions_buttons_position_callback(){		
		$data = array(
			'top'=> esc_html__('Top', 'push-notification'),
			'bottom'=> esc_html__('Bottom', 'push-notification'),
		);
		PN_Field_Generator::get_input_select('notification_botton_position', 'top', $data, 'pn_push_on_publish', '');
	}
	public function pn_key_banner_message_callback(){		
		PN_Field_Generator::get_input('popup_banner_message', 'popup_banner_message_id');
	}

	public function pn_key_popup_display_settings_title_callback(){		
		PN_Field_Generator::get_input_color('popup_display_setings_title_color', 'popup_display_setings_title_color_id', 'my-color-field');
	}public function pn_key_popup_display_settings_ok_callback(){		
		PN_Field_Generator::get_input_color('popup_display_setings_ok_color', 'popup_display_setings_ok_color_id', 'my-color-field');
	}
	public function pn_key_popup_display_settings_no_thanks_callback(){		
		PN_Field_Generator::get_input_color('popup_display_setings_no_thanks_color', 'popup_display_setings_no_thanks_color_id', 'my-color-field');
	}
	public function pn_key_popup_display_settings_text_callback(){		
		PN_Field_Generator::get_input_color('popup_display_setings_text_color', 'popup_display_setings_text_color_id', 'my-color-field');
	}
	public function pn_key_popup_display_settings_bg_callback(){		
		PN_Field_Generator::get_input_color('popup_display_setings_bg_color', 'popup_display_setings_bg_color_id', 'my-color-field');
	}
	public function pn_key_popup_display_setings_border_radius_callback(){        
        PN_Field_Generator::get_input_number('popup_display_setings_border_radius', 'popup_display_setings_border_radius_id');
		echo "<p class='description'>".esc_html__('Set Border radius of Popup (in px)',"push-notification")."</p>";
    }
	public function pn_key_banner_accept_btn_callback(){		
		PN_Field_Generator::get_input('popup_banner_accept_btn', 'popup_banner_accept_btn_id');
	}
	public function pn_key_banner_decline_btn_callback(){		
		PN_Field_Generator::get_input('popup_banner_decline_btn', 'popup_banner_decline_btn_id');
	}
	public function pn_key_popupshowagain_callback(){
		PN_Field_Generator::get_input('notification_popup_show_again', 'notification_popup_show_again', '');
		echo "<p class='help'> ".esc_html__('Show Popup again after decline by the user (in Days)', 'push-notification')." </p>";
	}
	public function pn_key_popupshowafternseconds_callback(){		
		PN_Field_Generator::get_input('notification_popup_show_afternseconds', 'notification_popup_show_afternseconds', '');
		echo "<p class='help'> ".esc_html__('Show Popup after n seconds (in Seconds)', 'push-notification')." </p>";
	}
	public function pn_key_popupshowafternpageview_callback(){		
		PN_Field_Generator::get_input('notification_popup_show_afternpageview', 'notification_popup_show_afternpageview', '');
		echo "<p class='help'> ".esc_html__('Show Popup after nth page view (Default 1)', 'push-notification')." </p>";
	}

	public function user_notification_order_change_callback(){		

		if ( !class_exists( 'WooCommerce' ) ) {
			
			echo "<p class='description'>".esc_html__('This feature is only available for WooCommerce users.',"push-notification")."</p>";
			return;
		}
		PN_Field_Generator::get_input_checkbox('notification_on_order_change_to_user', 1, 'send_notification_to_user_order', "", esc_html__("To User", 'push-notification'));
		PN_Field_Generator::get_input_checkbox('notification_on_order_change_to_admin', 1, 'send_notification_to_admin_order', "", esc_html__("To Admin", 'push-notification'));
		echo "<p class='description'>".esc_html__('Send notification when order status will change',"push-notification")."</p>";
	}

	public function user_notification_um_title(){		
		PN_Field_Generator::get_input('pn_um_notification_title', 'pn_um_notification_title', '');
		echo "<p class='help'> ".esc_html__('Show Popup after nth page view (Default 1)', 'push-notification')." </p>";
	}

	public function pn_verify_user(){
		if(empty( $_POST['nonce'])){
			wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
		}
		else if( isset( $_POST['nonce']) &&  !wp_verify_nonce($_POST['nonce'], 'pn_notification') ){
			wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
		}else{
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
			}
			$user_token = sanitize_text_field($_POST['user_token']);
			$response = PN_Server_Request::varifyUser($user_token);
			if( function_exists('pwaforwp_required_file_creation') ){
				$pwaSettings = pwaforwp_defaultSettings();
				if( $pwaSettings['notification_feature']==1 && isset($pwaSettings['notification_options']) && $pwaSettings['notification_options']=='pushnotifications_io'){
					pwaforwp_required_file_creation();
				}
			}

			wp_send_json($response);
		}		

		wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not identified','push-notification')));
	}
	public function pn_refresh_api_key(){
		if(empty( $_POST['nonce'])){
			wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
		}
		else if( isset( $_POST['nonce']) &&  !wp_verify_nonce($_POST['nonce'], 'pn_notification') ){
			wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
		}else{
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
			}
			$authData = push_notification_auth_settings();
		$verifyUrl = 'validate/user';
		if ( is_multisite() ) {
            $weblink = get_site_url();
        }
		else {
            $weblink = home_url();
        }    
		$data = array("user_token"=>$authData['user_token'], "website"=>   $weblink);
		$response = PN_Server_Request::varifyUser($authData['user_token']);
		wp_send_json($response);
		}
		
	}
	public function pn_revoke_keys(){
		
		$request_response = array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification'));
		if(empty( $_POST['nonce'])){
			wp_send_json($request_response);
		}
		else if( isset( $_POST['nonce']) &&  !wp_verify_nonce($_POST['nonce'], 'pn_notification') ){
			wp_send_json($request_response);
		}else{
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json($request_response);
			}
			$auth_settings = push_notification_auth_settings();
			if (isset($auth_settings['user_token']) && !empty($auth_settings['user_token'])) {
				$user_token = $auth_settings['user_token'];
				$server_response = PN_Server_Request::inactivateWebsite($user_token);
				if ($server_response['status']) {
					delete_option('push_notification_auth_settings');
					$request_response['status'] = 200;
					$request_response['server_response'] = $server_response;
					$request_response['message'] = esc_html__('API key removed successfully', 'push-notification');
				}else{
					$request_response['server_response'] = $server_response;
				}
			}
			wp_send_json($request_response);
		}
	}

	public function pn_subscribers_data(){
		if(empty( $_POST['nonce'])){
			wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
		}
		else if( isset( $_POST['nonce']) &&  !wp_verify_nonce($_POST['nonce'], 'pn_notification') ){
			wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
		}else{
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
			}
			$auth_settings = push_notification_auth_settings();
			if( isset( $auth_settings['user_token'] ) ){
				$response = PN_Server_Request::getsubscribersData( $auth_settings['user_token'] );
				wp_send_json($response);
			}else{
				wp_send_json(array("status"=> 503, 'message'=> esc_html__('User token not found', 'push-notification')));
			}

		}
	}
	public function pn_delete_campaigns(){
		if(empty( $_POST['nonce'])){
			wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
		}
		else if( isset( $_POST['nonce']) &&  !wp_verify_nonce($_POST['nonce'], 'pn_notification') ){
			wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
		}else{
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
			}
			
			$auth_settings = push_notification_auth_settings();
			$campaign_ids = (isset($_POST['campaign_ids']) && is_array($_POST['campaign_ids'])) ? array_map('sanitize_text_field', $_POST['campaign_ids']):sanitize_text_field( $_POST['campaign_ids'] );
			if($campaign_ids != 'all'){
				$campaign_ids = implode(',', $campaign_ids);
			}
			if( isset( $auth_settings['user_token'] ) ){
				$response = PN_Server_Request::deleteCampaigns( $auth_settings['user_token'], $campaign_ids );
				wp_send_json($response);
			}else{
				wp_send_json(array("status"=> 503, 'message'=> esc_html__('User token not found', 'push-notification')));
			}
		}
	}
	public function pn_send_notification(){
		if(empty( $_POST['nonce'])){
			wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
		}
		else if( isset( $_POST['nonce']) &&  !wp_verify_nonce($_POST['nonce'], 'pn_notification') ){
			wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
		}else{
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
			}
			global $wpdb;
			$auth_settings = push_notification_auth_settings();
			$notification_settings = push_notification_settings();

			$title = sanitize_text_field(stripcslashes($_POST['title']));
			$message = sanitize_textarea_field(stripcslashes($_POST['message']));
			$link_url = esc_url_raw($_POST['link_url']);
			$image_url = esc_url_raw($_POST['image_url']);
			$icon_url = isset($_POST['icon_url'])?esc_url_raw($_POST['icon_url']):$notification_settings['notification_icon'];
			$audience_token_id = isset($_POST['audience_token_id'])?sanitize_text_field($_POST['audience_token_id']):'';
			$audience_token_url = isset($_POST['audience_token_url'])?sanitize_text_field($_POST['audience_token_url']):'';
			$send_type = isset($_POST['send_type'])?sanitize_text_field($_POST['send_type']):'';
			$page_subscribed = isset($_POST['page_subscribed'])?sanitize_text_field($_POST['page_subscribed']):'';
			$notification_schedule = isset($_POST['notification_schedule'])?sanitize_text_field($_POST['notification_schedule']):'';

			$notification_date = isset($_POST['notification_date'])?sanitize_text_field($_POST['notification_date']):'';

			$notification_time = isset($_POST['notification_time'])?sanitize_text_field($_POST['notification_time']):'';
			if($send_type=='custom-select'){
				$audience_token_id = isset($audience_token_id)?explode(',',$audience_token_id):'';
			}else if($send_type=='custom-upload'){
				$audience_token_id = str_replace('\\','',$audience_token_id );
				$audience_token_id = json_decode($audience_token_id,true);
			}
			
			if(isset($notification_settings['utm_tracking_checkbox']) && $notification_settings['utm_tracking_checkbox']){
				$utm_details = array(
				    'utm_source'=> $notification_settings['notification_utm_source'],
				    'utm_medium'=> $notification_settings['notification_utm_medium'],
				    'utm_campaign'=> $notification_settings['notification_utm_campaign'],
				    'utm_term'  => $notification_settings['notification_utm_term'],
				    'utm_content'  => $notification_settings['notification_utm_content'],
				    );
				$link_url = add_query_arg( array_filter($utm_details), $link_url  );
			}
			$user_ids=[];
			if( isset( $auth_settings['user_token'] ) ){
				$push_notify_token=[];
				if(!empty($audience_token_id) && is_array($audience_token_id))
				{
					foreach($audience_token_id as $token_id){
						
						if($send_type=='custom-select'){
							$token_ids = get_user_meta($token_id, 'pnwoo_notification_token',true);
							$user_ids[] = $token_id;
						}else if($send_type=='custom-upload'){
							if(!empty($token_id['email']))
							{	
								$user = get_user_by( 'email', trim($token_id['email']) );
								if(!$user){
									$user = get_user_by( 'login', trim($token_id['username']) );
								}
							}
							if($user && isset($user->ID)){
								$token_ids = get_user_meta($user->ID, 'pnwoo_notification_token',true);
								if($token_ids){
									$user_ids[] = $token_ids;
								}
							}
						}
						if(is_array($token_ids) && !empty($token_ids)){
							$push_notify_token = array_merge($push_notify_token,$token_ids);
						}
						else if($token_ids){
							$push_notify_token[]=$token_ids;
						}

					}
				}

				if( ! empty($send_type) ){
					if($send_type=='custom-page-subscribed'){
						$authData = push_notification_auth_settings();
						if(!empty($page_subscribed) && isset($authData['token_details']) && $authData['token_details']['validated'] ==1){
							$push_notify_token =pn_get_tokens_by_url($page_subscribed);
						}
					}				
					if(empty($push_notify_token)){
						if($send_type=='custom-select'){
							wp_send_json(array("status"=> 404, 'message'=>esc_html__('No Active subscriber found from the selection', 'push-notification')));
						}else if($send_type=='custom-upload'){
							wp_send_json(array("status"=> 404, 'message'=>esc_html__('No Active subscriber found from the csv list', 'push-notification')));
						}else{
							wp_send_json(array("status"=> 404, 'message'=>esc_html__('No Active subscriber found', 'push-notification')));
						}
					}
				}

				$payload =array(
					'user_token'=>$auth_settings['user_token'],
					'title'=>$title,
					'message'=>$message,
					'link_url'=>$link_url,
					'icon_url'=>$icon_url,
					'image_url'=>$image_url,
					'category'=>'',
					'audience_token_id'=>$push_notify_token,
					'audience_token_url'=>$audience_token_url,
					'notification_schedule'=>$notification_schedule,
					'notification_time'=>$notification_time,
					'notification_date'=>$notification_date,
				);
				$response = PN_Server_Request::sendPushNotificatioDataNew($payload);
				$table_name = $wpdb->prefix . 'um_notifications';
				if($response){
				 	wp_send_json($response);
					 if(class_exists('um_ext\um_notifications\core\Notifications_Main_API')){

						foreach($user_ids as $pn_user_id){
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Reason : Custom table
							$insert = $wpdb->insert(
								$table_name,
								array(
									'time'    => gmdate( 'Y-m-d H:i:s' ),
									'user'    => $pn_user_id,
									'status'  => 'unread',
									'photo'   => $icon_url,
									'type'    => 'new_pm',
									'url'     => $link_url,
									'content' => $message,
								)
							);
							if($insert){
								$new_notify = get_user_meta( $pn_user_id, 'um_new_notifications');
								if($new_notify && is_array($new_notify)){
									$new_notify[] = $wpdb->insert_id;
									update_user_meta( $pn_user_id, 'um_new_notifications', $new_notify );
								}
							}
							
						}
						
					 }

				}else{
					wp_send_json(array("status"=> 403, 'message'=>esc_html__('Request not completed', 'push-notification')));
				}
			}else{
				wp_send_json(array("status"=> 503, 'message'=>esc_html__('User token not found', 'push-notification')));	
			}			

		}
	}

	public function send_notification_on_update($new_status, $old_status, $post){
		$pn_settings = push_notification_settings();		
		if ( 'publish' !== $new_status ){
        	return;
		}
		if(!isset($pn_settings['posttypes'])){
			$pn_settings['posttypes'] = array("post");
		}
		if( !in_array( get_post_type($post), $pn_settings['posttypes']) ){
			return;
		}				
		$post_notf_on = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reasone: already nonce verified
		if(isset($_POST['pn_send_notification_on_post'])){
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reasone: already nonce verified
			$post_notf_on = sanitize_text_field($_POST['pn_send_notification_on_post']);
		}			
		if(isset($pn_settings['on_publish']) && $pn_settings['on_publish']==1 && (empty($post_notf_on) || $post_notf_on !== 1)){
			if ( $new_status !== $old_status) {
			 	$this->send_notification($post);
			}
		}

	}

	public function pn_get_compaigns(){		
		if(empty( $_POST['nonce'])){
			return;	
		}
		if( isset( $_POST['nonce']) &&  !wp_verify_nonce($_POST['nonce'], 'pn_notification') ){
			return;	
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;	
		}
		$page = sanitize_text_field($_POST['page']);
		$authData = push_notification_auth_settings();
		if ($authData['token_details']['validated']!=1 ){
			return;  
		}
		$campaigns = [];
		if(isset( $authData['user_token'] ) && !empty($authData['user_token']) ){
			$campaigns = PN_Server_Request::getCompaignsData( $authData['user_token'],$page);
		}
		
		
		$campaigns_html_escaped = '<h3>'.esc_html__('Campaigns', 'push-notification').'</h3>
					<table class="wp-list-table widefat fixed striped table-view-list">
						<thead>
							<tr>
								<th width="20px"><input type="checkbox" class="pn_check_all" value="all"></th>
								<th width="200px">'.esc_html__('Title', 'push-notification').'</th>
								<th>'.esc_html__('Message', 'push-notification').'</th>
								<th width="120px">'.esc_html__('Sent on', 'push-notification').'</th>
								<th width="80px">'.esc_html__('Status', 'push-notification').'</th>
								<th width="80px">'.esc_html__('Clicks', 'push-notification').'</th>
								<th width="160px">'.esc_html__('Actions', 'push-notification').'</th>
							</tr>
						</thead>
						<tbody>';
						$current_count_start = 0;
						if (!empty($campaigns['campaigns']['data'])) {
	                        foreach ($campaigns['campaigns']['data'] as $key => $campaign){
								$clickCount = 0;
								if(isset($campaign['campaign_response'][0])){
									foreach ($campaign['campaign_response'] as $key => $campaign_response) {
										if ($campaign_response['meta_key'] == 'Response') {
											$resposeData = json_decode( $campaign['campaign_response'][0]['meta_value'], true);
										}else if($campaign_response['meta_key'] == 'Clicks'){
											$clickCount = $campaign_response['meta_value'];
										}
									}
								}
								$message = $campaign['message'];
								if (strlen($message) > 100) {
									$stringCut = substr($message, 0, 100);
									$endPoint = strrpos($stringCut, ' ');
									$message = $endPoint? substr($stringCut, 0, $endPoint) : substr($stringCut, 0);
									$message = nl2br($message);
									$message .= '... <a href="javascript:void(0)" class="pn_js_read_more">'.esc_html__('read more', 'push-notification').'</a>';
								}else{
									$message = nl2br($campaign['message']);
								}
								$campaigns_html_escaped.='<tr>
									<td style="padding-left:18px;"><input type="checkbox" class="pn_check_single" value="'.esc_attr($campaign['id']).'"></td>
									<td>'.esc_html($campaign['title']).'</td>
									<td><p class="less_text">'.$message.'</p>                        
									<p class="full_text" style="display:none;">'.wp_strip_all_tags($campaign['message']).' <a href="javascript:void(0)" class="pn_js_read_less">'.esc_html__('read less', 'push-notification').'</a> 
									</p></td>
									<td>'.esc_html($campaign['created_at'] ).'</td>
									<td>';
									if ($campaign['status'] === 'Done') {
										$campaigns_html_escaped.='<span class="badge badge-pill badge-success" style="color:green">'.esc_html($campaign['status']).'</span>';
									}elseif ($campaign['status'] === 'Failed'){
										$campaigns_html_escaped.='<span class="badge badge-pill badge-danger" style="color:red">'.esc_html($campaign['status']).'</span>';
									}else{
										$campaigns_html_escaped.='<span class="badge badge-pill badge-secondary" style="color:blue">'.esc_html($campaign['status']).'</span>';
									}
									$campaigns_html_escaped.='</td><td>';
									$campaigns_html_escaped.=esc_html($clickCount);
									$campaigns_html_escaped.='</td>';
									$campaigns_html_escaped.='<td><a class="button pn_delete_button" onclick="pn_delete_campaign(this)" data-id="'.esc_attr($campaign['id']).'">'.esc_html__('Delete', 'push-notification').'</a>
									<a class="button pn_reuse_button" data-json="'.esc_attr(wp_json_encode($campaign)).'">'.esc_html__('Reuse', 'push-notification').'</a>
									</td>';
								$campaigns_html_escaped.='</tr>';
							}
						}else{
							$campaigns_html_escaped.='<tr><td colspan="7">'.esc_html__('No data found', 'push-notification').'</td></tr>';
						}
						$campaigns_html_escaped.='</tbody></table>';
						if (isset($campaigns['campaigns']['data']) && !empty($campaigns['campaigns']['data'])) {
							if (empty($campaigns['campaigns']['prev_page_url'])) {
								$pre_html_escaped = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>
											<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>';
							}else{
								$pre_html_escaped = '<a class="first-page button js_custom_pagination" page="1" href="'.esc_url($campaigns['campaigns']['first_page_url']).'">
												<span class="screen-reader-text">'.esc_html__('First page', 'push-notification').'</span>
												<span aria-hidden="true">«</span>
											</a>
											<a class="prev-page button js_custom_pagination" page="'.esc_attr(($campaigns['campaigns']['current_page']-1)).'" href="'.esc_url($campaigns['campaigns']['prev_page_url']).'">
												<span class="screen-reader-text">'.esc_html__('Previous page', 'push-notification').'</span>
												<span aria-hidden="true">‹</span>
											</a>';
							}
							if (empty($campaigns['campaigns']['next_page_url'])) {
								$next_html_escaped = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>
											<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>';
							}else{
								$next_html_escaped = '<a class="next-page button js_custom_pagination"  page="'.esc_attr(($campaigns['campaigns']['current_page']+1)).'" href="'.esc_url($campaigns['campaigns']['next_page_url']).'">
												<span class="screen-reader-text">'.esc_html__('Next page', 'push-notification').'</span>
												<span aria-hidden="true">›</span>
											</a>
											<a class="last-page button js_custom_pagination"  page="'.esc_attr(($campaigns['campaigns']['current_page']+1)).'" href="'.esc_url($campaigns['campaigns']['last_page_url']).'">
												<span class="screen-reader-text">'.esc_html__('Last page', 'push-notification').'</span>
												<span aria-hidden="true">»</span>
											</a>';
							}
						// already used esc_html for $pre_html_escaped and $next_html_escaped
							$campaigns_html_escaped.='<div class="tablenav bottom">
									<div class="alignleft actions bulkactions">
									</div>
									<div class="alignleft actions">
									</div>
									<div class="tablenav-pages">
										<span class="displaying-num">'.esc_html($campaigns['campaigns']['total']).' '.esc_html__('items', 'push-notification').'</span>
										<span class="pagination-links">'. /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped  */ $pre_html_escaped.'<span class="screen-reader-text">'.esc_html__('Current Page', 'push-notification').'</span>
											<span id="table-paging" class="paging-input">
												<span class="tablenav-paging-text">'.esc_html($campaigns['campaigns']['current_page']).' '.esc_html__('of', 'push-notification').'
													<span class="total-pages">'.esc_html($campaigns['campaigns']['last_page']).'</span>
												</span>
											</span>'. /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped  */ $next_html_escaped.'
										</span>
									</div>
									<br class="clear">
								</div>';
						}
		        $campaigns_html_escaped.='</div>';
		/* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped  */				
		echo $campaigns_html_escaped;
		wp_die();           
	}

	protected function send_notification($post){
		$post_id = $post->ID;
		$post_content = get_the_excerpt($post->ID);
		$post_title = $post->post_title;
		$auth_settings = push_notification_auth_settings();
		$push_notification_settings = push_notification_settings();
		//API Data
		$title = sanitize_text_field(wp_strip_all_tags($post_title, true) );
		$category_detail = get_the_category($post->ID);//$post->ID
		for($i=0; $i < count($category_detail); $i++) {
			$category_name[] = $category_detail[$i]->slug;
		}
		$category = '';
		if(!empty($category_name)){
			$category = implode(',',$category_name);
		} 
		$post_content= preg_replace('#\[[^\]]+\]#', '',$post_content);
		$message = wp_trim_words(wp_strip_all_tags(sanitize_text_field($post_content), true), 20);
		$link_url = esc_url_raw(get_permalink( $post_id ));
		if(isset($push_notification_settings['utm_tracking_checkbox']) && $push_notification_settings['utm_tracking_checkbox']){
			$utm_details = array(
			    'utm_source'=> $push_notification_settings['notification_utm_source'],
			    'utm_medium'=> $push_notification_settings['notification_utm_medium'],
			    'utm_campaign'=> $push_notification_settings['notification_utm_campaign'],
			    'utm_term'  => $push_notification_settings['notification_utm_term'],
			    'utm_content'  => $push_notification_settings['notification_utm_content'],
			    );
			$link_url = add_query_arg( array_filter($utm_details), $link_url  );
		}
		$image_url = '';
		if(has_post_thumbnail($post_id)){
			$image_url = esc_url_raw(get_the_post_thumbnail_url($post_id));
		}
		$icon_url = $push_notification_settings['notification_icon'];
		if( isset( $auth_settings['user_token'] ) && !empty($auth_settings['user_token']) ){
			$response = PN_Server_Request::sendPushNotificatioData( $auth_settings['user_token'], $title, $message, $link_url, $icon_url, $image_url, $category);
		}//auth token check 	

	}
	
	/**
	 * Send the push notification when orders will change
	 * @method pn_order_send_notification
	 * @param  string 			Ref Order id 
	 * @param  string 			Ref status_from
	 * @param  string           $status_to  converted status.
	 * @param  string           $$obj  Order Object.
	 * @return Void                                            
	 */
	public function pn_order_send_notification($order_id, $status_from, $status_to, $obj){
		if(strtolower($status_to)==='pending'){ return; }
		$push_notification_settings = push_notification_settings();
		if(isset($push_notification_settings['notification_on_order_change_to_user']) && $push_notification_settings['notification_on_order_change_to_user']!=1){ return ; }
		$order = wc_get_order( $order_id );
		$user_id = $order->get_user_id();
		$token_ids = get_user_meta($user_id, 'pnwoo_notification_token',true);

		//Send notificarion to admin
		if(isset($push_notification_settings['notification_on_order_change_to_admin']) && $push_notification_settings['notification_on_order_change_to_admin']==1){ 
			$args = array(
			    'role'    => 'administrator',
			    'order'   => 'ASC'
			);
			$users = get_users( $args );
			if(count($users)>0){
				foreach ($users as $key => $user) {
					$tokens = get_user_meta($user->ID, 'pnwoo_notification_token',true);
					if(is_array($tokens) && !empty($tokens)){
						$token_ids = array_merge($token_ids, $tokens);
					}
				}
			}
		}

		if (empty($token_ids)) {
			return;
		}else{
			if(!is_array($token_ids)){
				$token_ids[] = $token_ids;
			}
		}

		$token_ids = array_filter($token_ids);
		$token_ids = array_unique($token_ids);

		$post_title = esc_html__('Order status changed', 'push-notification');
		$post_content = esc_html__('Order id #', 'push-notification').esc_html($order_id).esc_html__(' changed from ', 'push-notification').esc_html($status_from).esc_html__(' to ', 'push-notification').esc_html($status_to);
		$auth_settings = push_notification_auth_settings();

		//API Data
		$title = sanitize_text_field(wp_strip_all_tags($post_title, true) );
		$message = wp_trim_words(wp_strip_all_tags(sanitize_text_field($post_content), true), 20);
		$link_url = esc_url_raw( get_home_url() );
		if(isset($push_notification_settings['utm_tracking_checkbox']) && $push_notification_settings['utm_tracking_checkbox']){
			$utm_details = array(
			    'utm_source'=> $push_notification_settings['notification_utm_source'],
			    'utm_medium'=> $push_notification_settings['notification_utm_medium'],
			    'utm_campaign'=> $push_notification_settings['notification_utm_campaign'],
			    'utm_term'  => $push_notification_settings['notification_utm_term'],
			    'utm_content'  => $push_notification_settings['notification_utm_content'],
			    );
			$link_url = add_query_arg( array_filter($utm_details), $link_url  );
		}

		$image_url = $push_notification_settings['notification_icon'];
		$icon_url = $push_notification_settings['notification_icon'];

		if( isset( $auth_settings['user_token'] ) && !empty($auth_settings['user_token']) ){
			$userid = 1;
			if(function_exists('get_current_user_id')){
				$userid = get_current_user_id();
			}

			$verifyUrl = PN_Server_Request::$notificationServerUrl.'campaign/single';
	        $weblink = is_multisite()? get_site_url() : home_url();
			$data = array("user_token"=>$auth_settings['user_token'],
						"audience_token_id"=>$token_ids,
						"title"=>wp_strip_all_tags($title),
						"message"=> wp_strip_all_tags($message),
						"link_url"=>$link_url,
						"icon_url"=> $icon_url,
						"image_url"=> $image_url,
						"website"=>   $weblink,
						);
			$postdata = array('body'=> $data);
			$remoteResponse = wp_remote_post($verifyUrl, $postdata);

			if( is_wp_error( $remoteResponse ) ){
				$remoteData = array('status'=>401, "response"=>esc_html__('could not connect to server', 'push-notification'));
			}else{
				$remoteData = wp_remote_retrieve_body($remoteResponse);
				$remoteData = json_decode($remoteData, true);
			}
		}//auth token check 
	}

	public function pn_get_layout_files($filePath){
	    $fileContentResponse = @wp_remote_get(esc_url_raw(PUSH_NOTIFICATION_PLUGIN_URL.'assets/'.$filePath));
	    if(wp_remote_retrieve_response_code($fileContentResponse)!=200){
	      if(!function_exists('get_filesystem_method')){
	        require_once( ABSPATH . 'wp-admin/includes/file.php' );
	      }
	      $access_type = get_filesystem_method();
	      if($access_type === 'direct')
	      {
	      	$file = PUSH_NOTIFICATION_PLUGIN_DIR.('assets/'.$filePath);
	        $creds = request_filesystem_credentials($file, '', false, false, array());
	        if ( ! WP_Filesystem($creds) ) {
	          return false;
	        }   
	        global $wp_filesystem;
	        $htmlContentbody = $wp_filesystem->get_contents($file);
	        return $htmlContentbody;
	      }
	      return false;
	    }else{
	      return wp_remote_retrieve_body( $fileContentResponse );
	    }
	}

	/**
	* show notices if API is not entered in option panel
	*/
	function admin_notices_opt(){
		global $pagenow;
		$auth_settings = push_notification_auth_settings();

		if ( ! isset( $auth_settings['user_token'] ) || ( isset( $auth_settings['user_token'] ) && empty( $auth_settings['user_token'] ) ) ){

	         echo sprintf('<div class="notice notice-warning is-dismissible">
				             <p>%s <a href="%s">%s</a>.</p>
				         </div>',
				         esc_html__( 'Push Notification is require API, Please enter', 'push-notification' ),
				         esc_url( admin_url( 'admin.php?page=push-notification' ) ),
				         esc_html__( 'API key', 'push-notification' )
				     );

	    }
	}

	public function pn_subscribe_newsletter() {

		if ( empty( $_POST['nonce'] ) ) {

			wp_send_json( array( "status" => 503, "message" => esc_html__( 'Request not authorized', 'push-notification' ) ) );

		}

		if ( isset( $_POST['nonce'] ) &&  ! wp_verify_nonce( $_POST['nonce'], 'pn_notification' ) ) {

			wp_send_json( array( "status" => 503, "message" => esc_html__( 'Request not authorized', 'push-notification' ) ) );

		}

		if ( ! current_user_can( 'manage_options' ) ) {

			wp_send_json( array( "status" => 503, "message" => esc_html__( 'Request not authorized', 'push-notification' ) ) );

		}
		    $api_url 	= 'http://magazine3.company/wp-json/api/central/email/subscribe';
			$name 		=  isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';
			$email 		=  isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
			$website 	=  isset( $_POST['website'] ) ? sanitize_url( $_POST['website'] ) : '';

		    $api_params = array(
		        'name'    => $name,
		        'email'	  => $email,
		        'website' => $website,
		        'type'    => 'notification'
		    );

		    $response = wp_remote_post( $api_url, array( 'timeout' => 15, 'sslverify' => false, 'body' => $api_params ) );

			if ( ! is_wp_error( $response ) ) {

				$response = wp_remote_retrieve_body( $response );
		    	$response = json_decode( $response, true );
		    	echo wp_json_encode( array( 'response' => $response['response'] ) );

			} else {

				$error_message = $response->get_error_message();
				echo wp_json_encode( array( 'response' => $error_message ) );
				
			} 

		    wp_die();		
	}
	
}

$push_Notification_Admin_Obj  = new Push_Notification_Admin(); 

if ( is_admin() || wp_doing_ajax() ) {

	$push_Notification_Admin_Obj->init();

}

//Send push on publish and update 
// Put it here because in Gutenberg is_admin gives us false
add_action( 'transition_post_status', array( $push_Notification_Admin_Obj, 'send_notification_on_update' ), 10, 3 );

function push_notification_settings(){
	$push_notification_settings = get_option( 'push_notification_settings', array() ); 
	$icon = PUSH_NOTIFICATION_PLUGIN_URL.'assets/image/bell-icon.png';
	if(function_exists('pwaforwp_defaultSettings')){
		$pwaforwpSettings = pwaforwp_defaultSettings();
		$icon = $pwaforwpSettings['icon'];
	}
	$default = array(
		'notification_icon' => $icon,		
		'on_publish'=> 1,
		'posttypes'=> array("post","page"),		
		'notification_position'=> 'bottom-left',
		'popup_banner_message'=> esc_html__('Enable Notifications', 'push-notification'),
		'popup_banner_accept_btn'=> esc_html__('OK', 'push-notification'),
		'popup_banner_decline_btn'=> esc_html__('No thanks', 'push-notification'),
		'notification_popup_show_again'=>'30',
		'notification_popup_show_afternseconds'=>'3',
		'notification_popup_show_afternpageview'=>'1',
		'notification_utm_source'=> 'pn-ref',
		'notification_utm_medium'=> 'pn-ref',
		'notification_utm_campaign'=> 'pn-campaign',
		'notification_utm_term'=> 'pn-term',
		'pn_url_capture'=> 'off',
		'pn_url_capture_manual'=>'',
		'popup_display_setings_title_color'=>'',
		'popup_display_setings_no_thanks_color'=>'#5f6368',
		'popup_display_setings_ok_color'=>'#8ab4f8',
		'popup_display_setings_text_color'=>'#fff',
		'popup_display_setings_bg_color'=>'#222',
		'popup_display_setings_border_radius'=>'4',
		'notification_botton_position'=>'top'
	);
	$push_notification_settings = wp_parse_args($push_notification_settings, $default);
	$push_notification_settings = apply_filters("pn_settings_options_array", $push_notification_settings);
	return $push_notification_settings;
}
function push_notification_auth_settings(){
	$push_notification_auth_settings = get_option( 'push_notification_auth_settings', array() ); 
	return $push_notification_auth_settings;
}
function push_notification_details_settings(){
	$push_notification_details_settings = get_option( 'push_notification_details_settings', array() ); 
	return $push_notification_details_settings;
}

/** 
* Server Side fields generation class
*/
class PN_Field_Generator{
	static $settingName = 'push_notification_settings';
	public static function get_input($name, $id="", $class=""){
		$settings = push_notification_settings();
			?><input type="text" name="<?php echo esc_attr(self::$settingName); ?>[<?php echo esc_attr($name); ?>]" class="regular-text" id="<?php echo esc_attr($id); ?>" value="<?php if ( isset( $settings[$name] ) && ( ! empty($settings[$name]) ) ) echo esc_attr($settings[$name]); ?>"/>		
		<?php
		if($name == "notification_icon"){
			?>
			<button type="button" class="button pn_not_icon" data-editor="content">
				<span class="dashicons dashicons-format-image" style="margin-top: 4px;"></span><?php echo esc_html__('Upload an image', 'push-notification'); ?>
			</button>
			<?php 
		}
		if($name == "notification_pop_up_icon"){
			?>
			<button type="button" class="button pn_pop_up_not_icon" data-editor="content">
				<span class="dashicons dashicons-format-image" style="margin-top: 4px;"></span><?php echo esc_html__('Upload an Icon', 'push-notification'); ?>
			</button>
			<?php 
		}
	}
	public static function get_input_number($name, $id="", $class=""){
		$settings = push_notification_settings();
			?><input type="number" name="<?php echo esc_attr(self::$settingName); ?>[<?php echo esc_attr($name); ?>]" class="regular-text" id="<?php echo esc_attr($id); ?>" value="<?php if ( isset( $settings[$name] ) && ( ! empty($settings[$name]) ) ) echo esc_attr($settings[$name]); ?>"/>		
		<?php
	}
	public static function get_input_color($name, $id="", $class=""){
		$settings = push_notification_settings();
			?><input type="text" name="<?php echo esc_attr(self::$settingName); ?>[<?php echo esc_attr($name); ?>]" class="<?php echo esc_attr($class); ?>" id="<?php echo esc_attr($id); ?>" value="<?php if ( isset( $settings[$name] ) && ( ! empty($settings[$name]) ) ) echo esc_attr($settings[$name]); ?>" data-default-color="#effeff"/>		
		<?php
	}
	public static function get_input_category($name, $id="", $class=""){
		$settings = push_notification_settings();
		?><input type="text" name="<?php echo esc_attr(self::$settingName); ?>[<?php echo esc_attr($name); ?>]" class="regular-text" id="<?php echo esc_attr($id); ?>" value="<?php if(isset($settings[$name]) && is_string($settings[$name])) echo esc_attr($settings[$name]); ?>" hidden/><?php
	}
	public static function get_input_checkbox($name, $value, $id="", $class="", $label=''){
		$settings = push_notification_settings();
		if(!isset($settings[$name])){$settings[$name] = 0; }
		?>
		<div class="checkbox_wrapper">
			<input type="checkbox" class="regular-text checkbox_operator" id="<?php echo esc_attr($id); ?>" <?php if ( isset( $settings[$name] ) && $settings[$name]==$value ) echo esc_attr("checked"); ?> value="<?php echo esc_attr($value); ?>"/>
			<input type="hidden" name="<?php echo esc_attr(self::$settingName); ?>[<?php echo esc_attr($name); ?>]" class="regular-text checkbox_target" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($settings[$name]); ?>" data-truevalue="<?php echo esc_attr($value); ?>"/>
			<?php if(!empty($label)){
				echo '<label style="display:inline-block" for="'.esc_attr($id).'">'.esc_html($label).'</label>';
			} ?>
		</div>
		<?php
	}
	public static function get_input_multi_select($name, $value, $options, $id="", $class=""){
		$settings = push_notification_settings();
		if( isset($settings[$name]) ){
			$value = $settings[$name];
		}
		?><select multiple name="<?php echo esc_attr(self::$settingName); ?>[<?php echo esc_attr($name); ?>][]" class="regular-text" id="<?php echo esc_attr($id); ?>" >
			<?php 
				if ( ! empty( $options ) ) {

					foreach ( $options as $key => $opt ) {

						$sel = '';
						if ( isset( $value ) && in_array( $key, $value ) ) {
							$sel = 'selected';
						}

						echo '<option value="'.esc_attr( $key ).'" '.esc_attr( $sel ).'>'.esc_html( $opt ).'</option>';

					} 
				} 
			?>
		</select><?php
	}
	
	public static function get_input_select($name, $value, $options, $id="", $class=""){
		$settings = push_notification_settings();
		if( isset($settings[$name]) ){
			$value = $settings[$name];
		}
		?><select name="<?php echo esc_attr(self::$settingName); ?>[<?php echo esc_attr($name); ?>]" class="regular-text" id="<?php echo esc_attr($id); ?>" >
			<?php 
				foreach ( $options as $key => $opt ) {
					$sel = '';
					if ( isset( $value ) && $key == $value ) {
						$sel = 'selected';
					}

					echo '<option value="'.esc_attr( $key ).'" '.esc_attr( $sel ).'>'.esc_html( $opt ).'</option>';
				} 
			?>
		</select><?php
	}
	// generate a function for textarea 
	public static function get_input_textarea($name, $id="", $placeholder="Enter the details" ,$cols=10 , $rows=1	,$class=""){
		$settings = push_notification_settings();
        if( isset($settings[$name]) ){
            $value = $settings[$name];
        }
        ?><textarea cols="<?php echo esc_attr($cols); ?>" rows="<?php echo esc_attr($rows); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" name="<?php echo esc_attr(self::$settingName); ?>[<?php echo esc_attr($name); ?>]" class="<?php echo esc_attr($class); ?>" id="<?php echo esc_attr($id); ?>" ><?php if(isset($settings[$name]) && is_string($settings[$name])) echo esc_attr($settings[$name]); ?></textarea><?php
    }

	public static function get_input_password($name, $id="", $class=""){
		$settings = push_notification_settings();
		?><input type="password" name="<?php echo esc_attr(self::$settingName); ?>[<?php echo esc_attr($name); ?>]" class="regular-text" id="<?php echo esc_attr($id); ?>" value="<?php if ( isset( $settings[$name] ) && ( ! empty($settings[$name]) ) ) echo esc_attr($settings[$name]); ?>"/><?php
	}
	public static function get_button($name, $id="", $class=""){
		$settings = push_notification_settings();
		?>
		<button type="button"  class="button <?php echo esc_attr($class); ?>" id="<?php echo esc_attr($id); ?>"><?php echo esc_html($name) ?></button>
	<?php
	}  
}
function pn_send_query_message(){   
	if(empty( $_POST['nonce'])){
		return;	
	}
	if( isset( $_POST['nonce']) &&  !wp_verify_nonce($_POST['nonce'], 'pn_notification') ){
		return;	
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;	
	}
        $authData = push_notification_auth_settings();
        if ($authData['token_details']['validated']!=1 ){
           return;  
        }
        $message    = sanitize_textarea_field($_POST['message']);        
        $customer_type    = sanitize_text_field($_POST['customer_type']);        
        $email    = sanitize_email($_POST['email']);        
        $customer_type = empty($customer_type)? $customer_type : 'No';
        $message .= "<table>
        				<tr><td>".esc_html__('Are you existing Premium Customer?','push-notification')."</td><td>".esc_html($customer_type)."</td></tr>
        				<tr><td>".esc_html__('Plugin','push-notification')."</td><td> ". esc_html__('Push Notification', 'push-notification') ."</td></tr>
        				<tr><td>".esc_html__('Version','push-notification')."</td><td>".esc_html(PUSH_NOTIFICATION_PLUGIN_VERSION)."</td></tr>
        			</table>";
        $user       = wp_get_current_user();
        if($user){
            $user_data  = $user->data;        
            $user_email = $user_data->user_email;       
            if($email){
                $user_email = $email;
            } 
            $to = 'team@magazine3.in';
            $subject = 'Push Notification Customer Query';
            $headers = 'From: '. esc_attr($user_email) . "\r\n" .
            'Reply-To: ' . esc_attr($user_email) . "\r\n";
            // Load WP components, no themes.                      
            $sent = wp_mail($to, $subject, wp_strip_all_tags($message), $headers);
            if($sent){
            wp_send_json(array('status'=>'t'));            
            }else{
            wp_send_json(array('status'=>'f'));            
            }            
        }                        
           wp_die();           
}

add_action('push_notification_pro_notifyform_before','push_notification_pro_notifyform_before');
function push_notification_pro_notifyform_before(){
	if(PN_Server_Request::getProStatus()=='inactive'){
		return;
	}
	$notification_settings= push_notification_settings();
	echo '<div class="form-group">
			<label for="notification-send-type">'.esc_html__('Send To','push-notification').'</label>
			<select id="notification-send-type" class="regular-text js_pn_select">
				<option value="">'.esc_html__('All Subscribers','push-notification').'</option>
				<option value="custom-select">'.esc_html__('Select subscribers','push-notification').'</option>
				<option value="custom-upload">'.esc_html__('Upload subscribers list','push-notification').'</option>';

	if ( isset( $notification_settings['pn_url_capture']) && ($notification_settings['pn_url_capture'] == 'auto' || $notification_settings['pn_url_capture'] == 'manual' ) ) {

	 echo '<option value="custom-page-subscribed">'.esc_html__('Select Page','push-notification').'</option>';

	}

	echo '</select>
		  </div>';
		  
		  $users = get_users( array(
								// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
								'meta_key'     => 'pnwoo_notification_token',
							) );

		$today_date = gmdate( 'Y-m-d', strtotime( '+1 day' ) );

		echo '<div class="form-group" style="display:none">
			<label for="notification-custom-select">'.esc_html__('Select Subscribers','push-notification').'</label>
			<select id="notification-custom-select" class="regular-text js_pn_select" placeholder="'.esc_html__('Select Subscribers','push-notification').'" multiple>';
			if(!empty($users)){
				foreach($users as $user){
					echo '<option value="'.esc_attr($user->ID).'">'.esc_attr($user->user_login).' ('.esc_attr($user->user_email).')</option>';			
				}
			}			
		echo' </select>
		  </div>';

		$pn_token_urls = pn_get_all_unique_meta();

		  echo '<div class="form-group" style="display:none">
		  <label for="notification-custom-page-subscribed">'.esc_html__('Select Page Subscribed','push-notification').'</label>
		  <select id="notification-custom-page-subscribed" class="regular-text js_pn_select" placeholder="'.esc_html__('Select Page','push-notification').'">';

		  if ( ! empty( $pn_token_urls ) ) {

			  foreach( $pn_token_urls as $url ) {
				  echo '<option value="'.esc_url($url).'" data-url="'.esc_url(basename($url)).'">'.esc_attr(pn_get_page_title_by_url($url)).'('.esc_url($url).')</option>';			

			  }

		  } else {
			echo '<option value="">'.esc_html__('No Subscribed Page Found','push-notification').'</option>';	
		  }			
	  echo' </select>
		</div>';

		echo '<div class="form-group" style="display:none">
				<label for="notification-custom-upload">'.esc_html__('Upload subscriber list', 'push-notification').'</label>
				<input type="file" id="notification-custom-upload" accept=".csv">
				<p><b>'.esc_html__('Note : CSV should contain user email separated by commas ( , ) notification will be').' <br/>'.esc_html__('send to only emails that has subscribed to push notification','push-notification').' <a target="_blank" href="'.esc_url(PUSH_NOTIFICATION_PLUGIN_URL.'assets/sample.csv').'"
				>'.esc_html__('Sample CSV File','push-notification').'</a></b></p>
			</div>';

		echo '<div class="form-group">
				<label for="notification-schedule">'.esc_html__('Schedule Notification','push-notification').'</label>
				<select id="notification-schedule" class="regular-text js_pn_select">
					<option value="no">'.esc_html__('No','push-notification').'</option>
					<option value="yes">'.esc_html__('Yes','push-notification').'</option>
				</select>
			</div>';
		echo '<div class="form-group" style="display:none" >
			<label for="notification-date">'.esc_html__('Schedule Date Time', 'push-notification').'</label>
			<input class="regular-text" type="date" id="notification-date" min="'.esc_attr($today_date).'" style="width:400px">
			<input type="time" id="notification-time">
		</div>';

}

function push_notification_category( $search, $saved_data ) {

	$args = array( 
		'taxonomy'   => 'category',
		'hide_empty' => false,
		'number'     => 50, 
	);

	if ( ! empty( $search ) ) {

		$args['name__like'] = $search;

	}
	
	$get_option = get_terms( $args );

	$only_ids = [];
	$result = [];

	if ( ! empty( $get_option ) && is_array( $get_option ) ) {   

		foreach ( $get_option as $options_array ) {

			$only_ids[] = $options_array->term_id;
			$result[]   = array(
								'id' 	=> $options_array->term_id, 
								'text'  => $options_array->name,
							);
		}
	}

	if ( ! empty( $saved_data ) ) {  
		
		$args['include'] = $saved_data;
		$selected_cats = get_terms($args);

		foreach ( $selected_cats as $options_array ) {

			if ( ! in_array( $options_array->term_id, $only_ids ) ) {

				$result[] = array(
									'id'   => $options_array->term_id, 
									'text' => $options_array->name,
								);

			}
		}
	}

	return $result;

}

function pn_select2_category_data(){

	if ( ! isset( $_GET['nonce'] ) ) {
	  return; 
	}

	if ( isset( $_GET['nonce']) &&  !wp_verify_nonce($_GET['nonce'], 'pn_notification') ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	
	$search        = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';
	$result = push_notification_category($search,[]);
	
	wp_send_json(['results' => $result] );
	wp_die();

}

add_action( 'wp_ajax_pn_select2_category_data', 'pn_select2_category_data' );

// Query to get all unique values of user meta key "pnwoo_notification_token"

function pn_get_all_unique_meta() {

		global $wpdb;		
		$unique_urls   = [];
		$cache_key     = 'pn_unique_tokens_cache_key';

        $unique_tokens = wp_cache_get( $cache_key );

		if ( false === $unique_tokens ) {
			$table_name = $wpdb->prefix.'pn_token_urls';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Reason : Custom table
			$unique_tokens = $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared --Reason : %i modifier is not used because of compatibility issue for WP versions
					"SELECT DISTINCT url FROM {$table_name} WHERE status = %s", 
					'active'
				)
			);

			wp_cache_set( $cache_key, $unique_tokens );

		}		

		if ( $unique_tokens ) {

			foreach ( $unique_tokens as $value ) {

				$is_found = array_search( $value, $unique_urls );
	
				if ( $is_found === false ) {	
	
					$unique_urls[] = $value;
	
				}
	
			}

		}

		return $unique_urls;

	}
	
	function pn_get_page_title_by_url( $page_url = null ) {

		$page_id = url_to_postid( $page_url );

		if ( $page_id ) {
			// Get the page title
			$page_title = get_the_title( $page_id );

			return $page_title;
		} 

		if ( $page_url ==  home_url() ) {

			return esc_html__( 'Home','push-notification' );

		}

		return $page_url;

	}
	
	function pn_get_tokens_by_url( $url ) {

		global $wpdb; 

		$tokens = [];

		$cache_key    = 'saswp_token_cache_key_'.$url;
        $tokens       = wp_cache_get( $cache_key );

		if ( false === $tokens ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$tokens = $wpdb->get_col( $wpdb->prepare( "SELECT token FROM {$wpdb->prefix}pn_token_urls WHERE url = %s", $url ) );
			wp_cache_set( $cache_key, $tokens );
		}
				
		return $tokens;

	}
