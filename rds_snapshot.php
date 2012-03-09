<?php

// 2012 Kevin O'Connor <kevino@arc90.com> @gooeyblob

require_once('aws-sdk-for-php/sdk.class.php');
date_default_timezone_set('UTC');

// An IAM Key/Secret that has access to take and view RDS snapshots and instances
$key = '';
$secret = '';


// Instances to back up
// array(REGION => array('instance1', 'instance2'))
// Example:
// $targets = array(AmazonRDS::REGION_APAC_SE1 => array('my-database-1', 'my-database-2'))
$targets = array();


// How long to keep backups for (in seconds)
// Default is 90 days, with a 60 second buffer in case this script gets run at the exact same
// time every time you run it.  There's probably a better way to do this, but this works
$retention_time = (60 * 60 * 24 * 90) - 60;

// How often a new backup should be taken in seconds
// If you run the script every minute it won't take a backup every time unless the snapshot
// creation time + $schedule_time has been reached.  With a buffer of 60 seconds, again probably a
// better way to take care of that but this works
$schedule_time = (60 * 60 * 24 * 7) - 60;


/** End config **/

$rds = new AmazonRDS(array('key' => $key,
							'secret' => $secret));

// Go through each region listed in $targets
foreach($targets as $region => $instances) {
	// Actually set the region with AWS
	print date("[m-d-Y H:i:s] ") . "Setting region to " . $region . "\n";
	$rds->set_region($region);

	// For each target instance, do some stuff
	foreach($instances as $instance) {
		// Grab list of manual snapshots for this identifier (i.e., kindling-production-db)
		print date("[m-d-Y H:i:s] ") . "Describing snapshots for " . $instance . "\n";
		$snapshots = $rds->describe_db_snapshots(array('DBInstanceIdentifier' => $instance));

		// This shouldn't happen
		if(!$snapshots->isOK()) {
			print date("[m-d-Y H:i:s] ") . "Invalid response from Amazon, exiting.\n";
			exit(1);
		}

		// If this key isn't set there's no snapshots to speak of
		if(!isset($snapshots->body->DescribeDBSnapshotsResult->DBSnapshots->DBSnapshot)) {
			print date("[m-d-Y H:i:s] ") . "No snapshots for " . $instance . ", continuing.\n";
		} else {
			// Keep track of the latest snapshot creation time
			$latest_time = 0;
			$create_time = 0;

			// Flag to not take snapshot because one is already being taken
			$creating = 0;

			// Test to see if this snapshot's creation time is older than our retention
			// This isn't when the snapshot was completed, only requested, but it's close enough
			foreach($snapshots->body->DescribeDBSnapshotsResult->DBSnapshots->DBSnapshot as $snapshot_result) {

				if(!preg_match('/rds-backup-[0-9]+/', $snapshot_result->DBSnapshotIdentifier)) {
					print date("[m-d-Y H:i:s] ") . "Skipping " . $snapshot_result->DBSnapshotIdentifier . " as it's not an auto backup.\n";
					continue;
				}

				if($snapshot_result->Status == 'creating') {
					print date("[m-d-Y H:i:s] ") . "Snapshot " . $snapshot_result->DBSnapshotIdentifier . " is creating, skipping.\n";
					$creating = 1;
				} else {
					// Put creation time into something we can work with
					$create_time = strtotime($snapshot_result->SnapshotCreateTime);
					if(($create_time + $retention_time) < time()) {
						// This ish is old and busted, get rid of it
						print date("[m-d-Y H:i:s] ") . "Removing snapshot " . $snapshot_result->DBSnapshotIdentifier . ", " . date("m-d-Y H:i:s", $create_time) . " is older than retention time of " . $retention_time . " seconds.\n";
						$response = $rds->delete_db_snapshot($snapshot_result->DBSnapshotIdentifier);
						if(!$response->isOK()) {
							print date("[m-d-Y H:i:s] ") . "Got an error when attempting to delete snapshot for " . $instance . ", exiting.\n";
							exit(1);
						}
						$create_time = 0;
					} else {
						// It's cool this guy can stay
						print date("[m-d-Y H:i:s] ") . "Snapshot " . $snapshot_result->DBSnapshotIdentifier . " is within retention time, keeping.\n";
					}
					if($create_time > $latest_time)
						$latest_time = $create_time;
					unset($create_time);
				}
			}
		}

		// Done deleting, let's make some new snapshots you guys
		if(($latest_time + $schedule_time) > time()) {
			print date("[m-d-Y H:i:s] ") . "A snapshot has been taken already in the last scheduled window for " . $instance . ", no need to take one now.\n";
		} else if($creating) {
			print date("[m-d-Y H:i:s] ") . "A snapshot is already creating for " . $instance . ", skipping.\n";
		} else {
			print date("[m-d-Y H:i:s] ") . "Taking snapshot for " . $instance . "\n";
			$response = $rds->create_db_snapshot('rds-backup-' . date("mdYHis") . '-' . $instance, $instance);
			if(!$response->isOK()) {
				print date("[m-d-Y H:i:s] ") . "Got an error when attempting to create snapshot for " . $instance . ", exiting.\n";
				exit(1);
			}
		}

		// Clear the latest time
		$latest_time = 0;

		// Clear creating flag
		$creating = 0;
	}
}

print date("[m-d-Y H:i:s] ") . "Completed!\n";
exit(0);

?>
