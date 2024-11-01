<?php
//error_reporting(E_ALL);

class StrictlySystemCheck{

	protected $version				= "1.0.9";

	private static $instance;

	protected $windows;		

	protected $useragent			= "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/33.0.1750.154 Safari/537.36";

	protected $timeout				= 5;

	protected $slowquery			= 5;

	protected $connectiontheshold	= 80;

	protected $loadthreshold		= 1;

	protected $searchtext			= "";

	protected $emailreport			= "";

	protected $cron_code			= "";

	protected $site_down			= false;

	protected $send_report			= false;

	// the URL to check on a scheduled interval
	protected $checkurl				= ""; 

	protected $msg					= "";

	protected $errmsg				= "";

	protected $configerrmsg			= "";

	protected $checkmsg;

	protected $db_error_msg			= "";

	protected $config				= "";

	protected $server_load			= 0;

	protected $uninstall			= false;

	protected $checkmode			= "FAST";

	protected $optimizetables		= false;

	protected $sqlversion			= -1;

	protected $connection;

	protected $start_time;

	protected $stop_time;

	protected $duration				= 0;	

	protected $pct_reads			= 0;
	
	protected $pct_writes			= 0;
	
	protected $joins_without_indexes= 0;
	
	protected $joins_without_indexes_perhour	= 0;
	
	protected $aborted_connections	= 0;
	
	protected $uptime				= "";
	
	protected $total_queries_perhour= 0;
	
	protected $total_queries		= 0;
	
	protected $connections			= 0;
	
	protected $connections_perhour	= 0;

	protected $check_db_if_slow_load_time = false;

	protected $only_optimize_if_load_is_above_threshold = 0;


			

	private function __construct(){}

	// use singleton pattern to ensure there is only one instance of this object at any time
	public static function GetInstance(){

		ShowSysDebug("IN GetInstance");

		if (!isset(self::$instance)) {			
            $c = __CLASS__;
            self::$instance = new $c;
        }
		
		return self::$instance;
	}


	/**
	 * Sets the initial time counter	
	 *
	 */
	protected function StartTime(){
		// log starting time
		$this->start_time = microtime(true);
	}

	/**
	 * Stops the time counter	
	 *
	 */
	protected function StopTime(){
		// log starting time
		$this->stop_time = microtime(true);
	}

	/**
	 * Takes the difference between the start and stop time counters
	 *
	 */
	protected function Duration(){
		// log starting time
		$this->duration = round($this->stop_time - $this->start_time,2);
	}


	/**
	 * Returns the content of a URL with the correct transport
	 *
	 * @param string $url
	 * @return array
	 *
	 */
	protected function GetURL($url){

		ShowSysDebug("IN GetURL $url");

		if (in_array('curl', get_loaded_extensions())) {
			$http = $this->CURLGetURL($this->checkurl);
		}else if(ini_get('allow_url_fopen')){
			$http = $this->FOPENGetURL($this->checkurl);
		}else if(function_exists( 'fsockopen' )){
			$http = $this->FSOCKGetURL($this->checkurl);
		}else{
			$this->errmsg = __("The system could not make an HTTP request to the site!\r\n\r\nThere is no HTTP transport available to make regular site checks. Please ensure that either CURL is installed or that external URL\'s can be accessed through FOPEN or FSOCKOPEN from your webserver.","StrictlySystemCheck");

			return false;
		}

		return $http;
	}

	/**
	 * The main method that checks the relevant site by making an HTTP request to the URL, checking the source for a key phrase, checking
	 * the database can be connected to, scanning for corrupt tables and checking the server load.
	 *
	 * @return bool
	 */
	public function CheckSite(){

		ShowSysDebug("IN CheckSite");

		$checkDB = $this->send_report = $this->site_down = false;

		$this->windows = ((substr(PHP_OS, 0, 3) == 'WIN') ? true : false);

		$this->LogCheckMsg(__("Initiating System Report...","StrictlySystemCheck"));
		$this->LogCheckMsg(sprintf(__("Using Strictly System Check Version: %s","StrictlySystemCheck"),$this->version));

		// load in our custom config
		if(!$this->LoadPluginConfig()){

			$this->LogCheckMsg($this->errmsg);

			// if we can't load our config we cannot even send a report out so exit
			return false;

		}

		// set our internal members with the values from our config file
		$this->LoadFromConfig();

		$this->LogCheckMsg(sprintf(__("Initiating an HTTP request to %s","StrictlySystemCheck"),$this->checkurl));

		$user = $this->GetCurrentUser();

		if(empty($user))
		{
			$this->LogCheckMsg(sprintf(__("Current user is unavailable","StrictlySystemCheck"),$user));
		}else{
			$this->LogCheckMsg(sprintf(__("Current user is %s","StrictlySystemCheck"),$user));
		}
		

		ShowSysDebug("Current user is " . $user);

		$http = $this->GetURL($this->checkurl);

		ShowSysDebug("back from GetURL status is " . $http["status"]);

		if($http === false){

			ShowSysDebug("couldnt get an HTTP response");

			// cannot check our site so create our report file which can be accessed from admin
			// pass in the global error message			
			$report = $this->SaveReport($this->errmsg);		

			// send the report
			$this->SendSystemReport($report);

			return false;
			
		}

		// how long did the request take?
		$this->Duration();

		// log response
		$this->LogCheckMsg(sprintf(__("The HTTP request to %s took %d second(s) to respond and returned a status code of %s","StrictlySystemCheck"),$this->checkurl,$this->duration,$http['status']));
		
		if($this->duration > $this->timeout)
		{
			$this->LogCheckMsg(sprintf(__("The site took over the maximum time of %s seconds to load.","StrictlySystemCheck"),$this->timeout));

			if($this->check_db_if_slow_load_time)
			{
				
				$this->LogCheckMsg(sprintf(__("The system is set to check the database for potential problems if the page load is too long.","StrictlySystemCheck"),$this->timeout));

				$checkDB = $this->send_report = true;
			}
		}

		ShowSysDebug("could we make request");

		// was there a problem making the request
		if(($http["status"]=="0" || $http["status"]=="400") && !empty($http["error"])){

			ShowSysDebug("either 0 400 or we have an error");

			$this->LogCheckMsg(sprintf(__("There was an error making the HTTP request: $s","StrictlySystemCheck"),$http["error"]));

			ShowSysMsg("error making http request = " . $http["error"]);

			// if we got no response then the site seems to be in trouble
			$checkDB = $this->send_report = $this->site_down = true;			

		// status code could be 500 or 200 so just parse the html
		}elseif(!empty($http["html"])){

			ShowSysDebug("got HTML with any code");
			ShowSysDebug("status code is actually = " . $http["status"]=="500");
			//echo "status code = " . $http["status"] . "<br>";

			if(preg_match("@Error establishing a database connection@i",$http["html"])){

				ShowSysDebug("ERROR DB");

				// site is down with a DB error this could be a misconfiguration or a corrupt table index
				
				$this->LogCheckMsg(sprintf(__("Wordpress reports a database connection error whilst accessing: %s","StrictlySystemCheck"),$this->checkurl));

				// system is definitley in trouble if we are seeing this error!
				$checkDB = $this->send_report = $this->site_down = true;

			}elseif(!empty($this->searchtext)){

				// look for our user specified text
				ShowSysDebug("LOOK FOR TEXT " . $this->searchtext);

				if(stripos($http["html"],$this->searchtext) === false){

					ShowSysDebug("COULDNT FIND STRING!");

					$this->LogCheckMsg(sprintf(__("The specified search text %s could not be located within the HTTP response","StrictlySystemCheck"),$this->searchtext));

					$checkDB = $this->send_report = $this->site_down = true;
				}else{
					
					ShowSysDebug("FOUND STRING!");

					$this->LogCheckMsg(sprintf(__("The specified search text %s was found within the HTTP response","StrictlySystemCheck"),$this->searchtext));
					
				}
			// handle cloudflare errors
			}else if($http["status"]=="520" || $http["status"]=="521" || $http["status"]=="522" || $http["status"]=="523" || $http["status"]=="524"){

				ShowSysDebug("Cloudflare error");

				$cloudflareerror = $this->GetCloudflare($http["status"]);

				$this->LogCheckMsg(sprintf(__("Wordpress reports a %s status code this could indicate you use CloudFlare, Varnish or another Reverse Proxy system.\r\nPossible errors from this proxy and status code. %s - Try restarting Apache or checking your reverse proxy provider!","StrictlySystemCheck"),$http["status"], $this->checkurl,$cloudflareerror));

				// system is definitley in trouble if we are seeing this error!
				$checkDB = $this->send_report = $this->site_down = true;

 			// handle 500 errors
			}else if($http["status"]=="500" && $checkDB && $this->send_report && $this->site_down){
				
				//echo "status code = " . $http["status"] . "<br>";

				ShowSysDebug("500 STATUS CODE = " . $http["status"]=="500");

				$this->LogCheckMsg(sprintf(__("Wordpress reports a 500 status code (server error) whilst accessing: %s - Check your error logs!","StrictlySystemCheck"),$this->checkurl));

				// system is definitley in trouble if we are seeing this error!
				$checkDB = $this->send_report = $this->site_down = true;

			}
				
			
		}else{
			// if we got no response then the site seems to be in trouble
			$checkDB = $this->send_report = $this->site_down = true;

			$this->LogCheckMsg(__("The system got a blank response from the site. Try rebooting or restarting Apache/MySQL!","StrictlySystemCheck"));
		}


		// get the server load average (on LINUX) and the CPU % on Windows boxes and then check against our limits
		ShowSysDebug("get server load");

		// get current server load
		$this->server_load = $this->GetServerLoad();

		ShowSysDebug("server load = " . $this->server_load);

		$this->LogCheckMsg(sprintf(__("The server load is currently %s","StrictlySystemCheck"),$this->server_load));

		if($this->server_load >= $this->loadthreshold){

			$this->LogCheckMsg(sprintf(__("The server load is equal to or above the specified threshold of %s","StrictlySystemCheck"),$this->loadthreshold));

			$this->send_report = true;
		}elseif($this->server_load <= 0.10){

			$this->LogCheckMsg(__("The server load is very quiet, this is a good sign unless you cannot access the site","StrictlySystemCheck"));

			if($this->duration > $this->timeout)
			{
				$this->LogCheckMsg(__("As your page load is over your timeout threshold and your server is very quiet an Apache restart maybe needed!","StrictlySystemCheck"));

				$checkDB = $this->send_report = $this->site_down = true;
			}

		}else{

			$this->LogCheckMsg(__("The server load is okay","StrictlySystemCheck"));

		}

		
		$ServerMemoryUsage = $this->GetServerMemoryUsage();
		
		$PHPMemoryUsage = $this->GetCurrentMemoryUsage();
	
		$this->LogCheckMsg($PHPMemoryUsage);
		
		// try to open a DB connection
		if($this->OpenConnection()){

			ShowSysDebug("got an open connection to the DB");

			// populate some key stats > extend this feature in future versions to simulate mysqlreport tests
			$this->GetSQLStats();

			// get some important SQL performance indicators
			$this->RunSQLTests();

			

			// do we need to check the DB?
			if($checkDB){			
				
				// The following method will check all the tables and carry out REPAIR's on any that are corrupt
				$this->CheckDB();			

			}else{
				$this->LogCheckMsg(__("The system did not need to check the REPAIR status of tables.","StrictlySystemCheck"));
			}

			// check the no of current DB connections to see if the DB is overloaded or consumed by long running queries
			if(! $this->GetDatabaseLoad()){
				$this->send_report = true;	
			}else{
				
				ShowSysDebug("DB is okay - do we optimize any fragmented tables = " . $this->optimizetables);

				// if the database load is okay then do we run a check for fragmented tables?
				// non-fragmented tables means fast data access so it's in our best interest to ensure all indexes are optimized
				if($this->optimizetables){					
					
		
					if((is_numeric($this->only_optimize_if_load_is_above_threshold) && $this->server_load > $this->only_optimize_if_load_is_above_threshold) || ($this->only_optimize_if_load_is_above_threshold == 0))
					{

						// if we couldn't optimize for some reason then this could be a cause for concern
						if(!$this->CheckIndexes()){
							$this->send_report = true;
						}
					}else{
						$this->LogCheckMsg(sprintf(__("The system is not set up to OPTIMIZE the tables or the server load of %s was below the threshold for an OPTIMIZE of %s.","StrictlySystemCheck"),$this->server_load, $this->only_optimize_if_load_is_above_threshold));
					}
				}


			}
			
			// try to close our db connection as we have now finished with it
			$this->CloseConnection();
		}else{

			$this->LogCheckMsg(__("The Wordpress database could not be connected to. The system maybe under heavy duress or your database configuration parameters maybe wrong.","StrictlySystemCheck"));

			$this->send_report = true;
		}

		ShowSysDebug("create a report");
	
		if($this->send_report){
			
			$this->LogCheckMsg(sprintf(__("The system report concludes that the site is having problems and requires some immediate attention.\r\n\r\nOptions to try include:\r\n -Log into your console through SSH (Putty) and run TOP to see what processes are running and what resources they are consuming.\r\n- Install and run MyTOP to see which database queries are running and whether your server is in the middle of OPTIMIZING or REPAIRING which can lock your system up.\r\n -Check your servers error log for recorded errors.\r\n -Check your MySQL slow query log for long running queries, can you optimise them by adding indexes or re-writing them?\r\n -Check your access log for scrapers, DDOS attacks, spammers and hackers. Can you ban them by adding them to your systems Firewall or by using the .htaccess file?\r\n -Are people trying to hack your site through SSH? Install and use DENYHOSTS and other WordPress plugins to limit and lock out people who try to login to your site.\r\n -Try installing Fail2Ban to see if that helps automatically ban heavy hitters and hackers.\r\n -Check that SERP crawlers like GoogleBot and BingBOT are not over visiting you. If your content doesn't change daily there is no need for it to crawl daily. Edit your crawl rate setting either in your control center website or by using the Robots.txt Crawl-Delay directive to more than a few seconds.\r\n -Reduce your server load by banning heavy hitters, countries who you don't sell to or need their BOTS visiting and social media BOTS that just waste bandwidth\r\n -Check your Apache config settings to ensure you have enough memory to handle the MaxClients config setting your system has been set with.\r\n -Ensure your KeepAlive option, KeepAliveTimeout and KeepAliveConnections are set appropriatley for your site (some reading might be involved) on Apache tuning.\r\n -Put your site behind Cloudflare (free) to reduce load.\r\n -Install caching plugins for your site WP Super Cache, W3 Total Cache, Widget Cache etc.\r\n -Create static HTML pages when you can.\r\n -Read my WordPress survival guide series on performance, security and LINUX commands for beginners http://blog.strictly-software.com/2010/06/wordpress-survival-guide.html for tips\r\n -If you are autoblogging, importing content and tweeting ensure Twitter Rushes are not causing you problems. Read how you can prevent performance issues csused by this kind of blogging here http://blog.strictly-software.com/2014/07/introducing-strictly-tweetbot-pro.html.\r\n -Run some MySQL analyis reports and ensure your table/query cache settings and other database configuration are correctly set.\r\n -Restart Apache or MySQL.\r\n -Reboot your server.\r\n -Increase your available RAM and Disk Space.\r\n -Reduce Disk Swapping\r\n\r\nAn email is being sent to %s","StrictlySystemCheck"),$this->emailreport));

		}else{
			$this->LogCheckMsg(__("The system report has completed all its tests successfully.","StrictlySystemCheck"));
		}

		// create and output our report
		$report = $this->CreateReport();
		

		// end of tests do we send an email with the report as well?
		if($this->send_report){

			ShowSysDebug("we need to send a report");			

			$this->SendSystemReport($report);
			
		}

		return true;
	}

	

	/**
	 * gets current memory usage
	 *
	 * @param string $err
	 * @returns string
	 */
	protected function GetCloudflare($err)
	{
		ShowSysDebug("IN GetCloudflare $err");

		$reason = "";
		
		switch($err)
		{
			case "520":
				$reason = "There is an unknown connection issue between CloudFlare and your site.";
				break;
			case "521":
				$reason = "The webserver is not returning a connection";
				break;
			case "522":
				$reason = "The initial connection between CloudFlare and your site timed out.";
				break;
			case "523":
				$reason = "The web server is not reachable by CloudFlare.";
				break;
			case "524":
				$reason = "The origin web server timed out responding to this request.";
				break;
			default:
				$reason = "NA";
		}

		ShowSysDebug("RETURN " . $reason);

		return $reason;
	}


	/**
	 * gets current memory usage
	 *
	 * @returns string
	 */
	protected function GetServerMemoryUsage()
	{
		
		$retval = "";

		if(function_exists("shell_exec"))
		{ 
			$free = @shell_exec('free');
			$free = (string)trim($free);
			$free_arr = explode("\n", $free);
			$mem = explode(" ", $free_arr[1]);

			//echo "mem = $mem <br>";

			$mem = array_filter($mem);
			$mem = array_merge($mem);

			//echo "mem1 = " . $mem[1] . " - mem 2 = " . $mem[2] .  "<br>";

			$memory = $this->ConvertFromBytes($mem[1]*1024);
			$memory_usage = round((($mem[2]/$mem[1])*100),2);

			$swap = explode(" ", $free_arr[3]);

			//echo "swap = $swap <br>";

			$swap = array_filter($swap);
			$swap = array_merge($swap);
			$swapmemory = $this->ConvertFromBytes($swap[1]*1024);
			$swap_usage = round((($swap[2]/$swap[1])*100),2);

			//echo "mem1 = " . $swap[1] . " - mem 2 = " . $swap[2] .  "<br>";
			

			$retval = __("Your available server memory is $memory","StrictlySystemCheck");

			$this->LogCheckMsg($retval);

			$retval = __("Your current server memory usaage was $memory_usage%","StrictlySystemCheck");

			$this->LogCheckMsg($retval);

			
			$retval = __("Your available disk swap memory is $swapmemory","StrictlySystemCheck");

			$this->LogCheckMsg($retval);

			if($swap_usage == 0)
			{
				$retval = __("Your disk swap memory usage is $swap_usage% this is great!","StrictlySystemCheck");
			}
			else if($swap_usage <= 20)
			{
				$retval = __("Your disk swap memory usage is $swap_usage% this is not too bad!","StrictlySystemCheck");
			}
			else if($swap_usage <= 30)
			{
				$retval = __("Your disk swap memory usage is $swap_usage% this is quite high!","StrictlySystemCheck");
			}
			else if($swap_usage > 30)
			{
				$retval = __("Your disk swap memory usage is $swap_usage% this is very high. You should look into this ASAP!","StrictlySystemCheck");
			}

			

			$this->LogCheckMsg($retval);

		}else{
			$retval = __("Your system will not allow the report to access current memory usage on the server","StrictlySystemCheck");

			$this->LogCheckMsg($retval);
		}
	 
		return;
	}

	/**
	 * gets current memory usage
	 *
	 * @returns string
	 */
	protected function GetCurrentMemoryUsage(){

		$results = $this->ConvertFromBytes($this->GetMemoryUsage());

		if(empty($results)){
			$retval = __( "No PHP Memory usage could be obtained at the time of the report","StrictlySystemCheck");
		}else{
			$retval = __("The PHP Memory usage at the time of the report was " . $results,"StrictlySystemCheck");
		}
		
		return $retval;
	}


	/**
	 * format memory size from bytes
	 *
	 * @returns string
	 */
	protected function ConvertFromBytes($size)
	{

		$unit=array('B','KB','MB','GB','TB','PB');

		return @round($size/pow(1024,($i=floor(log($size,1024)))),2).$unit[$i];
	}
	
	
	/**
	 * get PHP memory usage
	 *
	 * @returns integer
	 */
	protected function GetMemoryUsage()
	{

		if(function_exists("memory_get_peak_usage")) {
			return memory_get_peak_usage(true);
		}elseif(function_exists("memory_get_usage")) {
			return  memory_get_usage(true);
		}
	}
	
	/**
	 * Sends an email to the specified recipient with the site status report
	 *
	 * @param string $report
	 */
	protected function GetSQLStats(){
		
		ShowSysDebug("IN GetSQLStats");

		// open a connection to our DB if we haven't already got one - we should have but that isn't the point
		if(!$this->OpenConnection()){
			return false;
		}

		ShowSysDebug("we have a connection so access the DB = " . SDB_NAME);

		// select the database
		if(!@mysql_select_db(SDB_NAME,$this->connection)) {
			$this->LogCheckMsg( sprintf(__("MySQL DB Select failed: %s","StrictlySystemCheck"), mysql_error()));
			return false;
		}

		// load up key status variables
		$sql = "SHOW /*!50000 GLOBAL */ STATUS;";

		
		$res = @mysql_query($sql,$this->connection);
		if(!$res) {
			$this->LogCheckMsg( sprintf(__("MySQL Query failed: %s","StrictlySystemCheck"), mysql_error()));
			return false;
		}		
		

		while($row = mysql_fetch_row($res)) {

			ShowSysDebug("set " . $row[0] . " to " . $row[1]);

			$this->status[$row[0]] = $row[1];
		}


		unset($res,$row);

		return true;

	}

	/**
	 * Formats the uptime value to a nice format
	 *
	 * @param int $uptime
	 * @returns string
	 *
	 */
	protected function FormatUptime($uptime){
		
		$seconds = $uptime % 60;
		$minutes = intval(($uptime % 3600) / 60);
		$hours = intval(($uptime % 86400) / (3600));
		$days = intval($uptime / (86400));
		$uptimestring;
		if ($days > 0) {
			$uptimestring = sprintf(__("%d days %d hours %d mins %d secs","StrictlySystemCheck"),$days,$hours,$minutes,$seconds);
		} elseif ($hours > 0) {
			$uptimestring = sprintf(__("%d hours %d mins %d secs","StrictlySystemCheck"),$hours,$minutes,$seconds);
		} elseif ($minutes > 0) {
			$uptimestring = sprintf(__("%d mins %d secs","StrictlySystemCheck"),$minutes,$seconds);
		} else {
			$uptimestring = sprintf(__("%d secs","StrictlySystemCheck"),$seconds);
		}
		return $uptimestring;
	}

	/**
	 * Run some SQL performance tests - will be extending this in future
	 *
	 */
	protected function RunSQLTests(){

		ShowSysDebug("IN RunSQLTests");

		// what percentage of our queries are "slow" as defined by the settings for this DB
		// this is different from our other test on currently running queries as it takes all executed queries since the last restart into consideration

		$this->uptime					= $this->FormatUptime($this->status["Uptime"]);

		$this->LogCheckMsg(sprintf(__("MySQL has been running for: %s","StrictlySystemCheck"),$this->uptime));

		$this->total_queries			= $this->status["Questions"];
		$this->total_queries_perhour	= intval($this->total_queries / ($this->status["Uptime"]/3600));

		ShowSysDebug("total queries = " . $this->total_queries . " per hour = " . $this->total_queries_perhour);

		$this->connections				= $this->status["Connections"];
		$this->connections_perhour		= intval($this->connections / ($this->status["Uptime"]/3600));
		$this->aborted_connections		= intval($this->status["Aborted_connects"] / $this->connections)* 100;

		ShowSysDebug("total connections = " .$this->connections . " per hour = " . $this->connections_perhour . " aborted " . $this->aborted_connections);


		if(intval($this->status["Questions"]) == 0){
			$this->slow_queriesperc = 0;
		}else{
			$this->slow_queriesperc = intval(($this->status["Slow_queries"] / $this->total_queries) * 100);
		}

		$this->LogCheckMsg(sprintf(__("Total Connections: %d - Aborted: %d - Connections Per Hour %s","StrictlySystemCheck"),$this->connections,$this->aborted_connections,$this->connections_perhour));


		$this->LogCheckMsg(sprintf(__("Total Queries: %d - Queries / Per Hour %s","StrictlySystemCheck"),$this->total_queries,$this->total_queries_perhour));


		// how many joins are we doing without indexes > sign that some performance tuning is required

		$this->joins_without_indexes		= $this->status["Select_range_check"] + $this->status["Select_full_join"];
		$this->joins_without_indexes_perhour= intval($this->joins_without_indexes / ($this->status["Uptime"]/3600));

		$this->LogCheckMsg(sprintf(__("Joins without indexes: %d - Joins without indexes Per Hour %s","StrictlySystemCheck"),$this->joins_without_indexes,$this->joins_without_indexes_perhour));

		// read / write
		$total_reads	= $this->status["Com_select"];
		$total_writes	= $this->status["Com_delete"] + $this->status["Com_insert"] + $this->status["Com_update"] + $this->status["Com_replace"];
		
		ShowSysDebug("total reads = " . $total_reads . " total writes = " . $total_writes);

		
		if ($total_reads == 0){
			$this->pct_reads	= 0;
			$this->pct_writes	= 100;
		} else {
			$this->pct_reads	= intval(($total_reads/($total_reads+$total_writes)) * 100);
			$this->pct_writes	= 100-$this->pct_reads;
		}

		$this->LogCheckMsg(sprintf(__("Total Reads: %d (%s%%) - Total Writes %s (%s%%)","StrictlySystemCheck"),$total_reads,$this->pct_reads,$total_writes,$this->pct_writes));

	}

	

	/**
	 * Sends an email to the specified recipient with the site status report
	 *
	 * @param string $report
	 */
	protected function SendSystemReport($report=""){
		
		ShowSysDebug("send report");

		// if we have someone to send the report to AND we actually have a report
		if(!empty($this->emailreport) && !empty($report)){

			$headers = "From: ". SNAME ." Administrator <" . SEMAIL . ">\n";

			// Different email clients use different headers. I think this should flag it in most but maybe wrong!
			$headers .= "X-Priority: 1\n";
			$headers .= "Priority: Urgent\n";
			$headers .= "Importance: high\n";

			// if the site is actually down specify a stronger subject line
			if($this->site_down){
				$subject = __("Strictly Software System Check - Your site is down!","StrictlySystemCheck");		
			}else{
				$subject = __("Strictly Software System Check - Site Report","StrictlySystemCheck");		
			}

			// try to send the email
			@mail($this->emailreport, $subject, $report, $headers);
			
		}	

	}


	/**
	 * Create a system report and output it to the plugin folder
	 * 
	 * @return string
	 */
	protected function CreateReport(){

		ShowSysDebug("IN CreateReport");

		// output all our messages
		$reportmsgs = $this->OutputMessages();

		ShowSysDebug("all our messages = " . $reportmsgs );

		// pass in the report data to our function that saves the file
		$report = $this->SaveReport($reportmsgs);		

		// return the report in case we want to email it
		return $report;
	}

	/**
	 * Saves a system report and output it to the plugin folder
	 * 
	 * @param string $reportdata
	 * @return string
	 */
	protected function SaveReport($reportdata=""){

		ShowSysDebug("IN SaveReport");

		// save our last report to a file in the plugin folder so it can be accessed in the admin area
		$path	= str_replace("\\","/",dirname(__FILE__) . "/lastreport.txt");

		ShowSysDebug("report data = " . $reportdata);

		// some weird issue is preventing sprintf and my custom __( function from working together!!
		$report =	"System Report: " . date('Y-m-d H:i:s',time()) . "\r\n\r\n".
					$reportdata . "\r\n\r\n".
					"Report Completed At " . date('Y-m-d H:i:s',time()) . "\r\n\r\n".
					"Strictly Software Plugins for Wordpress";

		ShowSysDebug("report content = " . $report);
		
		ShowSysDebug("save report to $path");

		file_put_contents($path, $report);

		// return the report in case we want to email it
		return $report;
	}


	/**
	 * Opens a connection to the host specified in our config file
	 *
	 * @return bool 
	 *
	 */
	protected function OpenConnection(){
		
		// our DB constants should be loaded now

		// if we already have a connection exit now
		if($this->connection) return true;

		// as we only ever connect to one Host/DB with the same details we can safely repeatedly call this
		// as it will re-use existing connections
		$this->connection = @mysql_connect(SDB_HOST,SDB_USER,SDB_PASSWORD);

		if(!$this->connection) {
			$this->LogCheckMsg( sprintf(__("MySQL Open Connection failed: %s","StrictlySystemCheck"), mysql_error()));
			return false;
		}else{
			return true;
		}

	}

	/**
	 * Closes the open connection
	 *
	 * @return bool 
	 *
	 */
	protected function CloseConnection(){
		
		if(!@mysql_close($this->connection)){
			$this->LogCheckMsg( sprintf(__("MySQL Close Connection failed: %s","StrictlySystemCheck"), mysql_error()));
			return false;
		}else{
			return true;
		}
	}

	/**
	 * Gets the current MySQL version
	 *
	 * @return bool
	 */
	protected function GetVersion(){
		
		ShowSysDebug("IN GetVersion");

		// if we dont already have a connection
		if(!$this->connection){

			// as this is never called from the report we can use the wordpress constants
			$this->connection = @mysql_connect(DB_HOST,DB_USER,DB_PASSWORD);

			// open a connection to our DB if we haven't already got one
			if(!$this->connection){
				return 0;
			}
		}

		$sql = "select convert(version() , signed);";

		$res = mysql_query($sql,$this->connection);
		if(!$res) {			
			return 0;
		}


		// get the max connections for the DB
		$row = mysql_fetch_row($res);

		$version = intval($row[0]);

		ShowSysDebug("version = " . $version);

		unset($res);

		// close
		@mysql_close($this->connection);

		return $version;

	}


	/**
	 * Checks the current database load by counting the current number of running queries and checking the connection limit
	 * I am basically treating a query as a connection and then using the number of queries to determine the connection load
	 * I could use the SHOW STATUS vars but then I wouldn't be able to check each queries execution time which is also important
	 *
	 * @return string 
	 *
	 */
	protected function GetDatabaseLoad(){

		ShowSysDebug("IN GetDatabaseLoad");

		// load in config if it hasn't been already

		
		if(!$this->LoadPluginConfig()){
			
			ShowSysDebug("the loading of the config failed");

			return false;
		}

		// open a connection to our DB if we haven't already got one
		if(!$this->OpenConnection()){
			return false;
		}

		ShowSysDebug("we have a connection so access the DB = " . SDB_NAME);

		// select the database
		if(!@mysql_select_db(SDB_NAME,$this->connection)) {
			$this->LogCheckMsg( sprintf(__("MySQL DB Select failed: %s","StrictlySystemCheck"), mysql_error()));
			return false;
		}

		// find the max connection limit
		$sql = "SELECT @@MAX_CONNECTIONS;";
	
		ShowSysDebug( $sql );
		
		$res = @mysql_query($sql,$this->connection);
		if(!$res) {
			$this->LogCheckMsg( sprintf(__("MySQL Query failed: %s","StrictlySystemCheck"), mysql_error()));
			return false;
		}


		// get the max connections for the DB - only one row
		$row = mysql_fetch_row($res);

		$maxconnections = intval($row[0]);

		ShowSysDebug("MAX Connections = " . $maxconnections);

		unset($res);

		// now get the number of queries currently being handled
		$sql = "SHOW PROCESSLIST;";

		$res = @mysql_query($sql,$this->connection);
		if(!$res) {
			$this->LogCheckMsg( sprintf(__("MySQL Query failed: %s","StrictlySystemCheck"), mysql_error()));
			return false;
		}

		$slowquerycount = $querycount = 0;

		while($row = mysql_fetch_object($res)) {

			ShowSysDebug("command = " . $row->Command .  " - query = " . $row->Info . " - Time = " . $row->Time);

			// log anything running over X seconds
			if($row->Command == "Query" && intval($row->Time) > $this->slowquery){
				$slowquerycount ++;
			}
			
			$querycount ++;

		}


		ShowSysDebug("so there were " . $querycount . " queries running");
		ShowSysDebug("so there were " . $slowquerycount . " slow queries running");

		// have we got any slow running queries? if so log them. We don't do any decision making based on this figure just yet but it's a useful stat to collect
		if($slowquerycount == 1){

			// log slow running queries
			$this->LogCheckMsg( sprintf(__("The system found 1 query running with an execution time over %d second(s)","StrictlySystemCheck"), $slowquerycount, $this->slowquery));

		}elseif($slowquerycount > 0){

			// log slow running queries
			$this->LogCheckMsg( sprintf(__("The system found %d queries running with an execution time over %d second(s)","StrictlySystemCheck"), $slowquerycount, $this->slowquery));

		}


		$dbload = round( (($querycount / $maxconnections) * 100) ,2);

		ShowSysDebug("current connection load is " . $dbload . "% utilisation");

		$this->LogCheckMsg( sprintf(__("The system is currently configured to accept a maximum of %d database connections","StrictlySystemCheck"), $maxconnections));

		if($querycount == 1){
			$this->LogCheckMsg( sprintf(__("At the time of reporting the database was running 1 query","StrictlySystemCheck"), $querycount));
		}else{	
			$this->LogCheckMsg( sprintf(__("At the time of reporting the database was running %d queries","StrictlySystemCheck"), $querycount));
		}

		$this->LogCheckMsg( sprintf(__("The current database load is %s%%","StrictlySystemCheck"), $dbload));


		// have we used all our available connections?
		if($querycount >= $maxconnections){
			
			ShowSysDebug("REACHED CONNECTION LIMIT!");

			$this->LogCheckMsg( sprintf(__("The database has reached the maximum number of queries it can handle. There are %d open connections and the limit is %d","StrictlySystemCheck"), $querycount,$maxconnections ));

			return false;

		// or are we over our database connection threshold
		}elseif($dbload >= $this->connectiontheshold){
			
			ShowSysDebug("Under pressure! and over our limit");

			$this->LogCheckMsg(sprintf(__("The database load is equal to or above the specified threshold of %s%%","StrictlySystemCheck"), $util,$this->connectiontheshold));

			return false;
		}else{		

			$this->LogCheckMsg("The database load is okay","StrictlySystemCheck");

			return true; // seems ok
		}
		
		
	}

	/**
	 * Return the current user
	 *
	 * @return string
	 *
	 */
	protected function GetCurrentUser(){

		ShowSysDebug("IN GetCurrentUser");
		
		if(!function_exists("shell_exec")){
			return "NA";
		}

		$i = @shell_exec("whoami"); 

		ShowSysDebug("whoami returned = $i");

		if(empty($i)){
			return "NA";
		}else{
			return $i;
		}
	}

	/**
	 * test whether we can run system commands by running a simple whoami command and checking the result
	 *
	 * @return bool
	 *
	 */
	protected function TestSystemExec(){

		ShowSysDebug("IN TestSystemExec");
		
		if(!function_exists("shell_exec")){
			return false;
		}

		$i = @shell_exec("whoami"); 

		ShowSysDebug("whoami returned = $i");

		if(empty($i)){
			return false;
		}else{
			return true;
		}
	}

	/**
	 * Checks the current server load by reading in the results of the uptime function
	 * For windows the function can takes a snapshot of the CPU usage at a point in time 
	 *
	 * @return string 
	 *
	 */
	protected function GetServerLoad(){
 
		$os = strtolower(PHP_OS); 
		
		// handle non windows machines
		if(substr(PHP_OS, 0, 3) !== 'WIN'){
			if(file_exists("/proc/loadavg")) {				
				$load	= file_get_contents("/proc/loadavg"); 
				$load	= explode(' ', $load); 				
				return $load[0]; 
			}elseif(function_exists("shell_exec")) { 				
				$load	= @shell_exec("uptime");
				$load	= explode(' ', $load);        
				return $load[count($load)-3]; 
			}else { 
				return false; 
			} 
		// handle windows servers
		}else{ 
			if(class_exists("COM")) { 				
				$wmi		= new COM("WinMgmts:\\\\."); 
				if(is_object($wmi)){
					$cpus		= $wmi->InstancesOf("Win32_Processor"); 
					$cpuload	= 0; 
					$i			= 0;   
					// Old PHP
					if(version_compare('4.50.0', PHP_VERSION) == 1) { 
						// PHP 4 					
						while ($cpu = $cpus->Next()) { 
							$cpuload += $cpu->LoadPercentage; 
							$i++; 
						} 
					} else { 
						// PHP 5 					
						foreach($cpus as $cpu) { 
							$cpuload += $cpu->LoadPercentage; 
							$i++; 
						} 
					} 
					$cpuload = round($cpuload / $i, 2); 
					return "$cpuload%"; 
				}
			} 
			return false; 			 
		} 
	}

	/**
	 * Checks whether our own database constants are accessible
	 *
	 * @return book
	 */
	protected function CheckDBConstants(){

		if(defined('SDB_NAME') && defined('SDB_USER') && defined('SDB_PASSWORD') && defined('SDB_HOST')){
			return true;
		}else{
			return false;
		}
	}



	/**
	 * Loads our custom config file and then checks the constants we need are available as the file maybe empty
	 *
	 */
	public function LoadPluginConfig(){

		ShowSysDebug("IN LoadPluginConfig");

		$path	= str_replace("\\","/",dirname(__FILE__) . "/sysconfig.php");

		if(file_exists($path)){
			
			ShowSysDebug("file $path exists so load it");

			include_once($path);

			if(defined('SDB_NAME') && defined('SDB_USER') && defined('SDB_PASSWORD') && defined('SDB_HOST')){

				ShowSysDebug("config exists");

				// check for updates - new values that I have added since the first release
				if(!defined('SOPTIMIZE_TABLES')){

					$this->configerrmsg = __("The plugin config file is missing some new settings. Please re-save the file.","StrictlySystemCheck");

					return false;
				}else{

					// seems to be ok
					return true;
				}
			}else{
				$this->configerrmsg = __("The system cannot load the required settings that it needs to connect to the database.","StrictlySystemCheck");

				return false;
			}
		}else{
			ShowSysDebug("$path doesnt even exist yet");

			$this->configerrmsg = __("The system cannot find the required plugin config file.","StrictlySystemCheck");

			return false;
		}
	}

	/**
	 * Builds up the contents for the local config file used by the plugin
	 * As we don't load any wordpress files during the system check we need to have a local copy of all the variables
	 *
	 */
	private function GetDBConfig(){

		ShowSysDebug("IN GetDBConfig");

		$config =	"<?php\n\n".
						"/** " .__("This config file holds the same DB configuration values as the main config.php file","StrictlySystemCheck")." */\n\n".
						"/**" .__("The name of the database for WordPress","StrictlySystemCheck").". */\n".
						"define('SDB_NAME', '" . addslashes(DB_NAME) . "');\n\n".						
						"/**" . __("MySQL database username","StrictlySystemCheck")." */\n".
						"define('SDB_USER', '" . addslashes(DB_USER) . "');\n\n".						
						"/**". __("MySQL database password","StrictlySystemCheck")." */\n".
						"define('SDB_PASSWORD', '" . addslashes(DB_PASSWORD) . "');\n\n".						
						"/**" .__("MySQL hostname","StrictlySystemCheck")." */\n".
						"define('SDB_HOST', '" . addslashes(DB_HOST) . "');\n\n".
						"/**". __("Authentication Code used to determine whether to allow remote requests","StrictlySystemCheck")." */\n".
						"define('SDB_CRONCODE', '" . $this->cron_code . "');\n\n".
						"/**"  .__("The name of the wordpress site we are checking","StrictlySystemCheck")." */\n".
						"define('SNAME','" . addslashes(get_bloginfo('name')) ."');\n\n".
						"/**" .__("The admin email address to send our reports from","StrictlySystemCheck")." */\n".
						"define('SEMAIL','" . addslashes(get_bloginfo('admin_email')) ."');\n\n".
						"/**" .__("The URL to check at specified intervals","StrictlySystemCheck")." */\n".
						"define('SCHECK_URL','" . addslashes($this->checkurl) ."');\n\n".
						"/**" .__("The email address to send the report to","StrictlySystemCheck")." */\n".
						"define('SEMAIL_REPORT','" .addslashes($this->emailreport) ."');\n\n".
						"/**" .__("The useragent to make our HTTP request with","StrictlySystemCheck")." */\n".
						"define('SUSERAGENT','" . addslashes($this->useragent) ."');\n\n".
						"/**" .__("The connection timeout in seconds","StrictlySystemCheck")." */\n".
						"define('STIMEOUT'," . addslashes($this->timeout)	 .");\n\n".
						"/**" .__("A piece of text that should appear in the URL at all times which we search for as an indicator that the site is running ok","StrictlySystemCheck")." */\n".
						"define('SSEARCH_TEXT','" . addslashes($this->searchtext) ."');\n\n".
						"/**" .__("The type of database integrity check to carry out against the tables within our database","StrictlySystemCheck")." */\n".
						"define('SCHECK_MODE','" . addslashes($this->checkmode) ."');\n\n".
						"/**" .__("Whether to check for and repair fragmented tables and indexes","StrictlySystemCheck")." */\n".
						"define('SOPTIMIZE_TABLES','" . (($this->optimizetables) ? "TRUE" : "FALSE") ."');\n\n".
						"/**" .__("The number of seconds that must pass before a query is considered slow","StrictlySystemCheck")." */\n".
						"define('SSLOW_QUERY'," . addslashes($this->slowquery) .");\n\n".
						"/**" .__("The server load threshold, anything above is considered overloaded","StrictlySystemCheck")." */\n".
						"define('SLOAD_THRESHOLD'," . addslashes($this->loadthreshold) .");\n\n".
						"/**" .__("The database connection threshold, anything above is considered overloaded","StrictlySystemCheck")." */\n".
						"define('SCONNECTION_THRESHOLD'," .addslashes($this->connectiontheshold) .");\n\n" .	
						"/**" .__("If the page load time is above the timeout specified then run database checks","StrictlySystemCheck")." */\n".
						"define('SCHECK_DB_IF_SLOW_LOAD_TIME','" .(($this->check_db_if_slow_load_time) ? "TRUE" : "FALSE") ."');\n\n".
						"/**" .__("Only optimize if the server load is above this threshold","StrictlySystemCheck")." */\n".
						"define('SONLY_OPTIMIZE_IF_LOAD_IS_ABOVE_THRESHOLD'," .addslashes($this->only_optimize_if_load_is_above_threshold) .");\n"; 
	
		

		return $config;
	}

	/**
	 * Load config settings from our own local file instead of the Wordpress database
	 * Whilst we save our plugin settings in the Wordpress DB when the report runs it runs without loading in any wordpress files therefore
	 * we require our own copy of the configuration settings which we save to a local file within the plugin folder
	 *
	 */
	protected function LoadFromConfig(){

		ShowSysDebug("IN LoadFromConfig");

		if(defined('SCHECK_URL')){
			$this->checkurl = SCHECK_URL;
		}
		if(defined('SEMAIL_REPORT')){
			$this->emailreport = SEMAIL_REPORT;
		}
		if(defined('SUSERAGENT')){
			$this->useragent = SUSERAGENT;
		}
		if(defined('STIMEOUT')){
			$this->timeout = STIMEOUT;
		}
		if(defined('SSEARCH_TEXT')){
			$this->searchtext = SSEARCH_TEXT;
		}
		if(defined('SCHECK_MODE')){
			$this->checkmode = SCHECK_MODE;
		}
		if(defined('SSLOW_QUERY')){
			$this->slowquery = SSLOW_QUERY;
		}
		if(defined('SLOAD_THRESHOLD')){
			$this->loadthreshold = SLOAD_THRESHOLD;
		}
		if(defined('SCONNECTION_THRESHOLD')){
			$this->connectiontheshold = SCONNECTION_THRESHOLD;
		}
		if(defined('SOPTIMIZE_TABLES')){
			$this->optimizetables = SOPTIMIZE_TABLES;
		}
		
		if(defined('SCHECK_DB_IF_SLOW_LOAD_TIME')){
			$this->check_db_if_slow_load_time = SCHECK_DB_IF_SLOW_LOAD_TIME;
		}
		if(defined('SONLY_OPTIMIZE_IF_LOAD_IS_ABOVE_THRESHOLD')){
			$this->only_optimize_if_load_is_above_threshold = SONLY_OPTIMIZE_IF_LOAD_IS_ABOVE_THRESHOLD;
		}

		
	}



	/**
	 * We need our own DB config file as if we try to load the Wordpress config file during a DB lockup
	 * it will try to load in all the other Wordpress files and make various DB connections and run selects
	 * which we don't want. Therefore this method will try to create a custom config file in the plugin folder
	 *
	 */
	protected function CreateDBConfig(){

		ShowSysDebug("IN CreateDBConfig");

		$path	= str_replace("\\","/",dirname(__FILE__) . "/sysconfig.php");
		$saved	= false;
		
		$config = $this->GetDBConfig();

		// try to save this file and if the file or folder isn't writable try to make it so
		if(StrictlyPluginTools::IsFileWritable($path)){

			ShowSysDebug("File $path is writable");

			@file_put_contents($path, $config);

			if(file_exists($path)){

				ShowSysDebug("file $path exists");
				$saved = true;
			}
		}

		if(!$saved){
			$this->configerrmsg = sprintf(__("The plugin could not create the required config file at the following location <strong>%s</strong>.<br /><br />Please create it manually or make the file location writable so that the website can create it.<br /><br />Try running the following command: <strong>chmod 767 %s</strong>","StrictlySystemCheck"),$path,$path);
		}

		ShowSysDebug("RETURN $saved");

		return $saved;
	}

	/**
	 * Adds a message related to the system check to the message cache
	 *
	 * @param string $err
	 *
	 */
	protected function LogCheckMsg($err){
		
		$this->checkmsg[] = $err;

	}

	/**
	 * returns all messages logged during the last system check
	 *
	 * return string
	 *
	 */
	protected function OutputMessages($line="\r\n"){

		$output = "";
		
		foreach($this->checkmsg as $msg){

			$output .= $msg . $line;
		}

		return $output;
	}



	/**
	 * Check our database to see if any tables are fragmented and optimize them if so
	 *
	 * @return bool
	 *
	 */
	protected function CheckIndexes(){

		ShowSysDebug("IN CheckIndexes");

		$this->LogCheckMsg(__("Initiating a check for fragmented tables and indexes","StrictlySystemCheck"));

		$success = false;
		$errs = 0;

		// open a connection to our DB if we haven't already got an open one
		if(!$this->OpenConnection()){
			return false;
		}

		
		if(!mysql_select_db(SDB_NAME,$this->connection)) {
			$this->LogCheckMsg( sprintf(__("MySQL DB Select failed: %s","StrictlySystemCheck"), mysql_error()));
			return false;
		}

		
		// use our system views to check for fragmented tables
		$sql = "SELECT *  FROM information_schema.TABLES WHERE TABLE_SCHEMA NOT IN ('information_schema','mysql') AND Data_free > 0 AND NOT ENGINE='MEMORY';";
	
		ShowSysDebug( $sql );
		
		$results = mysql_query($sql,$this->connection);
		if(!$results) {
			$this->LogCheckMsg( sprintf(__("MySQL query failed to retrieve table listings: %s","StrictlySystemCheck"),mysql_error()));
			return false;
		}


		ShowSysDebug( "loop through all tables");

		
		while($table = mysql_fetch_row($results)) {
			
			$chksql = "OPTIMIZE TABLE " . $table[2] .";";

			ShowSysDebug( $chksql );

			$res = mysql_query($chksql,$this->connection);
			if(!$res) {
				$this->LogCheckMsg(sprintf(__("MySQL query %s failed: %s","StrictlySystemCheck"),$chksql, mysql_error()));	
				
				$errs ++;
			}else{

				while($row = mysql_fetch_object($res)) {
					
					ShowSysDebug( "Result is == " . $row->Msg_text );

					// if result isn't ok then run a optimize
					if($row->Msg_text != "OK"){
						
						ShowSysDebug("Optimize failed for " . $table[2]);

						$this->LogCheckMsg(sprintf(__("Optimize Failed for table: %s  %s","StrictlySystemCheck"),$table[2], $row->Msg_text));	
					}else{
						ShowSysDebug("Optimize success for " . $table[2]);

						$this->LogCheckMsg(sprintf(__("Optimized table: %s","StrictlySystemCheck"),$table[2]));
					}
				}

				unset($row);
			}

			unset($res);
			
		}

		unset($results);		

		if($errs == 0){
			$this->LogCheckMsg(__("Completed check for fragmented tables and indexes","StrictlySystemCheck"));

			$success = true;
		}else{
			$this->LogCheckMsg(sprintf(__("Check for fragmented tables and indexes completed with %d errors","StrictlySystemCheck"),$errs));

			$success = false;
		}

		return $success;
	}


	/**
	 * Check our database to see if any tables have crashed and if so try to repair them
	 *
	 * @return bool
	 *
	 */
	protected function CheckDB(){

		ShowSysDebug("IN CheckDB");

		$this->LogCheckMsg(__("Initiating a check for corrupt tables and indexes","StrictlySystemCheck"));

		// open a connection to our DB if we haven't already got an open one
		if(!$this->OpenConnection()){
			return false;
		}

		
		if(!mysql_select_db(SDB_NAME,$this->connection)) {
			$this->LogCheckMsg( sprintf(__("MySQL DB Select failed: %s","StrictlySystemCheck"), mysql_error()));
			return false;
		}

		
		// so we can connect to the db lets look for crashed tables
		$sql = "SHOW TABLES;";
	
		ShowSysDebug( $sql );
		
		$results = mysql_query($sql,$this->connection);
		if(!$results) {
			$this->LogCheckMsg( sprintf(__("MySQL query failed to retrieve table listings: %s","StrictlySystemCheck"),mysql_error()));
			return false;
		}


		ShowSysDebug( "loop through all tables");

		$repair_tables = "";

		// set up the type of table CHECK we want (FAST is actually MEDIUM for our purposes)
		$check_type = ($this->checkmode == "FAST") ? "MEDIUM" : "EXTENDED";

		set_time_limit(0);

		while($table = mysql_fetch_row($results)) {
			
			$chksql = "CHECK TABLE " . $table[0] . " " . $check_type .";";

			ShowSysDebug( $chksql );

			$res = mysql_query($chksql,$this->connection);
			if(!$res) {
				$this->LogCheckMsg(sprintf(__("MySQL query %s failed: %s","StrictlySystemCheck"),$chksql, mysql_error()));				
			}else{
				
				// got a recordset back so check the result - should only be one row
				while($row = mysql_fetch_object($res)) {
					
					ShowSysDebug( "Result is == " . $row->Msg_text );

					// if result isn't ok then run a repair
					if($row->Msg_text != "OK"){
						
						ShowSysDebug("Run REPAIR on " . $table[0]);

						$repair_tables .=   $table[0] . ", ";

						$this->LogCheckMsg(sprintf(__("Crashed Table: %s - %s","StrictlySystemCheck"),$table[0], $row->Msg_text));	
					}else{
						$this->LogCheckMsg(sprintf(__("Table: %s does not need repairing","StrictlySystemCheck"),$table[0]));
					}
				}

				unset($row);
			}

			unset($res);
			
		}

		unset($results);		

		ShowSysDebug("REPAIR TABLES = " . $repair_tables);

		if(!empty($repair_tables)){

			$repair_tables = substr($repair_tables,0,-2);

			$repairsql = "REPAIR TABLE " . $repair_tables;

			ShowSysDebug($repairsql);

			$res = mysql_query($repairsql,$this->connection);
			if(!$res){
				$this->LogCheckMsg(sprintf(__("MySQL REPAIR failed: %s","StrictlySystemCheck"), mysql_error()));
				$success = false;
			}else{
				$this->LogCheckMsg( __("MySQL REPAIR succeeded","StrictlySystemCheck"));
				$success = true;
			}

			ShowSysDebug($result);

			unset($res);

		}else{
			$success = true;

			$this->LogCheckMsg(__("No repairing of tables was required","StrictlySystemCheck"));
		}

		$this->LogCheckMsg(__("Completed check for corrupt tables and indexes","StrictlySystemCheck"));

		return $success;
	}


	/**
	 * Checks the current plugins setup
	 *
	 */
	protected function TestConfig(){

		ShowSysDebug("IN TestConfig");

		$this->msg = $this->errmsg = "";

		$passed = false;
		$errs = 0;

		// Checks the system to ensure there is an HTTP transport mechanism available to make requests with
		if (!in_array ('curl', get_loaded_extensions()) && !(ini_get('allow_url_fopen')) && !(function_exists( 'fsockopen' ))) {
			
			$this->errmsg .= "<p>" . __("No HTTP transport is available to make regular site checks. Ensure that either CURL is installed or that URL\'s can be accessed through FOPEN or FSOCKOPEN.","StrictlySystemCheck") . "</p>";
			
			$transportok = false;
			$errs ++;
		}else{

			$this->msg .= "<p>" . __("HTTP Transport is available.","StrictlySystemCheck") . "</p>";

			$transportok = true;

		}

		if($transportok){
			// check that the URL is valid by trying to access it now and ensure we get a 200 status code
			$http = $this->GetURL($this->checkurl);

			ShowSysDebug("check url = " . $this->checkurl);

			ShowSysDebug("got a status code of " . $http["status"] );


			if($http["status"] != "200"){

				$this->errmsg .= "<p>" . sprintf(__("The URL %s returned a status code of %d when requested. Please ensure the URL is correct and that the useragent or IP address you are requesting from is not being blocked by your server for any reason.","StrictlySystemCheck"),$this->checkurl,$http["status"]) . "</p>";

				$errs ++;
			}else if(empty($http["html"])){

				$this->msg .= "<p>" . sprintf(__("The URL %s returned a status code of %d when requested but there was no content in the response!","StrictlySystemCheck"),$this->checkurl,$http["status"]) . "</p>";
				
				$errs ++;

			}else{	

				$this->msg .= "<p>" . sprintf(__("The URL %s returned a status code of %d when requested.","StrictlySystemCheck"),$this->checkurl,$http["status"]) . "</p>";

			}
		}

		// Check that we have our own config file
		if(!$this->LoadPluginConfig()){

			ShowSysDebug("cannot load the config file try to create it");

			// try to create it
			if(!$this->CreateDBConfig()){

				ShowSysDebug("couldnt create the config file");

				$errs ++;

				$this->errmsg .= "<p>" . $this->configerrmsg . "</p>";
			}else{

				$this->msg .= "<p>" . __("The Plugin configuration file was not initially found but was created successfully by the system.","StrictlySystemCheck") . "</p>";
			}
		}else{

			$this->msg .= "<p>" . __("The Plugin configuration file was found and loaded correctly.","StrictlySystemCheck") . "</p>";
		}

		$this->sqlversion = $this->GetVersion();

		if($this->sqlversion  > 0){
			$this->msg .= "<p>" .sprintf( __("MySQL Database connection is ok; Version: %d.","StrictlySystemCheck"),$this->sqlversion ) . "</p>";
		}else{
			$this->msg .= "<p>" . __("MySQL Datbase connection failed.","StrictlySystemCheck")  . "</p>";
		}
		
		// check we can write a report file out			
		
		$path	= str_replace("\\","/",dirname(__FILE__) . "/lastreport.txt");

		if(!StrictlyPluginTools::IsFileWritable($path)){

			$this->errmsg .= "<p>" . sprintf(__("The following file location needs to be writable by this plugin: <strong>%s</strong><br /><br />Try running the following command: <strong>chmod 767 %s</strong>","StrictlySystemCheck"),$path,$path) . "</p>";

			$errs ++;
		}else{

			$this->msg .= "<p>" . sprintf(__("The System Report file location %s is writable.","StrictlySystemCheck"),$path) . "</p>";
		}

		// for windows machines we test by just trying to get the information we want during the report i.e the CPU usage
		if($this->windows){

			if($this->GetServerLoad() !== false){
				$this->errmsg .= "<p>" . __("The plugin cannot access system information such as the CPU utilization on your server. If you would like this plugin to be able to report on server load the user your website connects with needs to be granted permission to create WMI COM objects. Please speak to your system administrator if you wish to change these settings.","StrictlySystemCheck"). "</p>";

				$errs ++;

			}else{
				$this->msg .= "<p>" . __("The website has permission to run the neccessary system functions it requires to obtain up to date server load information.","StrictlySystemCheck") . "</p>";
			}

		// if its not windows can we run system functions
		}else{

			if(!$this->TestSystemExec()){

				$this->errmsg .= "<p>" . __("The plugin cannot run system commands such as uptime and top. If you would like this plugin to be able to report on server load the user your website connects with needs to be granted permission to execute functions such as system, exec and passthru. Please speak to your system administrator if you wish to change these settings.","StrictlySystemCheck"). "</p>";

				$errs ++;
			}else{
				$this->msg .= "<p>" . __("The website has permission to run the neccessary system functions it requires to obtain up to date server load information.","StrictlySystemCheck") . "</p>";
			}
		}

		ShowSysDebug("there are $err config errors");

		if($errs > 0){
			return false;
		}else{
			return true;
		}
	}



	protected function CURLGetURL($url){		
		
		ShowSysDebug("USE CURL");

		$ch = curl_init();
		
		curl_setopt($ch, CURLOPT_URL, $url); // the url to retrieve
		curl_setopt($ch, CURLOPT_HEADER, 1); // return the header along with body
		curl_setopt($ch, CURLOPT_MAXREDIRS, 3); // follow this amount of redirects
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION,1); // follow redirects
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // return output as string
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); //ignore issues with SSL certificates
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout); // connection timeout
		curl_setopt($ch, CURLOPT_USERAGENT, $this->useragent); // the useragent to connect with
		
		// log the time it takes to return our page - CURL has its own timer but I am just being consistent across transports
		$this->StartTime();

		$urlinfo["html"]	= curl_exec($ch);
		$urlinfo["status"]	= curl_getinfo($ch, CURLINFO_HTTP_CODE); 
		$urlinfo["error"]	= curl_error($ch);
		$urlinfo['headers']	= curl_getinfo($ch);
		
		//echo "CURL status = " . $urlinfo["status"]  . "<br>";
		
		//echo "<br>";
		

		$this->StopTime();

		curl_close($ch);
	 
		return $urlinfo;
	}

	protected function FOPENGetURL($url){

		ShowSysDebug("IN FOPENGetURL $url");

		// create http array
		$opts = array('http'=>array(
				'method'=>'GET',
				'user_agent'=>$this->useragent,
				'timeout'=>$this->timeout
		));

		$context = stream_context_create($opts);

		// log the time it takes to return our page
		$this->StartTime();

		// Open the file using the HTTP headers set above
		$html = @file_get_contents($url, false, $context);

		// stop our timer
		$this->StopTime();

		// check $http_response_header for status code e.g first part is HTTP/1.1 200 OK

		// Retrieve HTTP status code by splitting this into 3 vars
		list($version,$status,$msg) = explode(' ',$http_response_header[0], 3);

		ShowSysDebug("status is " . $status. " - " .$msg);


		$urlinfo["status"]	= (is_numeric($status)) ? $status : 400;
		$urlinfo["html"]	= $html;
		$urlinfo['headers']	= $http_response_header;
			
		// inflate compressed content
		if (isset($urlinfo['headers']['content-encoding']) && ($urlinfo['headers']['content-encoding'] == 'gzip' || $urlinfo['info']['content-encoding'] == 'deflate'|| $urlinfo['headers']['content-encoding'] == 'x-gzip'))
		{
			// call decompress method to handle issues with certain sites e.g wordpress
			//$this->body = $this->decompress($this->body);
			$urlinfo["html"] = $this->Decompress($urlinfo["html"],$urlinfo['headers']['transfer-encoding']);

		}

		// return array
		return $urlinfo;
	}

	protected function FSOCKGetURL($url,$maxredirs=3){

		ShowSysDebug("IN FSOCKGetURL $url on redirect = $maxredirs");

		$urlinfo = parse_url($url);

		$urlinfo["html"]	= "";
		$urlinfo["status"]	= 0;
					 
		if (empty($urlinfo["scheme"])) {$urlinfo = parse_url("http://".$url);}                                                                  
		if (empty($urlinfo["path"])) {$urlinfo["path"]="/";}
				  
		if ( ! isset( $urlinfo['port'] ) ) {
			if ( ( $urlinfo['scheme'] == 'ssl' || $urlinfo['scheme'] == 'https' ) && extension_loaded('openssl') ) {
				$urlinfo["host"] = "ssl://$fsockopen_host";
				$urlinfo['port'] = 443;
				$ssl			 = true;
				$error_reporting = error_reporting(0);
			} else {
				$urlinfo['port'] = 80;
			}
		}

		
		if (isset($urlinfo["query"]))
		{
			$request = "GET ".$urlinfo["path"]."?".$urlinfo["query"]." ";
		} else {   
			$request = "GET ".$urlinfo["path"]." ";
		}
	
	
		$request .= "HTTP/1.0\r\n";
		$request .= "Host: ".$urlinfo["host"]."\r\n";
		$request .= "User-Agent: ".$this->useragent."\r\n";	
		$request .= "Connection: close\r\n\r\n";
		
		ShowSysDebug("make request to " . $request);

		// log the time it takes to return our page
		$this->StartTime();

		$fp = @fsockopen($urlinfo["host"], $urlinfo["port"], $errno, $errstr, $this->timeout);

		if (!$fp)
		{
			$urlinfo["error"]	= "Socket Error! (".$errno.") ".$errstr;				
		}
		else
		{   
			$bytes = fwrite($fp, $request);

			if ($bytes === false) {
				$urlinfo["error"]	= "Socket error: Error sending data";
			}elseif ($bytes < strlen($request)) {
				$urlinfo["error"]	= "Socket error: Could not send all data";
			}else{
				$data = "";
				while (!feof($fp)) 
				{
					$data .= fgets($fp, 4096);
				}
				
				fclose($fp);   
			
				list($headers, $body) = explode("\r\n\r\n", $data, 2);

				$parsed = $this->processHeaders($headers);

				$urlinfo["headers"]	= $parsed['headers'];
				$urlinfo["status"]	= $parsed ['status_code'];
				$urlinfo["html"]	= $body;
				
				if($ssl) error_reporting($error_reporting);

				if ((stripos($headers, "location:")) && ($maxredirs > 0))
				{
					
					preg_match("/\r\nlocation:(.*)/i", $headers, $match);

					if ($match)
					{    
						$redirect = trim($match[1]);						
						
						if(substr($redirect,0,1)=="/"){
							$redirect = $urlinfo["host"] . $redirect;
						}

						$maxredirs--;                         

						ShowSysDebug("redirect to $redirect");

						return $this->FSOCKGetURL($redirect, $maxredirs);
					}
				}   
				
				// inflate compressed content
				if (isset($urlinfo['headers']['content-encoding']) && ($urlinfo['headers']['content-encoding'] == 'gzip' || $urlinfo['info']['content-encoding'] == 'deflate'|| $urlinfo['headers']['content-encoding'] == 'x-gzip'))
				{
					// call decompress method to handle issues with certain sites e.g wordpress				
					$urlinfo["html"] = $this->Decompress($urlinfo["html"],$urlinfo['headers']['transfer-encoding']);

				}
			}
		}  
		
		// stop our timer
		$this->StopTime();

		// return array of header/html
		return $urlinfo;    
	}

	/**
	 * this is apparently written specifically to get round an issue with Wordpress
	 *
	 * @param string $gzData
	 * @return string
	 *
	 */
	protected function CompatibleGZInflate($gzData) {

		ShowSysDebug("IN compatible_gzinflate with " . strlen($gzData) . " worth of compressed content");

		if ( substr($gzData, 0, 3) == "\x1f\x8b\x08" ) {
			
			$i = 10;
			$flg = ord( substr($gzData, 3, 1) );
			
			if ( $flg > 0 ) {
				if ( $flg & 4 ) {
					list($xlen) = unpack('v', substr($gzData, $i, 2) );
					$i = $i + 2 + $xlen;
				}
				if ( $flg & 8 )
					$i = strpos($gzData, "\0", $i) + 1;
				if ( $flg & 16 )
					$i = strpos($gzData, "\0", $i) + 1;
				if ( $flg & 2 )
					$i = $i + 2;
			}
			return @gzinflate( substr($gzData, $i, -8) );
		} else {			
			return false;
		}
	}


		
	/**
	 * Puts together chunked content
	 *
	 * @param string $content
	 * @param string $rn
	 * @return string
	 *
	 */
	protected function Unchunk($content,$rn="\r\n"){

		$lrn = strlen($rn); 
		$str = ""; 
		$ofs = 0; 
		do{ 
			$p	 = strpos($content,$rn,$ofs); 
			$len = hexdec(substr($content,$ofs,$p-$ofs)); 
			$str .= substr($content,$p+$lrn,$len); 
			$ofs = $p+$lrn*2+$len; 
		}while ($content[$ofs]!=='0'); 
		
		return $str; 
	}


	/**
	 * Decompresses gziped content by various built in and custom methods
	 *
	 * @param string $compressed
	 * @param string $trans
	 * @return string
	 * 
	 */
	protected function Decompress( $compressed, $trans) {

		ShowSysDebug("DECOMPRESS! as the first 300 chars of content is " . substr($compressed,0,300));
		
		// if transfer-encoding: chunked then unchunk
		if(isset($trans) && $trans=="chunked"){

			ShowSysDebug("content is chunked so unchunk");

			$compressed = $this->Unchunk($compressed);
		}

		if ( false !== ($decompressed = @gzinflate( $compressed ) ) ){			
			return $decompressed;
		}	

		// do compatible test first as this checks for correct gzip header
		if ( false !== ( $decompressed = $this->CompatibleGZInflate( $compressed ) ) ){			
			return $decompressed;
		}		
		
		if ( false !== ( $decompressed = @gzuncompress( $compressed ) ) ){			
			return $decompressed;
		}
		
		// we may have another gzdecode function to try
		if ( function_exists('gzdecode') ) {
			$decompressed = @gzdecode( $compressed );

			if ( false !== $decompressed ){
				return $decompressed;
			}
		}

		// return original compressed	
		return $compressed;
	}




	public function processHeaders($headers) {
		// split headers, one per array element
		if ( is_string($headers) ) {
			// tolerate line terminator: CRLF = LF (RFC 2616 19.3)
			$headers = str_replace("\r\n", "\n", $headers);
			// unfold folded header fields. LWS = [CRLF] 1*( SP | HT ) <US-ASCII SP, space (32)>, <US-ASCII HT, horizontal-tab (9)> (RFC 2616 2.2)
			$headers = preg_replace('/\n[ \t]/', ' ', $headers);
			// create the headers array
			$headers = explode("\n", $headers);
		}
		
		$status_code= 0;
		$message	= "";
		$cookies	= array();
		$newheaders = array();

		foreach ( $headers as $tempheader ) {
			if ( empty($tempheader) )
				continue;

			if ( false === strpos($tempheader, ':') ) {
				list( , $iResponseCode, $strResponseMsg) = explode(' ', $tempheader, 3);
				$status_code	= $iResponseCode;
				$message		= $strResponseMsg;
				continue;
			}

			list($key, $value) = explode(':', $tempheader, 2);

			if ( !empty( $value ) ) {
				$key = strtolower( $key );
				if ( isset( $newheaders[$key] ) ) {
					$newheaders[$key] = array( $newheaders[$key], trim( $value ) );
				} else {
					$newheaders[$key] = trim( $value );
				}				
			}
		}

		return array('status_code' => $status_code, 'message' => $message, 'headers' => $newheaders);
	}

	/**
	 * Register AdminOptions with Wordpress
	 *
	 */
	public function RegisterAdminPage() {
		add_options_page('Strictly Software - System Checker', 'Strictly System Checker', 10, basename(__FILE__), array(&$this,'AdminOptions'));	
	}

	/**
	 * Admin page for backend management of plugin
	 *
	 */
	public function AdminOptions(){

		// ensure we are in admin area
		if(!is_admin()){
			die("You are not allowed to view this page");
		}

		$msg = $errmsg = "";

		$this->windows = ((substr(PHP_OS, 0, 3) == 'WIN') ? true : false);

		
		// get saved options
		$options	= $this->GetOptions();

		// set up the path to the URL that is called to run the report
		$cronurl = str_replace(StrictlyPluginTools::GetHomePath(),untrailingslashit(get_option('siteurl'))."/",trailingslashit(str_replace("\\","/",dirname(__FILE__)))) . 'strictly-system-check-report.php?code=' . $this->cron_code;


		// do we run the report now?
		if( isset($_POST['StrictlySystemCheck-runreport']) && $_POST['StrictlySystemCheck-runreport']!=""){

			// check nonce
			check_admin_referer("adminform","strictlysystemchecknonce");

			ShowSysDebug("manually run report");

			if($this->CheckSite()){
				$msg	.= "<p>" .__("The System Report ran successfully. Please review the report output for details of your systems current status.","StrictlySystemCheck") . "</p>" . $this->msg;
			}else{
				$errmsg .=  "<p>" .__("This System Report did not run successfully. Please review the report output for details of any problems it experienced when checking your site.</p>","StrictlySystemCheck") . "</p>" . $this->errmsg;				
			}
		}
		
		
		// do we test the config setup?		
		if( isset($_POST['testconfig']) && $_POST['testconfig']!=""){

			// check nonce
			check_admin_referer("adminform","strictlysystemchecknonce");

			ShowSysDebug("test config");

			// set a flag so we don't repeat config errors
			$configtest = true;

			if($this->TestConfig()){
				$msg	.= "<p>" .__("This plugin is configurated correctly and the system report should run successfully.","StrictlySystemCheck") . "</p>" . $this->msg;
			}else{
				$errmsg .=  "<p>" .__("This plugin is configured incorrectly and the system report will not run until all issues are resolved.</p>","StrictlySystemCheck") . "</p>" . $this->errmsg;				
			}
		}else{
			$configtest = false;
		}

		// if we ran a test then we will already have the mysql version otherwise get it now
		if($this->sqlversion == -1){

			// get MySQL version
			$this->sqlversion = $this->GetVersion();
		}
		

		// if form has been submitted then save new values
		if ( isset($_POST['StrictlySystemCheck-submit']) && $_POST['StrictlySystemCheck-submit']!="" )
		{

			// check nonce
			check_admin_referer("adminform","strictlysystemchecknonce");
			
			ShowSysDebug("set options from the form");

			$options['checkurl']			= trim(strip_tags(stripslashes($_POST['StrictlySystemCheck-checkurl'])));
			$options['emailreport']			= trim(strip_tags(stripslashes($_POST['StrictlySystemCheck-emailreport'])));
			$options['useragent']			= trim(strip_tags(stripslashes($_POST['StrictlySystemCheck-useragent'])));
			$options['timeout']				= trim(strip_tags(stripslashes($_POST['StrictlySystemCheck-timeout'])));			
			$options['searchtext']			= trim(strip_tags(stripslashes($_POST['StrictlySystemCheck-searchtext'])));
			$options['checkmode']			= trim(strip_tags(stripslashes($_POST['StrictlySystemCheck-checkmode'])));
			$options['slowquery']			= trim(strip_tags(stripslashes($_POST['StrictlySystemCheck-slowquery'])));
			$options['connectiontheshold']	= trim(strip_tags(stripslashes($_POST['StrictlySystemCheck-connectiontheshold'])));
			$options['loadthreshold']		= trim(strip_tags(stripslashes($_POST['StrictlySystemCheck-loadthreshold'])));	
			$options['check_db_if_slow_load_time']		= (bool)strip_tags(stripslashes($_POST['StrictlySystemCheck-check_db_if_slow_load_time']));	
			


			
			// only support optimize for MySQL version 5+
			if($this->sqlversion >= 5){
				
				$options['optimizetables']	= (bool)(strip_tags(stripslashes($_POST['StrictlySystemCheck-optimizetables'])));	
				
				$options['only_optimize_if_load_is_above_threshold']		= trim(strip_tags(stripslashes($_POST['StrictlySystemCheck-only_optimize_if_load_is_above_threshold'])));		
				
				ShowSysDebug("StrictlySystemCheck-only_optimize_if_load_is_above_threshold from post is " . trim(strip_tags(stripslashes($_POST['StrictlySystemCheck-only_optimize_if_load_is_above_threshold']))));

				if(!is_numeric($options['only_optimize_if_load_is_above_threshold'])){
			
					ShowSysDebug("not numeric");

					$errmsg .= "<p>". sprintf(__("The Optimize tables threshold limit value you entered %s is invalid and has been reset. Please enter a valid integer value.","StrictlySystemCheck"),$options['only_optimize_if_load_is_above_threshold']	) . "</p>";

					$options['only_optimize_if_load_is_above_threshold'] = (is_numeric($this->only_optimize_if_load_is_above_threshold)) ? $this->only_optimize_if_load_is_above_threshold : 0;
				}
				
				ShowSysDebug("now its StrictlySystemCheck-only_optimize_if_load_is_above_threshold from post is " . trim(strip_tags(stripslashes($_POST['StrictlySystemCheck-only_optimize_if_load_is_above_threshold']))));


			}else{
				$options['optimizetables']	= false;
				$options['only_optimize_if_load_is_above_threshold']	= 0;

				ShowSysDebug("set to 0");
			}
	
			$this->uninstall				= (bool)strip_tags(stripslashes($_POST['StrictlySystemCheck-uninstall']));

			
			// run some validation tests

			if(!StrictlyPluginTools::ValidEmail($options['emailreport'])){

				$errmsg .= "<p>". sprintf(__("The email address you entered %s is invalid and has been reset. Please enter a valid email address.","StrictlySystemCheck"),$options['timeout']	) . "</p>";

				$options['emailreport'] = "";
			}


			if(!is_numeric($options['timeout'])){
			
				$errmsg .= "<p>". sprintf(__("The timeout value you entered %s is invalid and has been reset. Please enter a valid integer value.","StrictlySystemCheck"),$options['timeout']	) . "</p>";

				$options['timeout'] = $this->timeout;
			}

			if(!is_numeric($options['slowquery'])){
			
				$errmsg .= "<p>". sprintf(__("The slow query value you entered %s is invalid and has been reset. Please enter a valid integer value.","StrictlySystemCheck"),$options['slowquery']	) . "</p>";

				$options['slowquery'] = $this->slowquery;

			}

			if(!is_numeric($options['connectiontheshold'])){
			
				$errmsg .= "<p>". sprintf(__("The connection threshold value you entered %s is invalid and has been reset. Please enter a valid integer value.","StrictlySystemCheck"),$options['connectiontheshold']	) . "</p>";

				$options['connectiontheshold'] = $this->connectiontheshold;

			}

			if(!is_numeric($options['loadthreshold'])){
			
				$errmsg .= "<p>". sprintf(__("The server load threshold value you entered %s is invalid and has been reset. Please enter a valid integer value.","StrictlySystemCheck"),$options['loadthreshold']	) . "</p>";

				$options['loadthreshold'] = $this->loadthreshold;

			}

			ShowSysDebug("Save options to DB");

			// save new values to the DB
			$this->SaveOptions($options);

			ShowSysDebug("after save options " . $this->only_optimize_if_load_is_above_threshold);


			$msg .= "<p>" . __("Options Saved","StrictlySystemCheck") ."</p>";

			// now rebuild our config file as we need a local copy of variables we use 
			if($this->CreateDBConfig()){
				$msg	.= "<p>" .__("The plugin configuration file was created successfully.","StrictlySystemCheck") . "</p>";
			}else{
				$errmsg .= "<p>" .  $this->configerrmsg . "</p>";				
			}

			
		}

		// try to load in our config file
		$gotconfig = $this->LoadPluginConfig();

		// skip errors if we ran a config test earlier
		if(!$configtest){
			// we haven't got a file yet so show a message
			if(!$gotconfig){
				$errmsg .= "<p>" . $this->configerrmsg . "</p>";
			}
		}


		if(!empty($errmsg)){
			echo '<div class="error">' . $errmsg . '</div>';
		}

		if(!empty($msg)){
			echo '<div class="updated">' . $msg . '</div>';
		}

		ShowSysDebug("so what are the options now");

	//	print_r($options);

		ShowSysDebug("useragent = " . $this->useragent);
		ShowSysDebug("timeout = " . $this->timeout);

		echo	'<style type="text/css">
				.tagopt{
					margin-top:7px;
				}
				.donate{
					margin-top:30px;
				}
				
				p.error{
					font-weight:bold;
					color:red;
				}
				p.msg{
					font-weight:bold;
					color:green;
				}
				#StrictlySystemCheckAdmin ul{
					list-style-type:circle !important;
					padding-left:18px;
				}
				#StrictlySystemCheckAdmin label{
					font-weight:bold;
				}
				#StrictlySystemCheckAdmin div.report{
					width:600px;
					height:470px;
					overflow:auto;
				}
				div.report{
					padding: 5px 5px 5px 5px;
					border: 1px solid black;
					background:white;
				}
				div label:first-child{					
					display:	inline-block;
					width:		250px;
				}
				span.notes{
					display:		block;
					padding-left:	5px;
					font-size:		0.8em;					
				}
				</style>';

		echo	'<div class="wrap" id="StrictlySystemCheckAdmin">';

		echo	'<h3>'.sprintf(__('Strictly Database Check - Version %s', 'StrictlySystemCheck'),$this->version).'</h3>';

		echo	'<p>'.__('Strictly Database Check is designed to allow you to monitor your website by periodically checking that your homepage is available and that your system is running correctly. Although this is not meant to be a replacement for professional server monitoring tools it is a nice addition for those administrators who want to monitor their site at regular intervals.', 'StrictlySystemCheck').'</p>
				<p>'.__('As well as checking that your site is running this plugin will also carry out database maintenence when required. For example sometimes tables and indexes within your database will become corrupt and cause your site to become unaccessible. This can happen after bulk inserts or large updates. This script will check for corrupt tables and will run any neccessary repair commands to try to remedy the situation.', 'StrictlySystemCheck').'</p>
				<p>'.__('To run this report you will need to setup either a CRON job on your server or if you don\'t have access to your server you can easily setup a WebCron job using one of the many free services available on the web.', 'StrictlySystemCheck').'</p>';


		echo	'<h4>'.__('Cron Command','strictlysitemap').'</h4>
				<div class="Cron">*/20 * * * * '. StrictlyPluginTools::GetCommand() . ' ' . attribute_escape($cronurl) . '</div>
				<h4>'.__('EasyCron URL','strictlysitemap').'</h4><p>If you cannot set up a cron job on your server or cannot create one from elsewhere then <a href="http://www.easycron.com?ref=13569" title="Easy Cron">EasyCron are a website that offer a very cheap way to run cron jobs at scheduled times</a> to pages of your choice. Visit their site at <a href="http://www.easycron.com?ref=13569" title="Easy Cron">EasyCron</a> to set up a scheduled cron job to the URL specified in the WebCron Ready URL section beneath.</p>				
				<h4>'.__('WebCron Ready URL','strictlysitemap').'</h4>
				<div class="Cron">' . attribute_escape($cronurl) . '</div>';	

		// output details of our last report
		$path	= str_replace("\\","/",dirname(__FILE__) . "/lastreport.txt");
		
		ShowSysDebug("do we have a system report>> $path");

		if(file_exists($path)){

			$report = file_get_contents($path);

			ShowSysDebug("yes we do with a len of " . strlen($report));

			if(!empty($report)){

				echo '<h3>'.__('Last Report Output', 'StrictlySystemCheck').'</h3>';

				echo '<div class="report">' . str_replace("\r\n","<br />",$report) . '</div>';
			}
		}

		

		echo	'<h3>'.__('Database Check Options', 'StrictlySystemCheck').'</h3>';

		
		echo	'<div><form method="post">
				'. wp_nonce_field("adminform","strictlysystemchecknonce",false,false);
	

		echo	'<p>' . __("This plugin requires write access to it's own folder to store the report as well as to maintain it's own configuration file. The plugin also requires HTTP access to make remote requests. You can test the plugins configuration by running the Test Configuration button.","StrictlySystemCheck") .'</p>

				<p class="submit"><input type="submit" name="testconfig" id="testconfig" value="'.__("Test Configuration","StrictlySystemCheck"). '" /></p>';

		ShowSysDebug("value of uninstall = " . $this->uninstall);

		echo	'<div class="tagopt">
				<label for="StrictlySystemCheck-uninstall">'.__('Uninstall on De-Activation','StrictlySystemCheck').'</label>
				<input type="checkbox" name="StrictlySystemCheck-uninstall" id="StrictlySystemCheck-uninstall" value="true" ' . (($this->uninstall) ? 'checked="checked"' : '') . '/>				
				<span class="notes">'.__('Will remove all configuration options related to the plugin when deactivated', 'StrictlySystemCheck').'</span>
				</div>';
		
		echo	'<div class="tagopt">
				<label for="StrictlySystemCheck-emailreport">'.__('Email Report To','StrictlySystemCheck').'</label>
				<input type="text" name="StrictlySystemCheck-emailreport" id="StrictlySystemCheck-emailreport" value="' . $this->emailreport. '" size="100" maxlength="200" />				
				<span class="notes">'.__('Enter the email address of the person you want to send the report to. Reports are only sent if there are issues with the website e.g the site is down or the database couldn\'t be connected to.', 'StrictlySystemCheck').'</span>
				</div>';

		echo	'<div class="tagopt">
				<label for="StrictlySystemCheck-checkurl">'.__('Check URL','StrictlySystemCheck').'</label>
				<input type="text" name="StrictlySystemCheck-checkurl" id="StrictlySystemCheck-checkurl" value="' . $this->checkurl. '" size="100" maxlength="200" />				
				<span class="notes">'.__('Enter the URL that you would like to check at scheduled intervals.', 'StrictlySystemCheck').'</span>
				</div>';


		echo	'<div class="tagopt">
				<label for="StrictlySystemCheck-timeout">'.__('Timeout','StrictlySystemCheck').'</label>
				<input type="text" name="StrictlySystemCheck-timeout" id="StrictlySystemCheck-timeout" value="' . $this->timeout . '" maxlength="3" />	
				<span class="notes">'.__('The length of time in seconds to wait when testing the page.', 'StrictlySystemCheck').'</span>
				</div>';




		echo	'<div class="tagopt">
				<label for="StrictlySystemCheck-check_db_if_slow_load_time">'.__('Check DB When Load Time Is Over Timeout','StrictlySystemCheck').'</label>
				<input type="checkbox" name="StrictlySystemCheck-check_db_if_slow_load_time" id="StrictlySystemCheck-check_db_if_slow_load_time" value="true" ' . (($this->check_db_if_slow_load_time) ? 'checked="checked"' : '') . '/>				
				<span class="notes">'.__('Will check the DB when the page load time is above the timeout specified above.', 'StrictlySystemCheck').'</span>
				</div>';


				
		echo	'<div class="tagopt">
				<label for="StrictlySystemCheck-useragent">'.__('User-Agent','StrictlySystemCheck').'</label>
				<input type="text" name="StrictlySystemCheck-useragent" id="StrictlySystemCheck-useragent" value="' . $this->useragent. '" size="100" maxlength="200" />				
				<span class="notes">'.__('The User-Agent to use when making remote requests. Please use an agent that is not going to be blocked by htaccess rules or other blocking mechanisms.', 'StrictlySystemCheck').'</span>
				</div>';

		echo	'<div class="tagopt">
				<label for="StrictlySystemCheck-checkmode">'.__('Repair Mode','StrictlySystemCheck').'</label>
				<input type="radio" name="StrictlySystemCheck-checkmode" id="StrictlySystemCheck-checkmode-fast" value="FAST" ' . (($this->checkmode=="FAST") ? "checked=\"checked\" " : "") . ' /><label for="StrictlySystemCheck-checkmode-fast">' .__('FAST','StrictlySystemCheck'). '</label>
				<input type="radio" name="StrictlySystemCheck-checkmode" id="StrictlySystemCheck-checkmode-extended" value="EXTENDED" ' . (($this->checkmode=="EXTENDED") ? "checked=\"checked\" " : "") . ' /><label for="StrictlySystemCheck-checkmode-extended">' .__('EXTENDED','StrictlySystemCheck'). '</label>				
				<span class="notes">'.__('When the system checks the state of your database it can either carry out a fast or extended check. The fast check is not as extensive but does take less time to carry out. If you have lots of large tables within your database then I would recommend the fast option.', 'StrictlySystemCheck').'</span>
				</div>';

		// only support this for MySQL versions 5+
		if($this->sqlversion >= 5){	

			echo	'<div class="tagopt">
					<label for="StrictlySystemCheck-optimizetables">'.__('Optimize Fragmented Tables','StrictlySystemCheck').'</label>
					<input type="checkbox" name="StrictlySystemCheck-optimizetables" id="StrictlySystemCheck-optimizetables" value="true" ' . (($this->optimizetables) ? 'checked="checked"' : '') . '/>				
					<span class="notes">'.__('Whether to check for and repair any fragmented tables using the OPTIMIZE command. Fragmented tables can slow down query times and will occur when records are deleted or inserted.', 'StrictlySystemCheck').'</span>
					</div>';		

			
			echo	'<div class="tagopt">
					<label for="StrictlySystemCheck-only_optimize_if_load_is_above_threshold">'.__('Only Optimize When Load Is Above','StrictlySystemCheck').'</label>
					<input type="text" name="StrictlySystemCheck-only_optimize_if_load_is_above_threshold" id="StrictlySystemCheck-only_optimize_if_load_is_above_threshold" value="' . $this->only_optimize_if_load_is_above_threshold. '" size="20" maxlength="4" />				
					<span class="notes">'.__('If you want to Optimize your tables when the database or website is having problems you can specify to only carry this action out if the server load is above a certain amount. Remember carrying out lots of Optimize statements can lock your system up. Set to 0 to always Optimize.', 'StrictlySystemCheck').'</span>
					</div>';

					
		}

		echo	'<div class="tagopt">
				<label for="StrictlySystemCheck-slowquery">'.__('Slow Query Duration','StrictlySystemCheck').'</label>
				<input type="text" name="StrictlySystemCheck-slowquery" id="StrictlySystemCheck-slowquery" value="' . $this->slowquery. '" size="20" maxlength="2" />				
				<span class="notes">'.__('The length of time in seconds that a running SQL query is considered too long. The wordpress database and related plugins will make many database queries on each page load and when everything is running smoothly these queries should run very quickly e.g < 1 second. Lots of slow running queries will cause page load delays and other problems which result in a poor user experience. When the system checks the site it will look at queries currently being executed and can flag any problems related to long running queries.', 'StrictlySystemCheck').'</span>
				</div>';

		echo	'<div class="tagopt">
				<label for="StrictlySystemCheck-connectiontheshold">'.__('Database Connection Warning Threshold','StrictlySystemCheck').'</label>
				<input type="text" name="StrictlySystemCheck-connectiontheshold" id="StrictlySystemCheck-connectiontheshold" value="' . $this->connectiontheshold. '" size="20" maxlength="2" />				
				<span class="notes">'.__('If the number of open database connections at the time of checking is over the threshold percentage set here then a warning email will be sent. Your wordpress database is set to limit database connections to an amount specified in the MySQL configuration file my.cnf the threshold percentage set here will use that figure as the benchmark.', 'StrictlySystemCheck').'</span>
				</div>';

		
		
		// for windows servers we can only take a snapshot of CPU usage at the point in time we check the server. However this would tell us if the server
		// was maxed out e.g running at 100%. Whilst with LINUX boxes we can use uptime to get the actual load averages which are a better indicator of stress levels
		if($this->windows){

			echo	'<div class="tagopt">
					<label for="StrictlySystemCheck-loadthreshold">'.__('Server Load Warning Threshold','StrictlySystemCheck').'</label>
					<input type="text" name="StrictlySystemCheck-loadthreshold" id="StrictlySystemCheck-loadthreshold" value="' . $this->loadthreshold. '" size="20" maxlength="4" />					
					<span class="notes">'.__('If your system is setup to allow webusers to access WMI COM objects then the scheduled site test can check for an overloaded system by examining the CPU usage of the server. You can set the threshold limit that triggers a warning report here. If the CPU usage is 100% then the system is overloaded and running full out.', 'StrictlySystemCheck').'</span>
					</div>';
		}else{

			echo	'<div class="tagopt">
					<label for="StrictlySystemCheck-loadthreshold">'.__('Server Load Warning Threshold','StrictlySystemCheck').'</label>
					<input type="text" name="StrictlySystemCheck-loadthreshold" id="StrictlySystemCheck-loadthreshold" value="' . $this->loadthreshold. '" size="20" maxlength="4" />					
					<span class="notes">'.__('If your system is setup to allow webusers to run system commands (e.g with the system, exec or passthru functions) then the scheduled site test can check for an overloaded system by examining the output of the uptime function. You can set the threshold limit that triggers a warning report here. A server load value of 1 or more means that there is an overload and a queue of pending processes.', 'StrictlySystemCheck').'</span>
					</div>';
		}

		echo	'<div class="tagopt">
				<label for="StrictlySystemCheck-searchtext">'.__('Search Text','StrictlySystemCheck').'</label>
				<input type="text" name="StrictlySystemCheck-searchtext" id="StrictlySystemCheck-searchtext" value="' .$this->searchtext . '" maxlength="100" size="100" />				
				<span class="notes">'.__('If you would like to search for a specific piece of text or content on your homepage when checking your site enter it here. If this text is not found then the system will consider this a sign of failure so please ensure that the text would always be there during normal site activity.', 'StrictlySystemCheck').'</span>
				</div>';

		// check that the local DB constant file exists and if not offer to build it
		if(!$gotconfig){

				echo	'<div class="tagopt">
						<h3>'.__('Create Database Config File','StrictlySystemCheck').'</h3>
						<p>'.__('As the Strictly Database Checker script runs independantly of Wordpress it requires it\'s own configuration file so it can connect to the database without having to load in any other required files. You can either manually create the file with the specified content outlined below at the following location or try to let the system create the file for you. Whenever the main plugin configuration options are saved this file is updated.','StrictlySystemCheck').'</p>
						<label>'.__('Create File at this location: ','StrictlySystemCheck').'</label><strong>' . dirname(__FILE__) . '/dbconfig.php</strong>
						<textarea readonly="readonly" style="width:600px;height:600px;" id="config" name="config">' . htmlentities($this->GetDBConfig()) . '</textarea>						
						</div>';
		}


		echo	'<p class="submit"><input value="'.__('Save Options', 'StrictlySystemCheck').'" type="submit" id="StrictlySystemCheck-submit" name="StrictlySystemCheck-submit" /><input value="'.__('Test Report', 'StrictlySystemCheck').'" type="submit" id="StrictlySystemCheck-runreport" name="StrictlySystemCheck-runreport" /></form></p></div>';

		echo	'<div class="donate"><h3>'.__('Donate to Stictly Software', 'StrictlySystemCheck').'</h3>';

		echo	'<p>'.__('Your help ensures that my work continues to be free and any amount is appreciated.', 'StrictlySystemCheck').'</p>';
		
		echo	'<div style="text-align:center;"><br />
				<form action="https://www.paypal.com/cgi-bin/webscr" method="post"><br />
				<input type="hidden" name="cmd" value="_s-xclick"><br />
				<input type="hidden" name="hosted_button_id" value="6427652"><br />
				<input type="image" src="https://www.paypal.com/en_GB/i/btn/btn_donateCC_LG.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online.">
				<br /></form></div></div>';


		echo	'<div class="recommendations"><p>'.__('If you enjoy using this Wordpress plugin you might be interested in some other websites, tools and plugins I have		developed.', 'StrictlySystemCheck').'</p>
					<ul>
						<li><a href="http://www.strictly-software.com/plugins/strictly-auto-tags">'.__('Strictly AutoTags','strictlyautotags').'</a>
							<p>'.sprintf(__('Strictly AutoTags comes in two versions a free version from WordPress with less features and a PRO version for &pound;40. Not only does the PRO version offer the ability to use equivalent words for tags e.g match the words <strong>Snowden, NSA and GCHQ</strong> in an article but tag the word <strong>Internet Privacy</strong>. You can also set &quot;Top Tags&quot; that should be relevant to your sites main SEO keywords as they get ranked higher than any other word found in the article. As all tags are ordered by relevancy only the most relevant tags are used by the system. The PRO versiom also has many new functions to match words like al-Qaeda or 1,000 Guineas, convert plain (strong and weak) text links into real clickable links, set minimum character lengths and maximum words a tag must have as well as a new cleanup mode that lets you edit individual articles and remove any SEO HTML the plugin adds if you want it to. There is also a new function to remove basic styling tags if you need to and when used with <strong>Strictly TweetBOT PRO</strong> it is awesome for <strong>AutoBlogging</strong> as the tags found by this plugin can also be used as #HashTags in your tweets. You can buy this plugin direct from my site. However if everyone using this plugin who liked it donated me a single &pound; I wouldn\'t need to do this so please consider it if you like this plugins features.','StrictlySystemCheck'), $this->dontate_version).'</p>
						</li>
						<li><a href="http://wordpress.org/extend/plugins/strictly-tweetbot/">'.__('Strictly Tweetbot','strictlyautotags').'</a>
							<p>'.__('Strictly Tweetbot is a Wordpress plugin that allows you to automatically post tweets to multiple accounts or multiple tweets to the same account whenever a post is added to your site. Features include: Content Analysis, Tweet formatting and the ability to use tags or categories as hash tags, OAuth PIN code authorisation and Tweet Reports. When used with Strictly AutoTags the tweets are only sent out AFTER tagging has been completed so that they can be used as #HashTags. With the PRO version that costs a mere &pound;25 you can stagger the tweets out to prevent Twitter Rushes, force new articles to be cached before visitors hit the site and set limits on your Tweet content and sizes.','StrictlySystemCheck').'</p>
						</li>
						<li><a href="http://www.ukhorseracingttipster.com">'.__('UK Horse Racing Tipster','StrictlySystemCheck').'</a>
							<p>'.__('A top tipping site for horse racing fans with racing news, free tips to your email inbox and a premium service that <strong>offers a high return on investment and profitable horse racing tips each day.</strong> From Lay, Place to Win tips we have over 63 NAP tipsters providing NAPs each day and two lots of members only tips from <strong>over 517 systems, LAY, WIN and PLACE TIPS, some that provide over &pound;3,700 a month!</strong>','StrictlySystemCheck').'</p>
						</li>
						<li><a href="http://www.fromthestables.com">'.__('From The Stables','StrictlySystemCheck').'</a>
							<p>'.__('If you like horse racing or betting and want that extra edge when using Betfair then this site is for you. It\'s a members only site that gives you inside information straight from the UK\'s top racing trainers every day. We reguarly post up to 5 winners a day and our members have thousands since we started earlier this year.','StrictlySystemCheck').'</p>
						</li>
						<li><a href="http://www.darkpolitricks.com">'.__('Dark Politricks','StrictlySystemCheck').'</a>
							<p>'.__('Tired of being fed news from inside the box? Want to know the important news that the mainstream media doesn\'t want to report on? Then this site is for you. Alternative news, comment and analysis all in one place. Great essays, rants and opinion from the other side of the media.','StrictlySystemCheck').'</p>
						</li>						
					</ul>
				</div>';
	}

	/**
	 * save new options to the DB and reset internal members
	 *
	 * @param object $object
	 */
	protected function SaveOptions($options){

		ShowSysDebug("IN SaveOptions");

	//	print_r($options);

		update_option('strictly_system_check_options', $options);
		
		update_option('strictly_system_check_uninstall',$this->uninstall);
		
		// set internal members
		$this->SetValues($options);
	}
	
	/**
	 * sets internal member properties with the values from the options array
	 *
	 * @param object $object
	 */
	protected function SetValues($options){

		ShowSysDebug("IN SetValues");

	//	print_r($options);

		$this->checkurl		= (StrictlyPluginTools::IsNothing($options["checkurl"]))	? get_option('siteurl') : $options["checkurl"];

		$this->emailreport	= (StrictlyPluginTools::IsNothing($options["emailreport"]))	? $this->emailreport	: $options["emailreport"];		
	
		$this->useragent	= (StrictlyPluginTools::IsNothing($options["useragent"]))	? $this->useragent		: $options["useragent"];

		$this->timeout		= (StrictlyPluginTools::IsNothing($options["timeout"]))		? $this->timeout		: $options["timeout"];

		$this->searchtext	= (StrictlyPluginTools::IsNothing($options["searchtext"]))	? ""					: $options["searchtext"];
	
		$this->checkmode	= (StrictlyPluginTools::IsNothing($options["checkmode"]))	? $this->checkmode		: $options["checkmode"];

		$this->slowquery	= (StrictlyPluginTools::IsNothing($options["slowquery"]))	? $this->slowquery		: $options["slowquery"];

		$this->loadthreshold		= (StrictlyPluginTools::IsNothing($options["loadthreshold"]))		? $this->loadthreshold			: $options["loadthreshold"];
		
		$this->connectiontheshold	= (StrictlyPluginTools::IsNothing($options["connectiontheshold"]))	? $this->connectiontheshold		: $options["connectiontheshold"];

		$this->optimizetables		= (StrictlyPluginTools::IsNothing($options["optimizetables"]))		? $this->optimizetables			: $options["optimizetables"];

		
		$this->check_db_if_slow_load_time = (StrictlyPluginTools::IsNothing($options["check_db_if_slow_load_time"]))		? $this->check_db_if_slow_load_time			: $options["check_db_if_slow_load_time"];

		$this->only_optimize_if_load_is_above_threshold = (StrictlyPluginTools::IsNothing($options["only_optimize_if_load_is_above_threshold"]))		? $this->only_optimize_if_load_is_above_threshold			: $options["only_optimize_if_load_is_above_threshold"];
	 


		ShowSysDebug("useragent = " . $this->useragent);
		ShowSysDebug("checkurl = " . $this->checkurl);
	}


	/**
	 * get saved options otherwise use defaults
	 *	 
	 * @return array
	 */
	protected function GetOptions(){

		ShowSysDebug("IN GetOptions");

		// get saved options from wordpress DB
		$options			= get_option('strictly_system_check_options');

		$this->uninstall	= get_option('strictly_system_check_uninstall');

		$this->cron_code	= get_option('strictly_system_check_croncode');

	//	print_r($options);

		$this->SetValues($options);

		ShowSysDebug("useragent = " . $this->useragent);
		ShowSysDebug("checkurl = " . $this->checkurl);
		ShowSysDebug("timeout = " . $this->timeout);
		ShowSysDebug("connectiontheshold = " . $this->connectiontheshold);
		ShowSysDebug("loadthreshold = " . $this->loadthreshold);		
		ShowSysDebug("emailreport = " . $this->emailreport);
		ShowSysDebug("slow query = " . $this->slowquery);
		ShowSysDebug("uninstall = " .$this->uninstall);
		ShowSysDebug("cron_code = " .$this->cron_code);
		ShowSysDebug("check mode = " . $this->checkmode);
		ShowSysDebug("optimizetables = " . $this->optimizetables);
		ShowSysDebug("check_db_if_slow_load_time = " . $this->check_db_if_slow_load_time);
		ShowSysDebug("only_optimize_if_load_is_above_threshold = " . $this->only_optimize_if_load_is_above_threshold);

	}
}	