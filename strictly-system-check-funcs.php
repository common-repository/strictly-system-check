<?php

if(!defined('StrictlyPluginTools')){
	
	/**
	 * This class holds a number of functions used by Strictly Google Sitemap which are referenced statically
	 */
	class StrictlyPluginTools{
		
		/**
		 * Logs to a debug file which is useful for viewing issues after the fact
		 *
		 * @param stromg $msg
		 */
		function LogDebug($msg){

			if(is_array($msg)){

				$n = date('r') . ': '. StrictlyPluginTools::FormatData($msg);
				
				file_put_contents(dirname(dirname(dirname(dirname(__FILE__)))) . "/cron_debug.log",$n . "\n", FILE_APPEND);
			}else{

				$n = date('r') . ': '. $msg;

				file_put_contents(dirname(dirname(dirname(dirname(__FILE__)))) . "/cron_debug.log",$n . "\n", FILE_APPEND);
			}
		}

		/**
		 * Adds the supplied array of options to Wordpress
		 *
		 * @options array
		 */
		function addOptions($options)
		{
			foreach($options as $option => $var){
				add_option($option, $var); 
			}
			return true;
		} 
		 
		/**
		 * Takes the supplied array of options and adds any that are missing into Wordpress
		 * This is used when upgrades to the component are carried out
		 *
		 * @options array
		 * @return bool
		 */
		function addMissingOptions($options)
		{
			$opt = array();
			
			foreach($options as $option => $vars){
			  if(! get_option($option)) $opt[$option] = $vars;
			}

			return count($opt) ? StrictlyPluginTools::addOptions($opt) : true;
		}

		/**
		 * Takes the supplied array of options and removes them from Wordpress
		 *
		 * @options array
		 */		  
		function deleteOptions($options)
		{
			foreach($options as $option){
				delete_option($option);
			}
		}

		
		
		/**
		 * Converts a formatted string ini file size value such as 128M into an integer containing the number of bytes
		 *
		 * @param string $size
		 * @return int
		 */
		function ConvertToBytes($size) {
			ShowSysDebug("IN ConvertToBytes $size");

			$size = strtoupper(trim($size));
			$size = preg_replace("@B$@","",$size);
			$last = $size{strlen($size)-1};
			
			ShowSysDebug("switch last char = $last");

			switch($last) {				
				case 'K':
					return (int) $size * 1024;
					break;				
				case 'M':
					return (int) $size * 1048576;
					break;
				case 'G':
					return (int) $size * 1073741824;
					break;
				default:
					return $size;
			}
		}

		/**
		 * Converts an integer value containing a number of bytes into a formated value e.g 1024 = 1kb
		 *
		 * @param integer $size
		 * @return string
		 */
		function ConvertFromBytes($size){
			ShowSysDebug("IN ConvertFromBytes $size");
			$unit=array('B','KB','MB','GB','TB','PB');

			return @round($size/pow(1024,($i=floor(log($size,1024)))),2).$unit[$i];
		}
		
		
		/**
		 * Formats a PHP ini type size e.g 20M into standard format e.g 20MB
		 *
		 * @param integer $size
		 * @return string
		 */
		function FormatSize($size){
			ShowSysDebug("IN ConvertFromBytes $size");
			if(!empty($size)){
				if(preg_match("@\d+(?:\.\d+)?[KMGTP]?$@i",$size)){
					ShowSysDebug("matched regex add B");
					return strtoupper($size) . "B";
				}
			}
			return $size;
		}

		/**
		 * Formats datetime for XML if no value is supplied the current datetime is used
		 *
		 * @param datetime $lastmod
		 * @param string $format
		 * @return datetime
		 */
		function FormatLastModDate($lastmod="",$format="ISO"){

			//ShowSysDebug( "date passed in = " . $lastmod . " is it empty");

			// if no value supplied set to current date time
			if(empty($lastmod)){
				 return date('Y-m-d\TH:i:s+00:00',time());
			}else{
				// will either be ISO or UK format. Defaults to ISO
				list($date,$time) = preg_split("/ /",$lastmod);

				// handle dates 10-02-2010 and 10/02/2010
				list($v1,$v2,$v3) = preg_split('/[-\/]/',$date);

				// ensure we have it right way round
				if(($format=="ISO" && strlen($v3)==4) || $format!="ISO"){
					$year	= $v3;
					$month	= $v2;
					$day	= $v1;
				}else{
					$year	= $v1;
					$month	= $v2;
					$day	= $v3;
				}

				if(isset($time)){
					list($hour,$min,$sec) = preg_split('/:/',$time);
				}else{
					$hour=$min=$sec=0;
				}

				 $lastmod = mktime(intval($hour), intval($min), intval($sec), intval($month), intval($day), intval($year));		 
			}

			$lastmod = date('Y-m-d\TH:i:s+00:00',$lastmod);

			return $lastmod;
		}

		/**
		 * Converts a string in the format of Sat, 27 Jun 2009 17:53:15 GMT to 2009-06-27T17:53:15+00:00
		 *
		 * @param string $str
		 * @return date
		 */
		function ConvertFromFileStamp($str){
			ShowSysDebug("IN ConvertFromFileStamp $str");

			// remove the weekday and GMT part
			$str = preg_replace("@^\w+, @","",$str);

			$month = null;
			switch(substr($str, 3, 3)){
				case "Jan": $month = "01"; break;
				case "Feb": $month = "02"; break;
				case "Mar": $month = "03"; break;
				case "Apr": $month = "04"; break;
				case "May": $month = "05"; break;
				case "Jun": $month = "06"; break;
				case "Jul": $month = "07"; break;
				case "Aug": $month = "08"; break;
				case "Sep": $month = "09"; break;
				case "Oct": $month = "10"; break;
				case "Nov": $month = "11"; break;
				case "Dec": $month = "12"; break;
			}
			ShowSysDebug("month is $month");
			$mk = mktime(substr($str, 12, 2), substr($str, 15, 2), substr($str, 18, 2), $month, substr($str, 0, 2), substr($str, 7, 4));

			return date('Y-m-d\TH:i:s+00:00',$mk);
		}

		/**
		 * returns the binary path of supplied programs if possible. Taken from WP-O-Matic
		 *
		 * @param string $program
		 * @param string $append
		 * @param string $fallback
		 * @return string
		 */
		function GetBinaryPath($program, $append = '', $fallback = null)
		{ 
			$win = substr(PHP_OS, 0, 3) == 'WIN';
		
			// enforce API
			if (!is_string($program) || '' == $program) {
				return $fallback;
			}

			// available since 4.3.0RC2
			if (defined('PATH_SEPARATOR')) {
				$path_delim = PATH_SEPARATOR;
			} else {
				$path_delim = $win ? ';' : ':';
			}
			// full path given
			if (basename($program) != $program) {
				$path_elements[]	= dirname($program);
				$program			= basename($program);
			} else {
				// Honour safe mode
				if (!ini_get('safe_mode') || !$path = ini_get('safe_mode_exec_dir')) {
					$path = getenv('PATH');
					if (!$path) {
						$path = getenv('Path'); // some OSes are just stupid enough to do this
					}
				}
				$path_elements = explode($path_delim, $path);
			}

			if ($win) {
				$exe_suffixes = getenv('PATHEXT')
									? explode($path_delim, getenv('PATHEXT'))
									: array('.exe','.bat','.cmd','.com');
				// allow passing a command.exe param
				if (strpos($program, '.') !== false) {
					array_unshift($exe_suffixes, '');
				}
				// is_executable() is not available on windows for PHP4
				$pear_is_executable = (function_exists('is_executable')) ? 'is_executable' : 'is_file';
			} else {
				$exe_suffixes		= array('');
				$pear_is_executable = 'is_executable';
			}

			foreach ($exe_suffixes as $suff) {
				foreach ($path_elements as $dir) {
					$file = $dir . DIRECTORY_SEPARATOR . $program . $suff;
					if (@$pear_is_executable($file)) {
						return $file . $append;
					}
				}
			}
			return $fallback;
		}

		/**
		 * Finds a suitable command to run cron commands with to offer the user
		 *
		 * @return string
		 */
		function GetCommand()
		{
			$commands = array(
			  @StrictlyPluginTools::GetBinaryPath('curl'),
			  @StrictlyPluginTools::GetBinaryPath('wget'),
			  @StrictlyPluginTools::GetBinaryPath('lynx', ' -dump'),
			  @StrictlyPluginTools::GetBinaryPath('ftp')
			);
			
			return StrictlyPluginTools::Pick($commands[0], $commands[1], $commands[2], $commands[3], '<em>{wget or similar command here}</em>');
		}

		/**
		 * pick first non null item from supplied list of arguments
		 *
		 * @return string
		 */
		function Pick()
		{
			$argc = func_num_args();
			for ($i = 0; $i < $argc; $i++) {
				$arg = func_get_arg($i);
				if (! is_null($arg)) {
					return $arg;
				}
			}

			return null;    
		}

		/**
		 * Combination of empty and is_set
		 * 
		 * @param object $obj
		 * @return boolen
		 */
		function IsNothing($obj){
			if(isset($obj)){
				if(!empty($obj)){
					return false;
				}
			}
			return true;
		}
	

		/**
		 * Flattens a multi-dimensional array into a string
		 *
		 * @param array
		 * @return string
		 */
		function FormatData($arr){
		
			if(!is_array($arr)){
				return $arr;
			}

			$i = 1;
			$output = "";
			foreach($arr as $key => $val){
				if(is_array($val)){
					if($i>1){
						$output .= '\n';
					}
					
					$output .= StrictlyPluginTools::FormatData($val);
				}else{
					if($i>1){
						$output .=  '\n';
					}			
					$output .=  $val;			
				}
				$i++;
			}
			$output = preg_replace("/\n$/","",$output);
			
			return $output;
		
		}

		/**
		 * Outputs an HTML select list which selects a single item
		 *
		 * @param string $name
		 * @param string $id
		 * @param array $items
		 * @param string $val
		 */
		function drawlist($name,$id,$items,$val){

			$sel = "<select name=\"" . $name . "\" id=\"" . $id . "\">";

			foreach($items as $opt){
				$sel .= "<option value=\"" . $opt . "\" " . ($val == $opt ? ' selected="selected"' : '') . ">" . $opt . "</option>";
			}

			$sel .= "</select>";

			return $sel;
			
		}

		/**
		 * Replace a blank value with a replacement
		 *
		 * @param string $val
		 * @param string $rep
		 * @return string
		 */
		function RepBlank($val,$rep){
			
			if(StrictlyPluginTools::IsNothing($val)){
				return $rep;
			}else{
				return $val;
			}
		}

		/**
		 * Returns the path to the blog directory - taken from Arne Bracholds Sitemap plugin
		 *		
		 * @return string The full path to the blog directory
		*/
		function GetHomePath() {
			
			$res="";
			//Check if we are in the admin area -> get_home_path() is avaiable
			if(function_exists("get_home_path")) {
				$res = get_home_path();
			} else {
				//get_home_path() is not available, but we can't include the admin
				//libraries because many plugins check for the "check_admin_referer"
				//function to detect if you are on an admin page. So we have to copy
				//the get_home_path function in our own...
				$home = get_option( 'home' );
				if ( $home != '' && $home != get_option( 'siteurl' ) ) {
					$home_path	= parse_url( $home );
					$home_path	= $home_path['path'];
					$root		= str_replace( $_SERVER["PHP_SELF"], '', $_SERVER["SCRIPT_FILENAME"] );
					$home_path	= trailingslashit( $root.$home_path );
				} else {
					$home_path	= ABSPATH;
				}

				$res = $home_path;
			}
			return $res;
		}

		

		/**
		 * Checks if a file is writable and tries to make it if not.	
		 *
		 * @param string $filename
		 * @return boolean 
		 */
		function IsFileWritable($filename) {

			ShowSysDebug("IN IsFileWritable $filename");

			//can we write to our specified location?
			if(!is_writable($filename)) {

				ShowSysDebug("NO TRY CHMOD");

				// no so try to make the folder writable - for security reasons this really shouldn't work!
				if(!@chmod($filename, 0666)) {
					$pathtofilename = dirname($filename);
					// Check if parent directory is writable
					if(!is_writable($pathtofilename)) {
						ShowSysDebug("try to make $pathtofilename writable");
						// nope so try to make it writable
						if(!@chmod($pathtoffilename, 0666)) {												
							ShowSysDebug("FAILED");
							return false;
						}
					}
				}
			}		
			return true;
		}

		/**
		 * Validate an email address.
		 * Provide email address (raw input)
		 * Returns true if the email address has the email 
		 * address format and the domain exists.
		 *
		 * Author
		 * Douglas Lovell http://www.linuxjournal.com/article/9585
		 *
		 * @param string $email
		 * @return bool
		 */
		function ValidEmail($email)
		{
		   $isValid = true;
		   $atIndex = strrpos($email, "@");
		   if (is_bool($atIndex) && !$atIndex)
		   {
			  $isValid = false;
		   }
		   else
		   {
			  $domain = substr($email, $atIndex+1);
			  $local = substr($email, 0, $atIndex);
			  $localLen = strlen($local);
			  $domainLen = strlen($domain);
			  if ($localLen < 1 || $localLen > 64)
			  {
				 // local part length exceeded
				 $isValid = false;
			  }
			  else if ($domainLen < 1 || $domainLen > 255)
			  {
				 // domain part length exceeded
				 $isValid = false;
			  }
			  else if ($local[0] == '.' || $local[$localLen-1] == '.')
			  {
				 // local part starts or ends with '.'
				 $isValid = false;
			  }
			  else if (preg_match('/\\.\\./', $local))
			  {
				 // local part has two consecutive dots
				 $isValid = false;
			  }
			  else if (!preg_match('/^[A-Za-z0-9\\-\\.]+$/', $domain))
			  {
				 // character not valid in domain part
				 $isValid = false;
			  }
			  else if (preg_match('/\\.\\./', $domain))
			  {
				 // domain part has two consecutive dots
				 $isValid = false;
			  }
			  else if(!preg_match('/^(\\\\.|[A-Za-z0-9!#%&`_=\\/$\'*+?^{}|~.-])+$/',str_replace("\\\\","",$local)))
			  {
				 // character not valid in local part unless 
				 // local part is quoted
				 if (!preg_match('/^"(\\\\"|[^"])+"$/',
					 str_replace("\\\\","",$local)))
				 {
					$isValid = false;
				 }
			  }
			  if ($isValid && !(checkdnsrr($domain,"MX") || checkdnsrr($domain,"A")))
			  {
				 // domain not found in DNS
				 $isValid = false;
			  }
		   }
		   return $isValid;
		}
	}
}

if(!function_exists('ShowSysDebug')){
	/**
	 * function to output debug to page
	 *
	 * @param string $msg
	 */
	function ShowSysDebug($msg){
		if(DEBUGSYSCHECK){
			if(!empty($msg)){				
				if(is_array($msg)){
					print_r($msg);
					echo "<br />";
				}else{				
					echo htmlspecialchars($msg) . "<br>";
				}
			}
		}
	}
}

