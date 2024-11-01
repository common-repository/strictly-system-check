<?php

/**
 * Plugin Name: Strictly System Check
 * Version: 1.0.9
 * Plugin URI: http://www.strictly-software.com/plugins/strictly-system-check/
 * Description: This plugin will enable you to setup a cron job to periodically check the status of your wordpress site and auto fix any database related issues. This is useful for sites that import articles reguarly from feeds as you can experience corrupt tables on a regular basis especially after large bulk updates.
 * Author: Rob Reid
 * Author URI: http://www.strictly-software.com 
 * =======================================================================
 */


//error_reporting(E_ALL ^ E_NOTICE);
//error_reporting(0);

if(!defined('DEBUGSYSCHECK')){
	define('DEBUGSYSCHECK',false);
}

class StrictlySystemCheckPlugin{

	public static function Init(){

		ShowSysDebug("IN Init()");

		$StrictlySystemCheck = StrictlySystemCheck::GetInstance();	
		
		// load any language specific text 
		load_textdomain('StrictlySystemCheck', dirname(__FILE__).'/language/'.get_locale().'.mo');

		// add options to admin menu
		add_action('admin_menu', array(&$StrictlySystemCheck,'RegisterAdminPage'));		

		ShowSysDebug("RETURN");
	}


	public static function Activate(){

		$StrictlySystemCheck = StrictlySystemCheck::GetInstance();

		// set up a new key to be used on cron jobs
		$cron_key = substr(md5(time()), 0, 8);

		StrictlyPluginTools::addMissingOptions(array(
			"strictly_system_check_croncode"  => $cron_key,
			"strictly_system_check_uninstall" => false
			));

	}

	public static function Deactivate(){

		$StrictlySystemCheck = StrictlySystemCheck::GetInstance();

		ShowSysDebug("yes delete options");

		if(get_option("strictly_system_check_uninstall")){

			// remove settings from db
			StrictlyPluginTools::deleteOptions(array(
				"strictly_system_check_croncode",
				"strictly_system_check_settings",
				"strictly_system_check_uninstall"
				));
		}
	}

	/**
	 * Loads a file with the require function and handles windows paths
	 *
	 * @param string $file
	 * @param bool $once
	 *
	 */
	public static function RequireFile($file,$once=true){

		if(substr(PHP_OS, 0, 3) == 'WIN'){
			$file = str_replace("\\","/",$file);
		}

		if($once){
			@require_once($file);
		}else{
			@require($file);
		}
	}

	/**
	 * Loads a file with the require function and handles windows paths
	 *
	 * @param string $file
	 * @param bool $once
	 *
	 */
	public static function IncludeFile($file,$once=true){

		ShowSysDebug("IN IncludeFile $file");

		if(substr(PHP_OS, 0, 3) == 'WIN'){
			$file = str_replace("\\","/",$file);
		}

		if($once){
			@include_once($file);
		}else{
			@include($file);
		}
	}
}



StrictlySystemCheckPlugin::RequireFile(dirname(__FILE__) . "/strictly-system-check.class.php");
StrictlySystemCheckPlugin::RequireFile(dirname(__FILE__) . "/strictly-system-check-funcs.php");

// register my activate hook to setup the plugin
register_activation_hook(__FILE__, 'StrictlySystemCheckPlugin::Activate');

// register my deactivate hook to ensure when the plugin is deactivated everything is cleaned up
register_deactivation_hook(__FILE__, 'StrictlySystemCheckPlugin::Deactivate');

// init my object
add_action('init', 'StrictlySystemCheckPlugin::Init');


StrictlySystemCheckPlugin::Init();