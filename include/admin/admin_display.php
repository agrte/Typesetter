<?php
defined('is_running') or die('Not an entry point...');

includeFile('admin/admin_tools.php');

class admin_display extends display{

	public $pagetype				= 'admin_display';
	public $requested				= false;

	public $show_admin_content		= true;
	public $non_admin_content		= '';
	public $admin_html				= '';

	private	$scripts				= array();


	public function __construct($title){
		global $langmessage;


		$this->requested	= str_replace(' ','_',$title);
		$this->label		= $langmessage['administration'];
		$this->scripts		= admin_tools::AdminScripts();

		$this->head .= "\n".'<meta name="robots" content="noindex,nofollow" />';
		@header( 'X-Frame-Options: SAMEORIGIN' );
	}


	public function RunScript(){

		ob_start();
		$this->RunAdminScript();
		$this->contentBuffer = ob_get_clean();


		//display admin area in full window?
		if( $this->FullDisplay() ){
			$this->get_theme_css = false;
			$_REQUEST['gpreq'] = 'admin';
		}
	}

	//display admin area in full window
	private function FullDisplay(){

		if( common::RequestType() == 'template'
			&& $this->show_admin_content
			){
				return true;
		}

		return false;
	}


	//called by templates
	public function GetContent(){

		$this->GetGpxContent();

		if( !empty($this->non_admin_content) ){
			echo '<div class="filetype-text cf">';
			//echo '<div id="gpx_content" class="filetype-text">'; //id="gpx_content" conflicts with admin content
			echo $this->non_admin_content;
			echo '</div>';
		}

		echo '<div id="gpAfterContent">';
		gpOutput::Get('AfterContent');
		gpPlugin::Action('GetContent_After');
		echo '</div>';
	}

	public function GetGpxContent($ajax = false){
		global $gp_admin_html;

		if( empty($this->show_admin_content) ){
			return;
		}

		$request_type = common::RequestType();
		if( $request_type == 'body' ){
			echo $this->contentBuffer;
			return;
		}

		ob_start();
		echo '<div id="gpx_content"><div id="admincontent">';
		$this->AdminContentPanel();
		$this->BreadCrumbs();
		echo '<div id="admincontent_inner">';



		echo $this->contentBuffer;
		echo '</div></div></div>';
		$admin_content = ob_get_clean();

		if( !$ajax ){
			$gp_admin_html .= '<div id="admincontainer" >'.$admin_content.'</div>';
			return;
		}
		echo $admin_content;
	}

	private function BreadCrumbs(){
		global $langmessage;

		echo '<div id="admin_breadcrumbs" class="cf">';

		echo common::Link('',$langmessage['Homepage']);
		echo ' &#187; ';
		echo common::Link('Admin',$langmessage['administration']);



		$parts			= explode('/',$this->requested);
		$crumbs			= array();
		do{
			$crumb_part	= implode('/',$parts);
			if( isset($this->scripts[$crumb_part]) && isset($this->scripts[$crumb_part]['label']) ){
				$crumbs[$crumb_part] = $this->scripts[$crumb_part]['label'];
			}
		}while(array_pop($parts));


		//page label
		$this->label = implode('  &#171; ', $crumbs);


		//add to breadcrumbs
		$crumbs = array_reverse($crumbs);
		foreach($crumbs as $slug => $label){
			echo ' &#187; ';
			echo common::Link($slug,$label);
		}



		echo '</div>';
	}


	/**
	 * Output toolbar for admin window
	 *
	 */
	private function AdminContentPanel(){
		global $langmessage;

		echo '<div id="admincontent_panel" class="toolbar cf">';
		echo '<div id="admin_menu_wrap">';
		gpOutput::GetMenu();
		echo '</div>';


		echo '<form method="get" action="'.common::GetUrl('special_gpsearch').'" id="panel_search" class="cf">';
		echo '<input type="text" value="" name="q">';
		echo '<button class="gpabox" type="submit"></button>';
		echo '</form>';

		echo '</div>';
	}


	/**
	 * Find the requested admin script and execute it if the user has permissions to view it
	 *
	 */
	private function RunAdminScript(){
		global $dataDir,$langmessage;


		if( strtolower($this->requested) == 'admin' ){
			$this->AdminPanel();
			return;
		}


		//resolve request for /Admin_Theme_Content if the request is for /Admin_Theme_Conent/1234
		$request_string		= str_replace('_','/',$this->requested);
		$parts				= explode('/',$request_string);

		do{

			$request_string		= implode('/',$parts);
			$scriptinfo			= $this->GetScriptInfo($request_string);
			if( $scriptinfo ){

				if( admin_tools::HasPermission($request_string) ){

					$this->OrganizeFrequentScripts($request_string);
					gpOutput::ExecInfo($scriptinfo);

					return;
				}

				message($langmessage['not_permitted']);
				$this->AdminPanel();
				return;
			}


			//these are here because they should be available to everyone
			switch($request_string){
				case 'Admin_Browser':
					includeFile('admin/admin_browser.php');
					new admin_browser();
				return;

				case 'Admin_Preferences':
					$this->label = $langmessage['Preferences'];
					includeFile('admin/admin_preferences.php');
					new admin_preferences();
				return;

				case 'Admin_About':
					$this->label = 'About gpEasy';
					includeFile('admin/admin_about.php');
					new admin_about();
				return;

				case 'Admin_Finder':
					if( admin_tools::HasPermission('Admin_Uploaded') ){
						includeFile('thirdparty/finder/connector.php');
						return;
					}
				break;

			}
			array_pop($parts);

		}while( count($parts) );

		//$this->Redirect();
	}


	/**
	 * Get admin script info if the request slug uses underscores or slashes
	 *
	 */
	private function GetScriptInfo($request_string){

		if( isset($this->scripts[$request_string]) ){
			return $this->scripts[$request_string];
		}

		$request_string	= str_replace('/','_',$request_string);

		if( isset($this->scripts[$request_string]) ){
			return $this->scripts[$request_string];
		}

		return false;
	}


	/**
	 * Redirect admin request to the most similar page
	 *
	 */
	private function Redirect(){


		//find similar
		$scripts			= $this->scripts;
		$scripts['Admin']	= array();
		$similar			= array();
		$lower_req			= strtolower($this->requested);

		foreach($scripts as $key => $script_info){
			$lower_key	= strtolower($key);

			similar_text($lower_req,$lower_key,$percent);
			$similar[$key] = $percent;
		}

		arsort($similar);

		$redir_key		= key($similar);
		$location		= common::GetUrl($redir_key,http_build_query($_GET),false);
		common::Redirect($location);
	}


	/**
	 * Show the default admin page
	 *
	 */
	private function AdminPanel(){
		global $langmessage;

		$cmd = common::GetCommand();
		switch($cmd){
			case 'embededcheck':
				includeFile('tool/update.php');
				new update_class('embededcheck');
			return;

			case 'autocomplete-titles':
			$opts = array('var_name'=>false);
			echo gp_edit::AutoCompleteValues(false,$opts);
			die();
		}

		$this->head_js[] = '/include/js/auto_width.js';

		echo '<h2>'.$langmessage['administration'].'</h2>';

		echo '<div id="adminlinks2">';
		admin_tools::AdminPanelLinks(false);
		echo '</div>';
	}


	/**
	 * Increment freq_scripts for $page and sort by counts
	 *
	 */
	private function OrganizeFrequentScripts($page){
		global $gpAdmin;

		if( !isset($gpAdmin['freq_scripts']) ){
			$gpAdmin['freq_scripts'] = array();
		}
		if( !isset($gpAdmin['freq_scripts'][$page]) ){
			$gpAdmin['freq_scripts'][$page] = 0;
		}else{
			$gpAdmin['freq_scripts'][$page]++;
			if( $gpAdmin['freq_scripts'][$page] >= 10 ){
				$this->CleanFrequentScripts();
			}
		}

		arsort($gpAdmin['freq_scripts']);
	}


	/**
	 * Reduce the number of scripts in freq_scripts
	 *
	 */
	private function CleanFrequentScripts(){
		global $gpAdmin;

		//reduce to length of 5;
		$count = count($gpAdmin['freq_scripts']);
		if( $count > 3 ){
			for($i=0;$i < ($count - 5);$i++){
				array_pop($gpAdmin['freq_scripts']);
			}
		}

		//reduce the hit count on each of the top five
		$min_value = end($gpAdmin['freq_scripts']);
		foreach($gpAdmin['freq_scripts'] as $page => $hits){
			$gpAdmin['freq_scripts'][$page] = $hits - $min_value;
		}
	}


}
