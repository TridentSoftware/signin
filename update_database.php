<?php
// database_update.php
// Purpose - pulls information from NationBuilder api regularly (hourly? daily?)
//		and caches locally in SQLite database

// pullUrl
// $url - string - full url to be fetched
// return: string - content of page
function pullUrl($url) {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	$ret = curl_exec($ch);
	curl_close($ch);
	return $ret;
}

//checking local database to see if it exists
if ($db = new SQLite3('local_db.sql')) {
    $q = @$db->query('CREATE TABLE IF NOT EXISTS users (id INTEGER, first_name TEXT, last_name TEXT, email TEXT, phone TEXT, PRIMARY KEY (id))');
    $q = @$db->query('CREATE TABLE IF NOT EXISTS visits (uid INTEGER, time TEXT, info TEXT)');
    $q = @$db->query('CREATE TABLE IF NOT EXISTS events (eid INTEGER, start_time TEXT, end_time TEXT, name TEXT, description TEXT)');
}

function getPeople() {
	global $db;
	//API Token obtained on NationBuilder
	$token = trim(file_get_contents('config/token.txt'));

	//fetch initial listing, primarly to find the page count (defined as total_pages)
	$mainPage = pullUrl("https://techspring.nationbuilder.com/api/v1/people?access_token=" . $token);
	$mainObj = json_decode($mainPage);

	$pages_total = $mainObj->total_pages;

	// FOR TESTING ONLY - only pulling one page to save on compute/testing time
	//$pages_total = 4;
	//fetch each page of results (limit of 10 people per page)
	for($i = 1; $i <= $pages_total; $i++) {
		echo substr(($i/$pages_total)*100, 0, 5) . "%\r";
		$page = pullUrl("https://techspring.nationbuilder.com/api/v1/people?page=" . $i . "&access_token=" . $token);
		$pageObj = json_decode($page);
		/*if($_GET['debug']) {
			echo json_encode($pageObj, JSON_PRETTY_PRINT);
		}*/
		//results found on each page
		$results = $pageObj->results;
		foreach($results as $re) {
			$id = $re->id;
			$first_name = $db->escapeString($re->first_name);
			$last_name = $db->escapeString($re->last_name);
			$email = $db->escapeString($re->email);

			//some users have their numbers listed as "mobile", others have "phone"
			if($re->mobile !== null) {
				$phone = $re->mobile;
			} else if($re->phone !== null) {
				$phone = $re->phone;
			} else {
				$phone = "";
			}
			//echo $re->id . " - " . $re->first_name . " " . $re->last_name .  "<br />";
			$q = @$db->query('SELECT * FROM `users` WHERE `id`=' . $re->id . ';');
			
			if(($arr = $q->fetchArray()) === false) {
				//echo "Welcome, <b>" . $first_name . "</b> " . $last_name . "!<br/>";
				$q = @$db->query("INSERT INTO `users` (id, first_name, last_name, email, phone) VALUES ('" . $id . "','" . $first_name . "','" . $last_name . "','" . $email. "','" . $phone . "');");
			} else {
				//check to see if any information has changed in the database
				if($first_name != $arr['first_name'] ||
						$last_name != $arr['last_name'] ||
						$email != $arr['email'] ||
						$phone != $arr['phone']) {

					$q = @$db->query("UPDATE `users` SET `first_name`='" . $first_name . "', `last_name`='" . $last_name . "', `email`='" . $email . "', `phone`='" . $phone . "' WHERE `id`='" . $id . "';");
				}
			}
		}
	}
	echo "\nDone\n";
}

function getEvents() {
	global $db;

	$token = trim(file_get_contents('config/token.txt'));
	
	$mainPage = pullUrl("https://techspring.nationbuilder.com/api/v1/sites/v2/pages/events?starting=" . date("Y-m-d") . "&access_token=" . $token . "");
	$obj = json_decode($mainPage);
	//echo "<pre>" . json_encode($obj, JSON_PRETTY_PRINT) . "</pre>";

	if ($db = new SQLite3('local_db.sql')) {
		$q = @$db->query('CREATE TABLE IF NOT EXISTS events (eid INTEGER, start_time TEXT, end_time TEXT, name TEXT, description TEXT, PRIMARY KEY(eid))');
		$q = @$db->query('CREATE TABLE IF NOT EXISTS rsvp (eid INTEGER, uid INTEGER)');
	}


	foreach($obj->results as $eventObj) {
		echo "<br/>$eventObj->id - $eventObj->name - $eventObj->start_time - $eventObj->end_time - $eventObj->intro <br />";
		$startTime = strtotime($eventObj->start_time);
		$endTime = strtotime($eventObj->end_time);
		echo "Date: " . date("l, F jS", $startTime);
		echo "<br />Time:" . date("g:i a", $startTime) . " - " . date("g:i a", $endTime) . "<br />";
		@$db->query("INSERT OR IGNORE INTO `events` (eid, start_time, end_time, name, description) VALUES " . 
			"('" . $eventObj->id . "'," .
			"'" . $eventObj->start_time . "'," . 
			"'" . $eventObj->end_time . "'," .
			"'" . $eventObj->name . "'," . 
			"'" . $eventObj->intro . "');");
	}

	$eventRes = @$db->query("SELECT * FROM `events`");

	while($event = $eventRes->fetchArray()) {
		$json = pullUrl("https://techspring.nationbuilder.com/api/v1/sites/v2/pages/events/" . $event['eid'] . "/rsvps?limit=10&__proto__=&access_token=" . $token);
		$rsvpData = json_decode($json);
		foreach($rsvpData->results as $rsvp) {
			@$db->query("INSERT OR IGNORE INTO `rsvp` (eid, uid) VALUES ('" . $event['eid'] . "','" . $rsvp->person_id . "')");
		}
	}
}

getPeople();
getEvents();

$db->close();
?>