<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

class Push_Notification_Frontend{
	public $notificatioArray = array("gcm_sender_id"=> "103953800507");
	public function __construct(){
		$this->init();
	}

	public function pn_pwa_manifest_config(){
		return array(
			"gcm_sender_id"=> "103953800507"
		);
	}
	public function pn_manifest_config(){
		return array(
			"gcm_sender_id"=> "103953800507",
			"start_url"=> "/",
			"name"=> get_bloginfo( 'name' ),
			"display"=> "standalone"
		);
	}

	public function init(){
		$auth_settings = push_notification_auth_settings();
		if(empty($auth_settings)
			|| !isset($auth_settings['user_token']) 
			|| !isset($auth_settings['messageManager'])
			|| (isset($auth_settings['messageManager'])
				&& empty( $auth_settings['messageManager'])
				)
		){
        	return false;
        }
		if( function_exists('pwaforwp_init_plugin') ){
			$addNotification = false;
			if( function_exists('pwaforwp_defaultSettings') ) {
				$pwaSettings = pwaforwp_defaultSettings();
				if( $pwaSettings['notification_feature']==1 && isset($pwaSettings['notification_options']) && $pwaSettings['notification_options']=='pushnotifications_io'){
					$addNotification = true;
				}
			}
			if($addNotification){
				add_filter( 'pwaforwp_manifest', array($this, 'manifest_add_gcm_id') );

				add_action("wp_enqueue_scripts", array($this, 'pwaforwp_enqueue_pn_scripts'), 34 );
				add_action("wp_footer", array($this, 'pn_notification_confirm_banner'), 34 );
			}
		}elseif(function_exists('superpwa_addons_status')){
			add_filter( 'superpwa_manifest', array($this, 'manifest_add_gcm_id') );
			add_action("wp_enqueue_scripts", array($this, 'superpwa_enqueue_pn_scripts'), 34 );
			add_action("wp_footer", array($this, 'pn_notification_confirm_banner'), 34 );
			add_filter( "superpwa_sw_template", array($this, 'superpwa_add_pn_swcode'),10,1);
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reasone: Not processing form
		}elseif(function_exists('amp_is_enabled') && amp_is_enabled() && empty($_GET['noamp'])){			
			add_action( 'rest_api_init', array( $this, 'register_manifest_rest_route' ) );
			add_action("wp_footer", array($this, 'header_content'));
			add_action("wp_footer", array($this, 'amp_header_button_css'));
		}else{
			//manifest
			add_action('wp_head',array($this, 'manifest_add_homescreen'),1);
			add_action("wp_footer", array($this, 'pn_notification_confirm_banner'), 34 );
			//create manifest
			add_action( 'rest_api_init', array( $this, 'register_manifest_rest_route' ) );
			//ServiceWorker
			add_action("wp_enqueue_scripts", array($this, 'enqueue_pn_scripts') );


		}
		//firebase serviceworker
		add_action( 'parse_query', array($this, 'load_service_worker') );

		
		add_action( 'init', array($this, 'sw_template_query_var') );

		add_action( 'wp_ajax_pn_register_subscribers', array( $this, 'pn_register_subscribers' ) ); 
		add_action( 'wp_ajax_nopriv_pn_register_subscribers', array( $this, 'pn_register_subscribers' ) );

		//click event
		add_action( 'wp_ajax_pn_noteclick_subscribers', array( $this, 'pn_noteclick_subscribers' ) );
		add_action( 'wp_ajax_nopriv_pn_noteclick_subscribers', array( $this, 'pn_noteclick_subscribers' ) );
		//AMP Connect
		add_action( "pre_amp_render_post", array($this, 'amp_entry_gate') );

		 if( function_exists('ampforwp_get_setting') && ampforwp_get_setting('amp-mobile-redirection') && wp_is_mobile() ){
		 	add_action('template_redirect', array($this, 'page_redirect'), 9);
		 }else{
		 	add_filter('template_include', array($this, 'page_include'), 1, 1);
		 }

		//Woocommerce order status Compatibility
		//Store token ID
		add_action('pn_tokenid_registration_id', array($this, 'store_user_registered_tokens'), 10, 6);

		//set transient to detect user login so that we can update the token id
		add_action('wp_login', array($this, 'after_login_transient'), 10, 2); 
		 // force token update if login is detected
		add_filter('pn_token_exists', array($this, 'pn_token_exists'), 10, 1);
	}
	public static function update_autoptimize_exclude( $values, $option ){
		if(!stripos($values, PUSH_NOTIFICATION_PLUGIN_URL.'assets/public/application.min.js')){
			$values .= ", ".PUSH_NOTIFICATION_PLUGIN_URL.'assets/public/application.min.js';
		}
		if(!stripos($values, PUSH_NOTIFICATION_PLUGIN_URL.'assets/public/messaging.min.js')){
			$values .= ", ".PUSH_NOTIFICATION_PLUGIN_URL.'assets/public/messaging.min.js';
		}


		return $values;
	}
	function sw_template_query_var(){
		global $wp;
		 $wp->add_query_var( 'push_notification_sw' );
		 $wp->add_query_var( 'push_notification_amp_js' );
		 $wp->add_query_var( 'subscribe_pushnotification' );

		 add_rewrite_rule('subscribe/pushnotification/?$', 
					'index.php?subscribe_pushnotification=1','top');
	}

	function load_service_worker(WP_Query $query ){

		if ( $query->is_main_query() && $query->get( 'push_notification_sw' ) ) {

			header("Service-Worker-Allowed: /");
			header("Content-Type: application/javascript");
			header('Accept-Ranges: bytes');
			$messagesw_escaped = $this->pn_get_layout_files('messaging-sw.js');
			$settings = $this->json_settings();
			$messagesw_escaped = str_replace('{{pnScriptSetting}}', wp_json_encode($settings), $messagesw_escaped);
			/* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped  */
			echo $messagesw_escaped;
            exit;

		}

		if ( $query->is_main_query() && $query->get( 'push_notification_amp_js' ) ) {

			header("Content-Type: application/javascript");
			header('Accept-Ranges: bytes');
			$messagesw_escaped = $this->pn_get_layout_files('messaging-sw.js');
			$settings = $this->json_settings();
			$messagesw_escaped = str_replace('{{pnScriptSetting}}', wp_json_encode($settings), $messagesw_escaped);
			/* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped  */
			echo $messagesw_escaped;
            exit;

		}

	}

	public function pn_get_layout_files($filePath){

	    $fileContentResponse = @wp_remote_get(esc_url_raw(PUSH_NOTIFICATION_PLUGIN_URL.'assets/'.$filePath));

	    if ( wp_remote_retrieve_response_code( $fileContentResponse ) != 200 ) {

	      if( ! function_exists('get_filesystem_method' ) ){
	        require_once( ABSPATH . 'wp-admin/includes/file.php' );
	      }

	      $access_type = get_filesystem_method();

	      if ( $access_type === 'direct' ) {
	      
			$file = PUSH_NOTIFICATION_PLUGIN_DIR.'assets/'.$filePath;
	        $creds = request_filesystem_credentials( $file, '', false, false, array() );

	        if ( ! WP_Filesystem($creds) ) {
	          return false;
	        }   

	        global $wp_filesystem;
	        $htmlContentbody = $wp_filesystem->get_contents($file);
	        return $htmlContentbody;

	      }

	      return false;

	    } else {

	      return wp_remote_retrieve_body( $fileContentResponse );
		  
	    }
	}

	public function json_settings(){
		if ( is_multisite() ) {
            $link = get_site_url();              
        }
        else {
            $link = home_url();
        }    
        $auth_settings = push_notification_auth_settings();
		$pn_Settings = push_notification_settings();
		$messageConfig = '';
		$pn_token_exists = 1;
        if(isset($auth_settings['user_token']) && isset($auth_settings['token_details']['validated']) && $auth_settings['token_details']['validated'] == 1){
        	$messageConfig = json_decode($auth_settings['messageManager'], true);
        }
		$pn_current_user_id=get_current_user_id()?get_current_user_id():0;
		if($pn_current_user_id>0){
			$pn_user_token = get_user_meta($pn_current_user_id, 'pnwoo_notification_token',true);
			if(is_array($pn_user_token) && !empty($pn_user_token)){
				$pn_user_token = array_filter($pn_user_token);
				$pn_user_token = array_unique($pn_user_token);
				if(count($pn_user_token) == 0){
					$pn_token_exists=0;
				}
			}
			if(!$pn_user_token || (is_array($pn_user_token) && empty($pn_user_token)))
			{
				$pn_token_exists=0;
			}
		}
        $settings = array(
					'nonce' =>  wp_create_nonce("pn_notification"),
					'pn_config'=> $messageConfig,
					"swsource" => esc_url_raw(trailingslashit($link)."?push_notification_sw=1"),
					"scope" => esc_url_raw(trailingslashit($link)),
					"ajax_url"=> esc_url_raw(admin_url('admin-ajax.php')),
					"cookie_scope"=>esc_url_raw(apply_filters('push_notification_cookies_scope', "/")),
					'notification_popup_show_again'=>$pn_Settings['notification_popup_show_again'],
					'popup_show_afternseconds'=> $pn_Settings['notification_popup_show_afternseconds'],
					'popup_show_afternpageview'=> $pn_Settings['notification_popup_show_afternpageview'],
					'pn_token_exists' =>apply_filters('pn_token_exists',$pn_token_exists),
					);
        return $settings;
	}


	public function enqueue_pn_scripts(){
		wp_enqueue_script('pn-script-app-frontend', PUSH_NOTIFICATION_PLUGIN_URL.'assets/public/application.min.js', array(), PUSH_NOTIFICATION_PLUGIN_VERSION, true);

		wp_enqueue_script('pn-script-analytics', PUSH_NOTIFICATION_PLUGIN_URL.'assets/public/analytics.js', array('pn-script-app-frontend'), PUSH_NOTIFICATION_PLUGIN_VERSION, true);
		$data = "window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());";
		wp_add_inline_script('pn-script-analytics', $data, 'after');

		wp_enqueue_script('pn-script-messaging-frontend', PUSH_NOTIFICATION_PLUGIN_URL.'assets/public/messaging.min.js', array('pn-script-app-frontend'), PUSH_NOTIFICATION_PLUGIN_VERSION, true);
		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';	
		wp_enqueue_script('pn-script-frontend', PUSH_NOTIFICATION_PLUGIN_URL."assets/public/app{$min}.js", array('pn-script-app-frontend','pn-script-messaging-frontend'), PUSH_NOTIFICATION_PLUGIN_VERSION, true);
		$settings = $this->json_settings();
		wp_localize_script('pn-script-app-frontend', 'pnScriptSetting', $settings);
	}
	public function pwaforwp_enqueue_pn_scripts(){
		wp_enqueue_script('pn-script-app-frontend', PUSH_NOTIFICATION_PLUGIN_URL.'assets/public/application.min.js', array(), PUSH_NOTIFICATION_PLUGIN_VERSION, true);

		wp_enqueue_script('pn-script-analytics', PUSH_NOTIFICATION_PLUGIN_URL.'assets/public/analytics.js', array('pn-script-app-frontend'), PUSH_NOTIFICATION_PLUGIN_VERSION, true);
		$data = "window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag('js', new Date());";
		wp_add_inline_script('pn-script-analytics', $data, 'after');
		wp_enqueue_script('pn-script-messaging-frontend', PUSH_NOTIFICATION_PLUGIN_URL.'assets/public/messaging.min.js', array('pn-script-app-frontend'), PUSH_NOTIFICATION_PLUGIN_VERSION, true);
		$settings = $this->json_settings();
		wp_localize_script('pn-script-app-frontend', 'pnScriptSetting', $settings);
	}

	
	public function superpwa_enqueue_pn_scripts(){
		wp_enqueue_script('pn-script-app-frontend', PUSH_NOTIFICATION_PLUGIN_URL.'assets/public/application.min.js', array(), PUSH_NOTIFICATION_PLUGIN_VERSION, true);
		wp_enqueue_script('pn-script-analytics', PUSH_NOTIFICATION_PLUGIN_URL.'assets/public/analytics.js', array('pn-script-app-frontend'), PUSH_NOTIFICATION_PLUGIN_VERSION, true);
		$data = "window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag('js', new Date());";
		wp_add_inline_script('pn-script-analytics', $data, 'after');
		wp_enqueue_script('pn-script-messaging-frontend', PUSH_NOTIFICATION_PLUGIN_URL.'assets/public/messaging.min.js', array('pn-script-app-frontend'), PUSH_NOTIFICATION_PLUGIN_VERSION, true);
		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_enqueue_script('pn-script-frontend', PUSH_NOTIFICATION_PLUGIN_URL."assets/public/app-pwaforwp{$min}.js", array('pn-script-app-frontend','pn-script-messaging-frontend'), PUSH_NOTIFICATION_PLUGIN_VERSION, true);
		$settings = $this->json_settings();
		wp_localize_script('pn-script-app-frontend', 'pnScriptSetting', $settings);
	}
	public function manifest_add_homescreen(){
		echo '<link rel="manifest" href="'. esc_url( $this->urls_https( rest_url( 'push-notification/v2/pn-manifest-json' ) ) ).'">';
	}

	public function urls_https( $url ) {
           return str_replace( 'http://', 'https://', $url );
	}

	public function register_manifest_rest_route() {
        $rest_namepace = 'push-notification/v2';
        $route = 'pn-manifest-json';
        register_rest_route(
            $rest_namepace,
            $route,
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_manifest' ),
                'permission_callback' => array( $this, 'rest_permission' ),
            )
        );
    }
    public function rest_permission( WP_REST_Request $request ) {
        if ( 'edit' === $request['context'] ) {
            return new WP_Error( 'rest_forbidden_context', esc_html__( 'Sorry, you are not allowed to edit the manifest.', 'push-notification' ), array( 'status' => rest_authorization_required_code() ) );
        }
        return true;
    }
    public function get_manifest($request){
    	$array = $this->pn_manifest_config();
        return $array;
    }

    public function manifest_add_gcm_id($manifest){
		if(is_array($this->pn_pwa_manifest_config()) && !empty($this->pn_pwa_manifest_config())){
    		$manifest = array_merge($manifest, $this->pn_pwa_manifest_config());
		}
    	return $manifest;
    }

    public function pn_register_subscribers(){

		if(empty( $_POST['nonce'])){
			wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
		}
		if( isset( $_POST['nonce']) &&  !wp_verify_nonce($_POST['nonce'], 'pn_notification') ){
			wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
		}
			$token_id = sanitize_text_field($_POST['token_id']);
			$user_agent = sanitize_text_field($_POST['user_agent']);
			$category = sanitize_text_field($_POST['category']);
			$os = sanitize_text_field($_POST['os']);
			$url = isset($_POST['url'])?sanitize_url($_POST['url']):'';
			$ip_address = $this->get_the_user_ip();
			if(empty($token_id)){
				wp_send_json(array("status"=> 503, 'message'=>esc_html__('token_id is blank', 'push-notification')));
			}
			if(empty($user_agent)){
				wp_send_json(array("status"=> 503, 'message'=>esc_html__('user_agent is blank', 'push-notification')));
			}
			if(empty($os)){
				wp_send_json(array("status"=> 503, 'message'=>esc_html__('OS is blank', 'push-notification')));
			}
			if ($user_agent == 'undefined') {
				$user_agent = $this->check_browser_type();
			}
			$response = PN_Server_Request::registerSubscribers($token_id, $user_agent, $os, $ip_address, $category);
			do_action("pn_tokenid_registration_id", $token_id, $response, $user_agent, $os, $ip_address, $url);
			wp_send_json($response);
		
	}

	public function pn_noteclick_subscribers(){
		if(empty( $_POST['nonce'])){
			wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
		}
		if( isset( $_POST['nonce']) &&  !wp_verify_nonce($_POST['nonce'], 'pn_notification') ){
			wp_send_json(array("status"=> 503, 'message'=>esc_html__('Request not authorized', 'push-notification')));
		}
			$campaign = sanitize_text_field($_POST['campaign']);
			if(empty($campaign)){
				wp_send_json(array("status"=> 503, 'message'=>'Campaign is blank'));
			}
			$response = PN_Server_Request::sendPushNotificatioClickData($campaign);
			wp_send_json($response);
		
	}

	public function get_the_user_ip() {
		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			//check ip from share internet
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			//to check ip is pass from proxy
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			$ip = $_SERVER['REMOTE_ADDR'];
		}
		return $ip;
	}

	/**
	* Amp Entry point
	*/
	public function amp_entry_gate(){
		if( !function_exists('pwaforwp_init_plugin') ){
			add_action('amp_post_template_head',array($this, 'manifest_add_homescreen'),1);
		}else{
			global $pwaServiceWorker;
			remove_action('amp_wp_template_footer',array($pwaServiceWorker, 'pwaforwp_service_worker'));
			remove_action('amp_post_template_footer',array($pwaServiceWorker, 'pwaforwp_service_worker'));
            remove_filter('amp_post_template_data',array($pwaServiceWorker, 'pwaforwp_service_worker_script'),35);
		}
		add_action("ampforwp_after_header", array($this, 'header_content'));
		add_action("amp_post_template_css", array($this, 'header_button_css'));
	}

	function page_redirect(){
		global $wp_query;
    	if((isset($wp_query->query['pagename']) && $wp_query->query['pagename']=='subscribe/pushnotification') || (isset($wp_query->query['subscribe_pushnotification']) && $wp_query->query['subscribe_pushnotification']==1)){
    		$template = PUSH_NOTIFICATION_PLUGIN_DIR.'/inc/frontend/amp-pn-subscribe.php';
    		if(file_exists($template)){
	    		require_once $template;
				exit;
    		}
    	}
	}

	function page_include($template){
		global $wp_query;
    	if((isset($wp_query->query['pagename']) && $wp_query->query['pagename']=='subscribe/pushnotification') || (isset($wp_query->query['subscribe_pushnotification']) && $wp_query->query['subscribe_pushnotification']==1) ||(isset($wp_query->query['attachment']) && $wp_query->query['attachment']=="pushnotification")){
    		$template = PUSH_NOTIFICATION_PLUGIN_DIR.'inc/frontend/amp-pn-subscribe.php';
    	}
    	return $template;
	}

	function header_button_css(){
		echo '.pushnotification-class{width:100%; position: fixed;bottom: 55px;left:10px;z-index: 99;}
		.pushnotification-class a{background-color: #0062cc;padding: .5rem 1rem;border-radius: 23px;color: white;}
		.pushnotification-class a:hover{color: white;}
		.pushnotification-class a:before{
			content:"";
			background: url(\''.esc_attr( PUSH_NOTIFICATION_PLUGIN_URL ).'assets/image/bell.png\');
		  	width: 24px;
		    height: 20px;
		    background-repeat: no-repeat;
		    display: inline-block;
		    background-size: 20px;
		    position: relative;
		    top: 4px;
		}
				/* On screens that are 600px or less, set the background color to olive */
		@media screen and (max-width: 600px) {
		  .pushnotification-class a span{display:none;}
		  .pushnotification-class a {
			    background-color: #0062cc;
			    padding: 11px 13px 12px 13px;
			    border-radius: 100%;
			    color: #fff;
			    display: inline-block;
			}
		  .pushnotification-class a:before{
			width: 25x;
		    height: 25px;
		    background-size: 25px;
		    top: 0;

		  }
		}

		';
	}
	/**
	* Return true other then IOS
	* Return false on IOS IPAD Iphone
	*/
	public function check_browser_type(){
		$user_agent_name ='others';           
            if     (strpos($_SERVER['HTTP_USER_AGENT'], 'Opera') || strpos($_SERVER['HTTP_USER_AGENT'], 'OPR/')) $user_agent_name = 'opera';
            elseif (strpos($_SERVER['HTTP_USER_AGENT'], 'Edge'))    $user_agent_name = 'edge';            
            elseif (strpos($_SERVER['HTTP_USER_AGENT'], 'Firefox')) $user_agent_name ='firefox';
            elseif (strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE') || strpos($_SERVER['HTTP_USER_AGENT'], 'Trident/7')) $user_agent_name = 'internet_explorer';                        
            elseif (stripos($_SERVER['HTTP_USER_AGENT'], 'iPod')) $user_agent_name = 'ipod';
            elseif (stripos($_SERVER['HTTP_USER_AGENT'], 'iPhone')) $user_agent_name = 'iphone';
            elseif (stripos($_SERVER['HTTP_USER_AGENT'], 'iPad')) $user_agent_name = 'ipad';
            elseif (stripos($_SERVER['HTTP_USER_AGENT'], 'Android')) $user_agent_name = 'android';
            elseif (stripos($_SERVER['HTTP_USER_AGENT'], 'webOS')) $user_agent_name = 'webos';
            elseif (strpos($_SERVER['HTTP_USER_AGENT'], 'Chrome'))  $user_agent_name = 'chrome';
            elseif (strpos($_SERVER['HTTP_USER_AGENT'], 'Safari'))  $user_agent_name = 'safari';
            return $user_agent_name;
	}

	function header_content(){
		if(in_array($this->check_browser_type(), array('ipod','iphone', 'ipad', 'internet_explorer')) ){
			return false;
		}
		if(isset($_COOKIE['notification_permission']) && $_COOKIE['notification_permission']=='granted'){
			return ;
		}
		if ( is_multisite() ) {
            $link = get_site_url();
        }
        else {
            $link = home_url();
        }
		?>
		<div class="pushnotification-class">
			<a class="" target="_blank" href="<?php echo esc_url_raw($link."/subscribe/pushnotification?noamp=available")?>">
				<span><?php
			echo esc_html__('Subscribe for notification', 'push-notification');
			?></span></a>
		</div>
		<?php
	}
	function pn_notification_confirm_banner(){
		$settings = push_notification_settings();
		$position = "";
		if (isset($settings['notification_position']) && !empty($settings['notification_position'])) {
			$position = $settings['notification_position'];
		}
		$css_position_escaped = '';
		$setting_category = !empty($settings['category'])? $settings['category'] : [];
		$selected_category =  !is_array($setting_category) ? explode(',',$setting_category) : $setting_category;
		$catArray = !is_array($selected_category) ? explode(',',$selected_category) : $selected_category;
		$all_category = (isset($settings['segment_on_category'])) ? $settings['segment_on_category'] : 0;
		
		switch ($position) {
			case 'bottom-left':
				$css_position_escaped = 'bottom: 0;
		    left: 0;
		    margin: 20px;
		    right: auto;
		    top: auto;';
				break;
			case 'bottom-right':
				$css_position_escaped = 'bottom: 0;
		    left: auto;
		    margin: 20px;
		    right: 0;
		    top: auto;';
				break;
			case 'top-right':
				$css_position_escaped = 'bottom: auto;
		    left: auto;
		    margin: 20px;
		    margin-top: 40px;
		    right: 0;
		    top: 0;';
				break;
			case 'top-left':
				$css_position_escaped = 'bottom: auto;
						    left: 0;
						    margin: 20px;
						    margin-top: 40px;
						    right: auto;
						    top: 0;';
				break;
			default:
				$css_position_escaped = 'bottom: 0;
		    left: 0;
		    margin: 20px;
		    right: auto;
		    top: auto;';
				break;
		}
		$is_pro = (PN_Server_Request::getProStatus()=='active')?true:false;
		$custom_bg_color = (isset($settings['popup_display_setings_bg_color']) && $is_pro)?$settings['popup_display_setings_bg_color']:'#222';
		$custom_txt_color = (isset($settings['popup_display_setings_title_color'])&& $is_pro)?$settings['popup_display_setings_title_color']:'#fff';
		$activate_btn_color = (isset($settings['popup_display_setings_ok_color'])&& $is_pro)?$settings['popup_display_setings_ok_color']:'#8ab4f8';
		$decline_btn_color = (isset($settings['popup_display_setings_no_thanks_color'])&& $is_pro)?$settings['popup_display_setings_no_thanks_color']:'#5f6368';
		$border_radius =  (isset($settings['popup_display_setings_border_radius'])&& $is_pro)?$settings['popup_display_setings_border_radius']:'4';
		echo '<style>.pn-wrapper{
			box-shadow: 0 1px 3px 0 rgba(60,64,67,0.302), 0 4px 8px 3px rgba(60,64,67,0.149);
		    font-size: 14px;
		    align-items: center;
		    background-color: '.esc_attr($custom_bg_color).';
		    border: none;
		    border-radius: '.esc_attr($border_radius).'px;
		    box-sizing: border-box;
		    color: #fff;
		    display: none;
		    flex-wrap: wrap;
		    font-weight: 400;
		    padding: 16px 22px;
		    z-index:99999;
		    text-align: left;
		    position: fixed;
		    '. /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static values  */ $css_position_escaped.'
		}
.pn-wrapper .pn-txt-wrap {
    display: flex;
    flex-wrap: wrap;
    position: relative;
    height: auto;
    line-height: 1.5;
	color:'.esc_attr($custom_txt_color).';
	max-width:400px;
}
.pn-wrapper .btn.act{color: '.esc_attr($activate_btn_color).';}
.pn-wrapper .btn{
	align-items: center;
    border: none;
    display: inline-flex;
    outline: none;
    position: relative;
    font-size: 14px;
    background: none;
    border-radius: 4px;
    box-sizing: border-box;
    color: '.esc_attr($decline_btn_color).';
    cursor: pointer;
    font-weight: 500;
    outline: none;
    margin-left: 8px;
    min-width: auto;
    padding: 0 8px;
    text-decoration: none;
}
.pn-txt-wrap.pn-select-box {
	display: block;
	padding: 5px 15px;
}
.pn-categories-multiselect {
	font-size: 13px;
    margin: 10px 0;
}
#pn-activate-permission-categories {
    background-color: #fff;
    padding: 8px 15px;
    color: #000;
}
#pn-categories-checkboxes label{
    padding-right: 12px;
    text-transform: capitalize;
	cursor:pointer;
}
#pn-categories-checkboxes input{
	margin-right: 3px;
	cursor:pointer;
}
#pn-activate-permission-categories-text {
    padding: 12px 0;
    margin-top: 5px;
    font-size: 12px;
    font-weight: 600;
}
</style><div class="pn-wrapper">';
				if(isset($settings['notification_pop_up_icon']) && !empty($settings['notification_pop_up_icon']) && PN_Server_Request::getProStatus()=='active'){
					echo '<span style=" top: 0; vertical-align: top; "><img src="'.esc_attr($settings['notification_pop_up_icon']).'" style=" max-width: 70px;"></span>';
				}
			   echo '<span class="pn-txt-wrap pn-select-box">
			   		<div class="pn-msg-box">
				   		<span class="pn-msg">'.esc_html($settings['popup_banner_message']).'</span>';
				   		if((isset($settings['notification_botton_position']) && $settings['notification_botton_position'] != 'bottom') || !isset($settings['notification_botton_position'])){
				   			echo '<span class="pn-btns">
				   			<span class="btn act" id="pn-activate-permission_link" tabindex="0" role="link" aria-label="ok link">
				   				'.esc_html($settings['popup_banner_accept_btn']).'
				   			</span>
				   			<span class="btn" id="pn-activate-permission_link_nothanks" tabindex="0" role="link" aria-label="no thanks link">
				   				'.esc_html($settings['popup_banner_decline_btn']).'
				   			</span>
				   		</span>';
				   		}
			   		echo '</div>';
			   		if(!empty($settings['on_category']) && $settings['on_category'] == 1){
				   		echo '<div id="pn-activate-permission-categories-text">
			   				'.esc_html__('On which category would you like to receive?', 'push-notification').'
			   			</div>
				   		<div class="pn-categories-multiselect">
				   			<div id="pn-categories-checkboxes" style="color:'.esc_attr($settings['popup_display_setings_text_color']).'">';
							if($all_category){
			   			 		echo '<label for="pn-all-categories"><input type="checkbox" name="category[]" id="pn-all-categories" value="all" />'.esc_html__('All Categories', 'push-notification').'</label>';
							}
							if(!empty($catArray)){
								foreach ($catArray as $key=>$value) {
									if (is_string($value)) {
										$catslugdata ='';
										if(is_object(get_category($value))){
										$catslugdata = get_category($value)->slug;
										}
										echo '<label for="pn_category_checkbox'.esc_attr($value).'"><input type="checkbox" name="category[]" id="pn_category_checkbox'.esc_attr($value).'" value="'.esc_attr($catslugdata).'" />'.esc_html(get_cat_name($value)).'</label>';
									}
								}
							}
				   			echo '</div>
			   			</div>';

		   			}

					   if(isset($settings['notification_botton_position']) && $settings['notification_botton_position'] == 'bottom' && PN_Server_Request::getProStatus()=='active'){
						echo '<span class="pn-btns" style="float:right;margin-top:20px;">
						<span class="btn act" id="pn-activate-permission_link" tabindex="0" role="link" aria-label="ok link">
							'.esc_html($settings['popup_banner_accept_btn']).'
						</span>
						<span class="btn" id="pn-activate-permission_link_nothanks" tabindex="0" role="link" aria-label="no thanks link">
							'.esc_html($settings['popup_banner_decline_btn']).'
						</span>
					</span>';
					}
			   	echo '</span>
			</div>';
	}


	/**
	 * To store the token in db after allow
	 * @method store_user_registered_tokens
	 * @param  String                       $token_id   Generated token we get as string
	 * @param  Array                        $response   response as in Array
	 * @param  String                       $user_agent Type of browser
	 * @param  String                       $os         optional 
	 * @param  String                       $ip_address optional Grab the client ipaddress dummy
	 * @return Void                                   [description]
	 */
	function store_user_registered_tokens($token_id, $response, $user_agent, $os, $ip_address, $url){
		$userData = wp_get_current_user();
		if(is_object($userData) && isset($userData->ID)){
			$userid = $userData->ID;
		 	$token_ids = get_user_meta($userid, 'pnwoo_notification_token', true);
			$token_ids  = maybe_unserialize($token_ids);
			if(!$token_ids || !is_array($token_ids)){
				$token_ids = array();
			}
		 	$token_ids[] = esc_attr($response['data']['id']);
			$token_ids = array_slice(array_unique($token_ids), -5); // keep only last 5 push token
		 	update_user_meta($userid, 'pnwoo_notification_token', $token_ids);

			
		}

		$pn_save_url_token = apply_filters('push_notification_url_tokens',$url,true);
		if($pn_save_url_token){
			$this->pn_add_url_token(esc_url($url),esc_attr($response['data']['id']));
		}
		
	}
	function amp_header_button_css(){
		ob_start();
		$this->header_button_css();
		$css = ob_get_contents();
		ob_clean();
		echo "<style>".esc_attr($css)."</style>";
	}
/*

*/
	public function superpwa_add_pn_swcode($swJsContent)
	{
		header("Service-Worker-Allowed: /");
		header("Content-Type: application/javascript");
		header('Accept-Ranges: bytes');
		$messagesw_escaped = $this->pn_get_layout_files('messaging-sw.js');
		$settings = $this->json_settings();
		$messagesw_escaped = str_replace('{{pnScriptSetting}}', wp_json_encode($settings), $messagesw_escaped);
		$swJsContent .= PHP_EOL.$messagesw_escaped;
		return $swJsContent;
	}

	public function pn_add_url_token($url, $token) {
		global $wpdb;
		// return if url or token is empty
		if(!$url || !$token) {
			return false;
		}
		// return if pro is not active
		$authData = push_notification_auth_settings();
		if( !isset($authData['token_details']['validated']) || (isset($authData['token_details']) && $authData['token_details']['validated']!=1) ){
				return false;
		}
		$settings = push_notification_settings();
		if(isset($settings['pn_url_capture'])){
			if($settings['pn_url_capture'] == 'off'){
				return false;
			}else if($settings['pn_url_capture'] == 'manual'){
				if(isset($settings['pn_url_capture_manual'])){
					$manual_capture = explode(PHP_EOL, $settings['pn_url_capture_manual']);
					foreach ($manual_capture as &$capture) {
						// Remove the trailing slash from the URL
						$capture = rtrim($capture, '/');
					}
					if(empty($manual_capture) || !in_array(rtrim($url, '/'), $manual_capture)){
						return false;
					}
				}else{
					return false;
				}

			}

		}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Reasone: Custom table
			return $wpdb->insert(
				$wpdb->prefix.'pn_token_urls',
				array(
					'url' 		 => esc_url( $this->pn_standardize_url( $url ) ),
					'status' 	 => 'active',
					'token' 	 => $token,
					'created_at' => current_time( 'mysql' ),
					'updated_at' => current_time( 'mysql' )
				)
			);

	}

	public function after_login_transient( $user_login, $user ) {

		$user_id = $user->ID;
		$transient_key = 'pn_token_exists_' . $user_id;
		set_transient( $transient_key, true, 12 * HOUR_IN_SECONDS );

	}

	public function pn_token_exists( $status ) {

		$user_id 			= get_current_user_id();
		$transient_key 		= 'pn_token_exists_' . $user_id;
		$transient_value 	= get_transient( $transient_key );

		if ( $transient_value ) {

			delete_transient( $transient_key );
			
			return 0;

		}

		return $status;

	}

	public function pn_standardize_url($url) {

		$parsedUrl = parse_url($url);

		$standardUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

		if (isset($parsedUrl['port']) && 
		   (($parsedUrl['scheme'] === 'http' && $parsedUrl['port'] != 80) || 
		   ($parsedUrl['scheme'] === 'https' && $parsedUrl['port'] != 443))) {
			$standardUrl .= ':' . $parsedUrl['port'];
		}
	
		if (isset($parsedUrl['path'])) {
			$standardUrl .= $parsedUrl['path'];
		}
	
		return $standardUrl;
	}
}

function push_notification_frontend_class(){
	if(!is_admin() || wp_doing_ajax()){
		$notificationFrontEnd = new Push_Notification_Frontend(); 
	}
	add_filter( "option_autoptimize_js_exclude", array('Push_Notification_Frontend', 'update_autoptimize_exclude') , 10, 2);
}
push_notification_frontend_class();