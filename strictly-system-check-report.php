<?php

/* 
this is the file that is called to carry out the actual report on your site.
A cron job should be setup on your server to request this page at timed intervals throughout the day.
If you don't have access to your server use one of the many free webcron services available on the web

As the whole point of this plugin is to test whether the wordpress system is running correctly no wordpress
include files are referenced. Not only will this reduce the load at potentially stressful times it means only
the code that is required is loaded in.
*/

require_once(str_replace("\\","/",dirname(__FILE__) . "/strictly-system-check.class.php"));
require_once(str_replace("\\","/",dirname(__FILE__) . "/strictly-system-check-funcs.php"));

error_reporting(E_ERROR);
//error_reporting(0);

if(!defined('DEBUGSYSCHECK')){
	define('DEBUGSYSCHECK',FALSE);
}

ShowSysDebug("IN System Check Report");

$StrictlySystemCheck = StrictlySystemCheck::GetInstance();
	
ShowSysDebug("Load config");

// load in config and check the cron code is correct
if(! $StrictlySystemCheck->LoadPluginConfig()){

	ShowSysDebug("DIE");

	die;
}

ShowSysDebug("Get Cron Code");

// get the cron code
if(!defined('SDB_CRONCODE')){
	ShowSysDebug("DIE");
	die;
}

ShowSysDebug("check params is " . $_REQUEST['code'] . " == " . SDB_CRONCODE);

if(isset($_REQUEST['code']) && $_REQUEST['code'] == SDB_CRONCODE){

	ShowSysDebug("everything okay so run CheckSite report");

	$StrictlySystemCheck->CheckSite();

}

ShowSysDebug("end of system check report page");

/*
 * as I am not loading in any Wordpress files (the site might be down) then I require the following function to output text
 *
 */
function __( $text, $domain = 'default' ) {
	return $text; // all english I'm afraid!
}