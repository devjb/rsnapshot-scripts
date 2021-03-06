#!/usr/bin/php
<?php
/*
 * rsnapshot-once
 * Copyright (C) 2013 Philipp C. Heckel <philipp.heckel@gmail.com> 
 *
 * Original blog post at:
 *    http://blog.philippheckel.com/2013/06/28/script-run-rsnapshot-backups-only-once-and-rollback-failed-backups-using-rsnapshot-once/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
 
$opts = getopt("c:");
// General failure	
if (!$opts) {
	die(
		 "Usage:\n"
		. " {$argv[0]} [-c cfgfile] (daily|weekly|monthly)\n\n"
		 
		."Description:\n"
		."  rsnapshot-once is a wrapper for rsnapshot to ensure that daily, weekly\n"
		."  and monthly tasks are run only once in the respective time period, i.e.\n"
		."  it ensures that 'weekly' backups are executed only once a week,\n"
		."  regardless how often rsnapshot-timer is called.\n\n"
		."  rsnapshot-once accepts the same parameters as rsnapshot and uses the\n"
		."  same config file. It does not need any additional configuration.\n\n"
	
		."Example (crontab):\n"
		."  # Job to run every hour, rsnapshot-once makes sure it only runs once a day.\n"
		."  0 * * * * {$argv[0]} -c /home/user/.rsnapshot/rsnapshot.home.conf daily\n"
	);
}
//print_r($opts);
// -c (Config file)
if (!isset($opts['c'])) {
	$opts['c'] = "/etc/rsnapshot.conf";
}
else if (isset($opts['c']) && !file_exists($opts['c'])) {
	die("Config file {$opts['c']} does not exist.\n");
}
$cfgfile = $opts['c'];
// Read logfile
$logfile = trim(`cat '{$opts['c']}' | grep '^logfile'`);
if (!preg_match('!^logfile\t(.+)$!', $logfile, $m)) {
	die("Config option 'logfile' not found in config file.\n");
}
$logfile = $m[1];	
logft("## STARTING BACKUP ######################\n");
$xargs = $argv; array_shift($xargs);
logft("\$ ".basename($argv[0])." '".join("' '", $xargs)."'\n");
logft("Config read from: $cfgfile\n");
logft("- logfile = $logfile\n");
// Read snapshot_root	
$snapshot_root = trim(`cat '{$opts['c']}' | grep '^snapshot_root'`);
if (!preg_match('!^snapshot_root\t(.+)$!', $snapshot_root, $m)) {
	logft("Config option 'snapshot_root' not found in config file. EXITING.\n");
	exit;
}
$snapshot_root = $m[1];	
logft("- snapshot_root = $snapshot_root\n");
if (!preg_match('!/$!', $snapshot_root)) {
	logft("Invalid config. 'snapshot_root' has no trailing slash. EXITING.\n");
	logft("## BACKUP ABORTED #######################\n\n");	
	exit;
}
// Other argument (weekly, daily, monthly)
$jobname = $argv[count($argv)-1];
if (!in_array($jobname, array("daily", "weekly", "monthly"))) {
	logft("Jobname must be 'daily', 'weekly' or 'monthly'. EXITING.\n");
	logft("## BACKUP ABORTED #######################\n\n");	
	exit;
}
// Check pid file
$pidfile = "{$snapshot_root}.rsnapshot-once.pid";
logft("Checking rsnapshot-once pidfile at $pidfile ... ");
if (file_exists($pidfile)) {
	$pidfilepid = trim(file_get_contents($pidfile));
	if (file_exists("/proc/$pidfilepid")) {
		logf("Exists. PID $pidfilepid still running. ABORTING.\n");
        logft("## BACKUP ABORTED #######################\n\n");
    	exit;		
	}
	else {
		logf("Exists. PID $pidfilepid not running. Script crashed before.\n");
		$sorted_backups = glob("{$snapshot_root}$jobname.*");
		echo ": ".join($sorted_backups);
		exit;
		natsort($sorted_backups);
		if (count($sorted_backups) == 0) {
			logft("No previous backups found. No cleanup necessary.\n");
		}
		else {						
			logft("Cleaning up unfinished backup ...\n");
			$first_backup = array_shift($sorted_backups);
			logft("- Deleting $first_backup ... ");
		
			// Double check before deleting (!)
			if (!isset($jobname) || empty($jobname) || !file_exists($first_backup) || strlen($first_backup) < 2 || !preg_match('/^(.+)\.(\d+)$/', $first_backup)) {
				logf("Script security issue. EXITING.\n");
			    logft("## BACKUP ABORTED #######################\n\n");
		    	exit;
		   	}					
		   	else {
		   		// Delete!
		   		$slashed_first_backup = addslashes($first_backup);
		   		`rm -rf '$slashed_first_backup'`;
		   		logf("DONE\n");
		   	}
		
			while (count($sorted_backups) > 0) {
				$nth_backup = array_shift($sorted_backups);
			
				if (preg_match('/^(.+)\.(\d+)$/', $nth_backup, $m)) {
					$n_minus_1th_backup = $m[1].".".($m[2]-1);
					logft("- Moving $nth_backup to $n_minus_1th_backup ... ");
					rename($nth_backup, $n_minus_1th_backup);
					logf("DONE\n");
				}
			}
		}
	}		
}
else {
	logf("Does not exist. Last backup was clean.\n");
}
logft("Checking delays (minimum 15 minutes since startup/wakeup) ...\n");
// Backup delay (uptime/resumetime + 15 minutes)
$uptime_minutes = 0;
$uptime_minutes = strtok(exec("cat /proc/uptime"), ".")/60;
if ($uptime_minutes) {
	if ($uptime_minutes < 15) {
		logft("- Computer uptime is ".sprintf("%.1f", $uptime_minutes)." minutes. NOT ENOUGH. EXITING.\n");
		logft("## BACKUP ABORTED #######################\n\n");
		exit;
	}
	else {
		logft("- Computer uptime is ".sprintf("%.1f", $uptime_minutes)." minutes. THAT'S OKAY.\n");
	}
}
// Get time of resume
// http://unix.stackexchange.com/questions/22140/determine-time-of-last-suspend-to-ram
$wakeup_minutes = 0;
$wakeup_date = trim(`egrep 'Running hooks for (resume|thaw)' /var/log/pm-suspend.log | tail -n 1 | sed 's/^\(.*\):.*$/\\1/'`);
if (isset($wakeup_date) && $wakeup_date) {
	$wakeup_minutes = (time() - intval(`date --date="$wakeup_date" +%s`))/60;
	if ($wakeup_minutes) {
		if ($wakeup_minutes < 15) {
			logft("- Computer resume time is ".sprintf("%.1f", $wakeup_minutes)." minutes. NOT ENOUGH. EXITING.\n");
			logft("## BACKUP ABORTED #######################\n\n");
			exit;
		}
		else {
			logft("- Computer resume time is ".sprintf("%.1f", $wakeup_minutes)." minutes. THAT'S OKAY.\n");
		}
	}
}
// Get date of newest folder (e.g. weekly.0, daily.0, monthly.0)
// to figure out if the job needs to run
$newest_backup_folder = "{$snapshot_root}{$jobname}.0";
if (!file_exists($newest_backup_folder)) {
	logft("No backup exists for job '$jobname' at '$newest_backup_folder'.\n");
	$job_needs_to_run = true;
}
else {
	$backuptime = filemtime($newest_backup_folder);
	logft("Newest backup for '$jobname' at $newest_backup_folder was at ".date("d/M/Y, H:i:s", $backuptime).".\n");
	if ($jobname == "daily") {
		$job_needs_to_run = time() - $backuptime > 23*60*60;
		$text_between_runs = sprintf("%.1f", (time() - $backuptime)/60/60)." hour(s)";
		$text_min_time = "23 hours";		
	}
	else if ($jobname == "weekly") {
		$job_needs_to_run = time() - $backuptime > 6.5*24*60*60;
		$text_between_runs = sprintf("%.1f", (time() - $backuptime)/60/60/24)." day(s)";
		$text_min_time = "6.5 days";		
	}
	else if ($jobname == "monthly") {
		$job_needs_to_run = time() - $backuptime > 29*24*60*60;
		$text_between_runs = sprintf("%.1f", (time() - $backuptime)/60/60/24)." day(s)";
		$text_min_time = "29 days";		
	}
	else {
		logft("Error: This should not happen. ERROR.\n");
		exit;
	}
	if (!$job_needs_to_run) {
		logft("Job does NOT need to run. Last run is only $text_between_runs ago (min. is $text_min_time). EXITING.\n");
		logft("## BACKUP ABORTED #######################\n\n");
		exit;
	}			
	else {
		logft("Last run is $text_between_runs ago (min. is $text_min_time).\n");
	}
}
logft("Writing rsnapshot-once pidfile (PID ".getmypid().") to ".$pidfile.".\n");
file_put_contents($pidfile, getmypid());
logft("NOW RUNNING JOB: ");
array_shift($argv);
$escaped_pidfile = addslashes($pidfile);
$cmd = "rsnapshot '".join("' '", $argv)."' ".'2>&1';
logf("$cmd\n");
$exitcode = -1;
$configerror = false;
$output = array();
exec($cmd, $output, $exitcode);
foreach ($output as $outline) {
	logft("  rsnapshot says: $outline\n");
	
	if (preg_match("/rsnapshot encountered an error/", $outline)) {
		$configerror = true;
	}
}
if ($configerror) {
		logft("Exiting rsnapshot-once, because error in rsnapshot run detected.\n");
		logft("Removing rsnapshot-once pidfile at ".$pidfile." (CLEAN EXIT).\n");
		unlink($pidfile);
		
		logft("## BACKUP ABORTED #######################\n");
		exit;
}
// pidfile should NOT exist if exit was clean
if ($exitcode == 1) { // 1 means 'fatal error' in rsnapshot terminology
	logft("No clean exit. Backup aborted. Cleanup necessary on next run (DIRTY EXIT).\n");
	logft("## BACKUP ABORTED #######################\n");
	exit;
}
logft("Removing rsnapshot-once pidfile at ".$pidfile." (CLEAN EXIT).\n");
unlink($pidfile);
logft("Rotating log ...\n");
logrotate();
logft("## BACKUP COMPLETE ######################\n\n");
#### FUNCTIONS #############################################################
// log with time 
function logft($s) {
	logf("[".date("d/M/Y:H:i:s")."/rsnapshot-once] ".$s);
}
// log without time
function logf($s) {
	echo $s;
	
	if (isset($GLOBALS['logfile'])) {
		file_put_contents(trim($GLOBALS['logfile']), $s, FILE_APPEND | LOCK_EX);	
	}
}
// rotate log
function logrotate() {
	$logfile = $GLOBALS['logfile'];
	if (isset($GLOBALS['logfile'])) {
		`tail -n 1000 $logfile > $logfile.tmp`;
		`mv $logfile.tmp $logfile`;
	}
}
?>
