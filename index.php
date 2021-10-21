<?php

// Random In Category Tool
// -----------------------
//
// Replacement for 'Special:RandomInCategory' that actually chooses a page randomly. Includes options for filtering results by namespace and type.
// e.g.: https://tools.wmflabs.org/RandomInCategory/?site=en.wikipedia.org&category=AfC_pending_submissions_by_age/0_days_ago&cmnamespace=2|118&cmtype=page&debug=true
//
// Set the following rewrite rules to allow accessing via https://tools.wmflabs.org/RandomInCategory/AfC_pending_submissions_by_age/0_days_ago?site=en.wikipedia.org&cmnamespace=2|118&cmtype=page&debug=true:
//url.rewrite-if-not-file += ( "^/[Rr]andom[Ii]n[Cc]ategory/([^\?]+)\?(.*)$" => "/randomincategory/index.php?category=$1&$2" )
//url.rewrite-if-not-file += ( "^/[Rr]andom[Ii]n[Cc]ategory/([^\?]*)$" => "/randomincategory/index.php?category=$1" )

function getCache($key) {
	$redisClient = new Redis();
	$redisClient -> connect('tools-redis',6379);
	$value = $redisClient -> get( $key );
	$timestamp = $redisClient -> get( "$key:timestamp" );
	$redisClient -> close();
	return array(
		'data' => $value,
		'timestamp' => $timestamp
	);
}

function setCache($key, $value) {
	$redisClient = new Redis();
	$redisClient -> connect('tools-redis',6379);
	$redisClient -> set( $key, $value );
	$redisClient -> set( "$key:timestamp", time() );
	$redisClient -> close();
}

function getMembers($params, $cont = false) {
	$memberList = array();
	
	if ($cont) {
		$params['query'] = array_merge($params['query'], $cont);
	}
	
	$queryURL = 'https://' . $params['baseURL'] . '/w/api.php?' . http_build_query($params['query']);
	$jsonFile = @file_get_contents( $queryURL );
	
	if ($jsonFile) { // API call executed successfully
		$data = json_decode($jsonFile, TRUE);
		if ( isset($data) && isset($data['query']) && isset($data['query']['categorymembers']) ) {
			foreach ($data['query']['categorymembers'] as $item) {
				if ($item['title']) {
					$memberList[] = $item['title'];
				}
			}
		}
		if ( isset($data) && isset($data['continue']) ) {
			$memberList = array_merge( $memberList, getMembers($params, $data['continue']) );
		}
		return $memberList;
	} else { // API call failed
		$category = null;
		if (isset($_GET['category'])) {
			$category = $_GET['category'];
		} else if (isset($_GET['cmcategory'])) {
			$category = $_GET['cmcategory'];
		}
		$targetURL = "https://{$params['baseURL']}/wiki/Special:RandomInCategory/$category";
		if (isset($_GET['debug'])) {
			echo("Error fetching $queryURL. <a href=\"README.html\">View documentation</a>.<br>");
			echo("Location: $targetURL");
		} else {
			header("Location: $targetURL");
		}
		return false;
	}
}

// Set defaults

$redisKey = @file_get_contents('../redis.key') ?: 'G6YfmVEhxQdrFLEBFZEXxAppN0jyoYoC';
	
$params = array(
	'baseURL' => 'en.wikipedia.org',
	'query' => array(
		'format' => 'json',
		'action' => 'query',
		'list' => 'categorymembers',
		'cmprop' => 'title',
		'cmlimit' => 'max'
	)
);

// Gather parameters from URL
if (isset($_GET['site'])) {
	$params['baseURL'] = "{$_GET['site']}"; 
} else if (isset($_GET['server'])) {
	$params['baseURL'] = "{$_GET['server']}"; 
} 

if (isset($_GET['category'])) {
	$params['category'] = $_GET['category'];
} else if (isset($_GET['cmcategory'])) {
	$params['category'] = $_GET['cmcategory'];
} 

// Update API query
$params['category'] = preg_replace('/^Category:/i','',$params['category']);
$params['query']['cmtitle'] = "Category:{$params['category']}";

if (isset($_GET['namespace'])) {
	$params['query']['cmnamespace'] = $_GET['namespace'];
} else if (isset($_GET['cmnamespace'])) {
	$params['query']['cmnamespace'] = $_GET['cmnamespace'];
}

if ( isset($params['query']['cmnamespace']) ) {
	$params['query']['cmnamespace'] = str_replace( array(',', ';', '!', '/'), '|', $params['query']['cmnamespace'] );
}

if (isset($_GET['type'])) {
	$params['query']['cmtype'] = $_GET['type'];
} else if (isset($_GET['cmtype'])) {
	$params['query']['cmtype'] = $_GET['cmtype'];
}

// Check for cached list
$redisKey = implode(
	array(
		$redisKey,
		"https://{$params['baseURL']}/wiki/Category:{$params['category']}",
		$params['query']['cmnamespace'] ?? null,
		$params['query']['cmtype'] ?? null
	), ':'
);

$cache = getCache($redisKey);

if( $cache and $cache['data'] and $cache['timestamp'] and ( (time() - $cache['timestamp']) < 600) and !isset($_GET['purge']) ) {
	$memberList = json_decode( $cache['data'] );
} else {
	$memberList = getMembers($params);
	if ( $memberList !== FALSE ) {
		setCache( $redisKey, json_encode($memberList) );
	}
	$cache['timestamp'] = time();
}

// Output URL or redirect
if ( $memberList !== FALSE and sizeof($memberList) > 0 ) { //List of pages found
	// Get URL parameters to pass on
	$urlVars = array();
	foreach($_GET as $getKey => $getValue) {
		if (!in_array($getKey, array( 'site', 'server', 'category', 'cmcategory', 'namespace', 'cmnamespace', 'type', 'cmtype', 'purge', 'debug' ))) {
			$urlVars[$getKey] = $getValue;
		}
	}
	
	if ( sizeof($urlVars) ) {
		$targetURL = 'https://' . $params['baseURL'] . '/w/index.php?title=' . $memberList[array_rand($memberList)] . '&' . http_build_query($urlVars);
	} else {
		$targetURL = 'https://' . $params['baseURL'] . '/wiki/' . $memberList[array_rand($memberList)];
	}

	if (isset($_GET['debug'])) {
		echo('Cache age: ' . (time() - $cache['timestamp']) . 's. ');
		echo("Items in category {$params['category']}: " . sizeof($memberList) . '. ');
		echo('<a href="README.html">View documentation</a>.<br>');
		echo("Location: $targetURL");
	} else {
		header("Location: $targetURL");
	}
} else { // No page to redirect to
	// Check if category exists
	$query = array(
		'format' => 'json',
		'action' => 'query',
		'prop' => '',
		'titles' => "Category:{$params['category']}"
	);
	$queryURL = 'https://' . $params['baseURL'] . '/w/api.php?' . http_build_query($query);
	$jsonFile = @file_get_contents( $queryURL );
	$data = json_decode($jsonFile, TRUE);
	
	if ( $jsonFile and isset($data) and isset($data['query']) and isset($data['query']['pages']) and !isset($data['query']['pages']['-1']) ) {
		$categoryName = str_replace('Category:', '', reset($data['query']['pages'])['title']);
		$categoryColor = '#0645ad';
		$siteColor = '#0645ad';
	} else { // Invalid API response or category name not found
		$categoryName = str_replace('_',' ',$params['category']);
		$categoryColor = '#ba0000';
		if ($jsonFile) { // API returned a valid response, site exists
			$siteColor = '#0645ad';
		} else { // API returned a valid response, assuming site doesn't exist
			$siteColor = '#ba0000';
		}
	}
	if (isset($_GET['debug'])) {
		echo("Query URL: $queryURL<br>");
		echo("JSON file: $jsonFile<br>");
		echo('<a href="README.html">View documentation</a>.<br>');
	}
	echo(
'<html>
	<head><link rel="shortcut icon" type="image/x-icon" href="favicon.ico" /></head>
	<body style="font: 14px sans-serif;color: #202122;">
		<h1 style="font: 2em \'Linux Libertine\',\'Georgia\',\'Times\',serif;color: #000;line-height: 1.3;margin-bottom:7.2px;border-bottom: 1px solid #a2a9b1;display: block;">Random page in category</h1>
		<div style="padding: 4px 0px;">'
	);
	if ($params['category'] and $params['category'] != '') {
		
		if ( !isset($params['query']['cmtype']) ) { $params['query']['cmtype'] = 'page'; }
		$ns = isset($params['query']['cmnamespace']) ? "in namespace {$params['query']['cmnamespace']} " : '';
		
		echo(
"			<svg style=\"width: 20px; height: 20px;vertical-align: middle;\">
				<g fill=\"#d33\">
					<path d=\"M13.728 1H6.272L1 6.272v7.456L6.272 19h7.456L19 13.728V6.272zM11 15H9v-2h2zm0-4H9V5h2z\"/>
				</g>
			</svg>
			<span style=\"color: #d23;font-weight: 700;line-height: 20px;vertical-align: middle;\">
	There are no {$params['query']['cmtype']}s {$ns}in the <a style=\"color: $categoryColor;text-decoration: none;\" href=\"https://{$params['baseURL']}/wiki/Category:{$params['category']}\">{$categoryName}</a> category on <a style=\"color: $siteColor;text-decoration: none;\" href=\"https://{$params['baseURL']}/\">{$params['baseURL']}</a>.
			</span>"
		);
	}
	echo(
'		</div>
		<form>
			<div style="margin-top: 12px;">
				<span style="display: block;padding-bottom: 4px;">
					<label for="category-input">
						Category:
					</label>
				</span>
				<input type="text" name="category" id="category-input" placeholder="Category name" style="padding: 6px 8px;border: 1px solid #a2a9b1;border-radius: 2px;width: 640px;"><br>
			</div>'
	);
	foreach($_GET as $getKey => $getValue) {
		if ( $getKey != 'category' ) {
			echo(
"			<input type=\"hidden\" name=\"$getKey\" value=\"$getValue\">"
			);
		}
	}
	echo(
'			<div style="margin-top: 12px;">
				<input type="submit" value="Go" style="background-color: #36c;border: 1px solid #36c;border-radius: 2px;padding: 6px 12px;color: #fff;font-weight: 700;">
			</div>
		</form>
		<div style="font-size: smaller;margin-top: 12px;display: block;"><a href="/RandomInCategory/README.html">View documentation</a></div>
	</body>
</html>'
	);
}

exit();

/*
		The MIT License (MIT)

		Copyright (c) 2020 Ahecht (https://en.wikipedia.org/wiki/User:Ahecht)

		Permission is hereby granted, free of charge, to any person
		obtaining a copy of this software and associated documentation
		files (the \'Software\'), to deal in the Software without
		restriction, including without limitation the rights to use,
		copy, modify, merge, publish, distribute, sublicense, and/or sell
		copies of the Software, and to permit persons to whom the
		Software is furnished to do so, subject to the following
		conditions:

		The above copyright notice and this permission notice shall be
		included in all copies or substantial portions of the Software.

		THE SOFTWARE IS PROVIDED \'AS IS\', WITHOUT WARRANTY OF ANY KIND,
		EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
		OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
		NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
		HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
		WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
		FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
		OTHER DEALINGS IN THE SOFTWARE.
*/