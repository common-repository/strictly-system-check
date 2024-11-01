=== Strictly System Check ===
Contributors: Strictly Software
Donate link: http://www.strictly-software.com/donate
Plugin Home: http://www.strictly-software.com/plugins/strictly-system-check
Tags: Site, Monitoring, Uptime, Load, Database, Repair, Overload, HTTP
Requires at least: 2.0.2
Tested up to: 4.5
Stable tag: 1.0.9

Strictly System Check is a plugin that periodically checks your site checking your database and server and reporting if any problems are found.


== Description ==

Strictly System Check is a site monitoring tool that allows website administrators who may not have access to professional monitoring tools
the ability to regularly check the status of their site and to be notified if the site goes down or becomes overloaded.

I created this plugin initially for my own use as on one of my sites I regularly import content from XML feeds and I noticed that occasionally
after a large update my site would suffer problems such as:
	* Error establishing a database connection, error appearing when I tried to access the site even though the configuration was correct.
	* All my articles and other content disappearing from the site.
	* A high server load that didn't reduce quick enough after the import had finished

I soon realised that this was down to one or more of the MyISAM database tables in the MySQL database becoming corrupted which meant that the tables
were out of action, data couldn't be retrieved and requests to the system were quickly building up. Running a REPAIR statement on the database
always seemed to fix the problem. Therefore as I wasn't always able to catch this problem when it occurred I thought I would automate a process that would
check the homepage at regular intervals and on finding the database connection error it would then check the database for corrupt tables and
automatically repair them. 

This was the primary reason for the plugin and I soon extended it to offer some more features such as

* The option to choose which URL is scanned when the system check is carried out
* The ability to search for a key phrase or piece of text in the source code and to raise a report if it's not found
* The system also checks the server load and can raise a report if it's over a specified limit
* The system also checks the database load to ensure that it's not overloaded and not running too many queries
* The system is not suffering poor performance due to fragmented table indexes
* Reports on the number of connections, queries, reads, writes, aborted connections, slow queries

The site administrator can specify their own threshold limits for the webserver and database loads and a report will be emailed out
whenever these limits are reached or if the site is inaccessible.

The report will return details of the response time it took to load the page, the current server load, the current database load and whether or not
there were connection problems, issues with corrupt tables that needed repairing or fragmented indexes that were optimized.

Whilst not a replacement for professional server monitoring tools it is a nice easy to use plugin that can help notify you when your site is down
as well as rescuing your system from corrupt database tables before you even realise there has been an issue.

== Installation ==

This section describes how to install the plugin and get it working.

1. Download the plugin.
2. Unzip the strictly-system-check compressed file.
3. Upload the directory strictly-system-check to the /wp-content/plugins directory on your WordPress blog.
4. Activate the plugin through the 'Plugins' menu in WordPress.
5. Use the newly created Admin option within Wordpress titled Strictly System Check to set the configuration options for the plugin.
6. Use the Test Configuration button to ensure that your plugin will work correctly.
7. Set up a CRON job or WebCron job to run the system checker at intervals of your choice. The plugin will display the correct code and URL's to use
   for any CRON job.


Help 

1. You may need to grant write/execute permission for the plugin folder to the website so that the configuration file and report can be written out correctly.
   The plugin will give you the correct CHMOD code to run to grant these permissions.

2. To access the current webserver load averages the website will need to be able to run system functions such as e.g shell_exec, system, passthru.
   If you are running you own server or virutal server this shouldn't be a problem but if you are on a shared server you might have to ask your systme administrator
   to set the relevant permissions for you to be able to do this. The Test Configuration button will tell you whether or not this is a problem. Even if you cannot
   run system functions the plugin will still be able to report on key info such as whether the site is up or down and whether the database is overloaded or not.


== Changelog ==

= 1.0.1 =
* Added extra check for system functions in the configuration test

= 1.0.2 =
* Added the option to carry out a test for fragmented tables and then a repair using the OPTIMIZE command
* Added new database reports such as the percentage of queries that are slow, the number of joins without indexes, the number of reads & writes and much more
* Changed the formatting and layout of the admin page
* Added an extra test for MySQL version in the test configuration option
* Added code to inform the user if the plugin gets updated with new options so that they know to re-configure their plugin
* Added nonces and is_admin checks to the admin page

= 1.0.3 =
* Added code to handle REPAIRS if the database is overloaded
* Added extra logging for the REPAIRS if they are carried out OR not carried out
* Tested with latest version of Wordpress 3.5.2

= 1.0.4 =
* Added code to only OPTIMIZE if the server load is above a certain level
* Added code to run DB checks if the page load is too slow

= 1.0.5 =
* Ensured the system works with Wordpress 3.6
* Updated Readme.txt with information on how to debug any problems

= 1.0.6 =
* Added code to allow users to choose an external WP Cron option to run their jobs from
* Added code so if page response is blank its same as a critical error
* Added some options to the error email for people to try if their site is down
* Changed the check config to check for empty responses and 500 status codes

= 1.0.7 =
* Added code to show the PHP memory usage at the time of the report running
* Added code to show the output of the "free" command and the free Apache memory
* Added extra fixes that could be done
* Informed users about the great mix between Strictly AutoTags Premium and Strictly TweetBOT PRO

= 1.0.8 =
* Added code to fix free memory usage
* Added code to show total memory available
* Added code to show total disk swap memory available
* Added code to show the disk swap usage
* Added extra fix options to increase RAM, reduce disk swapping, reduce bandwidth, reduce load.

= 1.0.9 =
* Added code to check for reverse proxy error codes like CloudFlare messages
* Added code to return the current user the system is using
* Added code to ensure that if the server is very quiet but long page loads a warning is sent
* Added warnings about disk swap usage