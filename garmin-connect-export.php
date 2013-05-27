#!/usr/bin/php
<?php
/*
This script will backup your personal Garmin Connect data.
Activity records and details will go in a CSV file called     <-- TODO
'YYYY-MM-DD_garmin_connect_backup.csv' saved to the current
working directory.  GPX files containing track data,
activity title, and activity descriptions, will be saved in a
folder within the current working directory, called
'YYYY-MM-DD_garmin_connect_backup_gpx'.

Code is intended to be run from the command line as so:
	php -f backup_garmin_connect.php [how_many]
where [how_many] is how many recent activities to download.
The default is 1 and 'all' will download all activities.      <-- TODO

Code based on Garmin Connect export to Dailymile code
on http://www.ciscomonkey.net/gc-to-dm-export/ by rmullins@ciscomonkey.net
This project would not be possible without his work.

-Kyle Krafka, Oct. 24, 2012
*/

// Set your username and password for Garmin Connect here.
// WARNING: This data will be send in cleartext over HTTP
// so be sure you're on a private connection, and be aware
// that any remote parties storing HTTP requests will have
// your username and password right there.
// It might be best just to temporarily change your password
// at https://my.garmin.com/mygarmin/customers/updateAccountInformation.faces
// to use this script.
$username = 'username';
$password = 'password';

// Set this if you need it on your installation.
date_default_timezone_set('America/New_York');
$current_date = date('Y-m-d'); // TODO is this string format correct!?

// End of user edits.

// URLs for various services
$urlGCLogin    = 'http://connect.garmin.com/signin';
$urlGCSearch   = 'http://connect.garmin.com/proxy/activity-search-service-1.0/json/activities?';
$urlGCActivity = 'http://connect.garmin.com/proxy/activity-service-1.1/gpx/activity/';

// Initially, we need to get a valid session cookie, so we pull the login page.
curl( $urlGCLogin );

// Now we'll actually login
curl( $urlGCLogin . '?login=login&login:signInButton=Sign%20In&javax.faces.ViewState=j_id1&login:loginUsernameField='.$username.'&login:password='.$password.'&login:rememberMe=on');


// Now we search GC for the latest activity.
// We support calling multiples from command line if specified,
// otherwise, only pull the last activity.
if ( ! empty( $argc ) && ( is_numeric( $argv[1] ) ) ) {
	$search_opts = array(
		'start' => 0,
		'limit' => $argv[1] // Maximum of 100... 400 return status if over 100.
		);
} else {
	$search_opts = array(
		'start' => 0,
		'limit' => 1
		);
}

$result = curl( $urlGCSearch . http_build_query( $search_opts ) );
$json = json_decode( $result );

if ( ! $json ) {
	echo "Error: ";	
	switch(json_last_error()) {
		case JSON_ERROR_DEPTH:
			echo ' - Maximum stack depth exceeded';
			break;
		case JSON_ERROR_CTRL_CHAR:
			echo ' - Unexpected control character found';
			break;
		case JSON_ERROR_SYNTAX:
			echo ' - Syntax error, malformed JSON';
			break;
	}
	echo PHP_EOL;
	var_dump( $result );
	die();
}

// Info on the search, for future paging.
// @TODO: Add in support for loading all activities
$search     = $json->{'results'}->{'search'};

// Pull out just the list of activites
$activities = $json->{'results'}->{'activities'};

// Process each activity.
foreach ( $activities as $a ) {
	// Display which entry we're working on.
	print "Garmin Connect activity: [" . $a->{'activity'}->{'activityId'} . "] ";
	print $a->{'activity'}->{'beginTimestamp'}->{'display'}  . ": ";
	print $a->{'activity'}->{'activityName'}->{'value'} . "\n";

	// GC activity URL - to append to DM message
	$activity_gc_url = 'http://connect.garmin.com/activity/' . $a->{'activity'}->{'activityId'};

	/*
	// Change the activityType into something that DM understands
	switch( $a->{'activity'}->{'activityType'}->{'key'} ) {
		case 'running':
		case 'street_running':
		case 'track_running':
		case 'trail_running':
		case 'treadmill_running':
			$activity_type = 'running';
			break;

		case 'cycling':
		case 'cyclocross':
		case 'downhill_biking':
		case 'indoor_cycling':
		case 'mountain_biking':
		case 'recumbent_cycling':
		case 'road_biking':
		case 'track_cycling':
			$activity_type = 'cycling';
			break;

		case 'swimming':
		case 'lap_swimming':
		case 'open_water_swimming':
			$activity_type = 'swimming';
			break;

		case 'walking':
		case 'casual_walking':
		case 'speed_walking':
		case 'snow_shoe':
		case 'hiking':
			$activity_type = 'walking';
			break;

		default:
			$activity_type = 'fitness';
			break;
	}

	// Generate the DM Entry Name
	if ( $a->{'activity'}->{'activityName'}->{'value'} && $a->{'activity'}->{'activityName'}->{'value'} != 'Untitled' ) {
		$activity_name = $a->{'activity'}->{'activityName'}->{'value'} . ' (' . $a->{'activity'}->{'activityId'} . ')';
	} else {
		$activity_name = $a->{'activity'}->{'activityId'};
	}

	// Start building our DM entry array.
	$dm_entry = array();
	// Add in our Auth Token as it needs to be part of the post fields.
	$dm_entry{'oauth_token'} = $oauth;

	// Add message
	if ( $a->{'activity'}->{'activityDescription'}->{'value'} ) {
		$dm_entry{'message'} = $a->{'activity'}->{'activityDescription'}->{'value'};
		$dm_entry{'message'} .= "\nOriginal activity at: " . $activity_gc_url;
	} else {
		$dm_entry{'message'} = "\nOriginal activity at: " . $activity_gc_url;
	}

	// add geolocation:
	if ( $a->{'activity'}->{'beginLatitude'}->{'value'} && $a->{'activity'}->{'beginLongitude'}->{'value'} ) {
		$dm_entry{'lat'} = $a->{'activity'}->{'beginLatitude'}->{'value'};
		$dm_entry{'lon'} = $a->{'activity'}->{'beginLongitude'}->{'value'};
	}

	$dm_entry{'workout[activity_type]'} = $activity_type;
	$dm_entry{'workout[completed_at]'} = date( "c", strtotime( $a->{'activity'}->{'endTimestamp'}->{'display'} ) );
	$dm_entry{'workout[distance][value]'} = $a->{'activity'}->{'sumDistance'}->{'display'};
	$dm_entry{'workout[distance][units]'} = $a->{'activity'}->{'sumDistance'}->{'uom'};
	$dm_entry{'workout[duration]'} = $a->{'activity'}->{'sumElapsedDuration'}->{'value'};
	//$dm_entry{'workout[felt]'} = '';
	$dm_entry{'workout[calories]'} = $a->{'activity'}->{'sumEnergy'}->{'display'};
	$dm_entry{'workout[title]'} = $activity_name;
	*/

	// Download the GPX file from GC.
	print "\tDownloading .GPX ... ";

	/* TEMPORARY ONLY DOWNLOAD CYCLING
	switch( $a->{'activity'}->{'activityType'}->{'key'} ) {
		case 'cycling':
		case 'cyclocross':
		case 'downhill_biking':
		case 'indoor_cycling':
		case 'mountain_biking':
		case 'recumbent_cycling':
		case 'road_biking':
		case 'track_cycling':
			$activity_type = 'cycling';
			break;
		default:
			$activity_type = 'fitness';
			break;
	}

	if(strcmp($activity_type, 'fitness')==0) {
		print "\tUh oh!  It's not a cycling track.  I'm going to skip it.\n";
		continue;
	}
	// END TEMPORARY */

	$gpx_filename = './activities/activity_' . $a->{'activity'}->{'activityId'} . '.gpx';
	$save_file = fopen( $gpx_filename, 'w+' );
	$curl_opts = array(
		CURLOPT_FILE => $save_file
		);
	curl( $urlGCActivity . $a->{'activity'}->{'activityId'} . '?full=true', array(), array(), $curl_opts );
	fclose( $save_file );

	// Now we need to validate the .GPX.  If we have an activity without GPS data, GC still kicks out a .GPX file for it.
	// As I ride a trainer in the bad months, this is a common occurance for me, as I would imagine it would be for anyone
	// using a treadmill as well.
	$gpx = simplexml_load_file( $gpx_filename, 'SimpleXMLElement', LIBXML_NOCDATA );
	$gpxdataexists = ( count( $gpx->trk->trkseg->trkpt ) > 0);

	if ( $gpxdataexists ) {
		print "Done. GPX data saved.\n";
	} else {
		// We don't need to create a track, as we have no GPS track data. :(
		print "Done. No track points found.\n";
	}
}

print "\n\n";
// End

function curl( $url, $post = array(), $head = array(), $opts = array() )
{
	$cookie_file = '/tmp/cookies.txt';
	$ch = curl_init();

	//curl_setopt( $ch, CURLOPT_VERBOSE, 1 );
	curl_setopt( $ch, CURLOPT_URL, $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );	
	curl_setopt( $ch, CURLOPT_ENCODING, "gzip" );
	curl_setopt( $ch, CURLOPT_COOKIEFILE, $cookie_file );
	curl_setopt( $ch, CURLOPT_COOKIEJAR, $cookie_file );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1 );

	foreach ( $opts as $k => $v ) {
		curl_setopt( $ch, $k, $v );
	}

	if ( count( $post ) > 0 ) {
		// POST mode
		curl_setopt( $ch, CURLOPT_POST, 1 );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $post );
	}
	else {
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $head );
		curl_setopt( $ch, CURLOPT_CRLF, 1 );
	}

	$success = curl_exec( $ch );

	if ( curl_errno( $ch ) !== 0 ) {
		throw new Exception( sprintf( '%s: CURL Error %d: %s', __CLASS__, curl_errno( $ch ), curl_error( $ch ) ) );
	}

	if ( curl_getinfo( $ch, CURLINFO_HTTP_CODE ) !== 200 ) {
		if ( curl_getinfo( $ch, CURLINFO_HTTP_CODE ) !== 201 ) {
			throw new Exception( sprintf( 'Bad return code(%1$d) for: %2$s', curl_getinfo( $ch, CURLINFO_HTTP_CODE ), $url ) );
		}
	}

	curl_close( $ch );
	return $success;
}

?>