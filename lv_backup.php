<?php
require_once("classCommandLine.php");

// Use the local hostname to prevent collisions between multiple backup servers
// that might touch the same server simultaneously
$hostname = gethostname();

// Get info from command line args
$args = CommandLine::parseArgs($_SERVER['argv']);
$host=( (array_key_exists("host", $args) )?($args["host"]):(false) ) ;
$vg=( (array_key_exists("vg", $args) )?($args["vg"]):(false) ) ;
$lvs=( (array_key_exists("lvs", $args) )?(explode(",", $args["lvs"]) ):(false) ) ;
$interval=( (array_key_exists("interval", $args) )?($args["interval"]):(false) ) ;
$max_intervals =( (array_key_exists("max_intervals", $args) )?($args["max_intervals"]):(5) ) ;
$debug=( (array_key_exists("debug", $args) )?($args["debug"]):(false) ) ;
$md5=( (array_key_exists("md5", $args) )?($args["md5"]):(false) ) ;
$date = date("Ymd");
if( ($host===false) || ($vg===false) || ($lvs===false) || ($interval===false) ) {print("ERROR: requires arguments for host, vg, lvs, interval, and max_interval\n"); exit(); }
$valid_intervals = array("daily", "weekly", "monthly", "yearly");
if(!in_array($interval , $valid_intervals ) ) {print("Invalid interval given.\n"); exit(); }

// Make sure that we have a dir for this host.
$lhost_dir = "/data/lv_backups/{$host}";
// Does $lhost_dir exist?  if not, create it.
if(!file_exists($lhost_dir) ) {$cmd = "mkdir -p {$lhost_dir}"; go($cmd, $debug);}


### Get started:
foreach($lvs as $lv) {
	// Create a lock file / verify we're not going to conflict with another backup process.
	$lock = "/tmp/{$host}_{$lv}.lock";
	$f = fopen($lock, 'x');
	if ($f === false) { print("\nCan't acquire lock: {$lock}\n"); continue; }

	$snapshot = "{$hostname}_{$lv}_{$interval}_snapshot_{$date}";
	$ldir = "{$lhost_dir}/{$lv}";
	$lfile = "{$lv}_{$interval}_snapshot";

        #  Start by removing any old backup snapshots that are lingering on the server
        $cmd = "ssh {$host} 'find /dev -name {$hostname}_{$lv}_* -exec lvremove -f {} \\;'"; go($cmd, $debug);

        #  Connect to the remote server an create a snapshot
	$cmd = "ssh {$host} lvcreate -L4G -s -n {$snapshot} /dev/{$vg}/{$lv}"; go($cmd, $debug);
	if($debug){$cmd = "ssh {$host} md5sum /dev/{$vg}/{$snapshot}"; go($cmd, $debug); }

	# Make a copy of the local image file that alreqady exists (BTFFS should make this instantaneous)
	rotate_files($ldir, $lfile, $max_intervals, false);
	if($debug){$cmd = "md5sum {$ldir}/{$lfile}"; go($cmd, $debug);}

	# Use the helper bash/perl script to update the local file based on the remote file
	$cmd = "./sync_remote_lv_to_local_file.sh {$host} /dev/{$vg}/{$snapshot} {$ldir}/{$lfile}"; go($cmd, $debug);
	if($debug){$cmd = "md5sum {$ldir}/{$lfile}"; go($cmd, $debug);}

	# Clean-up on the remote server: Delete the LVM snapshot
	$cmd = "ssh {$host} lvremove -f /dev/{$vg}/{$snapshot}"; go($cmd, $debug);

	// Cleanup the lock
	fclose($f);
	unlink($lock);
}

# Ok, I think we're done here...

/* Script to get user and group ids from the file server we're backing up (From Mike Teehan)
// FIXME: Do something with this.
Users:
find /mnt/data/shares -type d -exec ls -lq --color=no "{}" \; | tr -s ' ' | cut -d ' ' -f 3 | sort | uniq | xargs getent passwd

Groups:
find /mnt/data/shares -type d -exec ls -lq --color=no "{}" \; | tr -s ' ' | cut -d ' ' -f 4 | sort | uniq | xargs getent group
*/

// Helper Function
function go($cmd, $debug) {
	if($debug) {print($cmd . "\n"); }
	$ret = shell_exec ($cmd);
	if($debug) {print ($ret . "\n");}
}

function rotate_files($ldir, $snapshot, $max_intervals, $debug=false) {
	if($debug) {print("Rotating files for: {$ldir}/{$snapshot}\n"); }

	# Does $ldir exist?  if not, create it.
	if(!file_exists($ldir) ) {$cmd = "mkdir -p {$ldir}"; go($cmd, $debug);}

	// Handle the nth case specially:
	if( file_exists("{$ldir}/{$snapshot}.{$max_intervals}") ) { $cmd = "rm -f {$ldir}/{$snapshot}.{$max_intervals}";  go($cmd, $debug); }

	// Loop through the files and copy
	for($i=$max_intervals-1; $i>0; $i--) {
		// Does $snapshot.$i exist?  If so, copy it to $snapshot.$i+1
		// print("Looking for {$ldir}/{$snapshot}.{$i}\n");
		if( file_exists("{$ldir}/{$snapshot}.{$i}") ) {
			// print("Found {$snapshot}.{$i}\n");
			$cmd = "mv -f {$ldir}/{$snapshot}.{$i} {$ldir}/{$snapshot}." . ($i+1);  go($cmd, $debug);
		}
	}

	// Handle the 0th case specially:
	if( file_exists("{$ldir}/{$snapshot}") ) { $cmd = "cp --reflink=always -f {$ldir}/{$snapshot} {$ldir}/{$snapshot}.1";  go($cmd, $debug); }
	else {
		if($debug) {print("{$snapshot} not found to copy\n");}

		// See if there are any other images of this LV lying around, if so use it instead of the blank "touched" file
		$dir = scandir ($ldir);
		foreach($dir as $file) {
			if(strstr($file , "_snapshot" ) !== false ) {
				$cmd = "cp --reflink=always -f {$ldir}/{$file} {$ldir}/{$snapshot}";  go($cmd, $debug);
				return;
			}
		}
		// If we've gotten here, then we need to create a blank / empty file.
		$cmd = "touch {$ldir}/{$snapshot} {$ldir}/{$snapshot}";  go($cmd, $debug);
	}

}

?>
