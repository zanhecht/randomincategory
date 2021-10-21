<?php

// Random In Category Tool
// -----------------------
//
// Replacement for 'Special:RandomInCategory' that actually chooses a page randomly. Includes options for filtering results by namespace and type.
// e.g.: https://RandomInCategory.toolforge.org/?site=en.wikipedia.org&category=AfC_pending_submissions_by_age/0_days_ago&cmnamespace=2|118&cmtype=page&debug=true
//
// Set the following rewrite rules to allow accessing via https://tools.wmflabs.org/RandomInCategory/AfC_pending_submissions_by_age/0_days_ago?site=en.wikipedia.org&cmnamespace=2|118&cmtype=page&debug=true
// or https://RandomInCategory.toolforge.org/AfC_pending_submissions_by_age/0_days_ago?site=en.wikipedia.org&cmnamespace=2|118&cmtype=page&debug=true:
//url.rewrite-if-not-file += ( "^/[Rr]andom[Ii]n[Cc]ategory/([^\?]+)\?(.*)$" => "/randomincategory/index.php?category=$1&$2" )
//url.rewrite-if-not-file += ( "^/[Rr]andom[Ii]n[Cc]ategory/([^\?]*)$" => "/randomincategory/index.php?category=$1" )
//url.rewrite-if-not-file += ( "^/([^\?]+)\?(.*)$" => "/index.php?category=$1&$2" )
//url.rewrite-if-not-file += ( "^/([^\?]*)$" => "/index.php?category=$1" )

// Set defaults
$redisKey = @file_get_contents('../redis.key') ?: 'G6YfmVEhxQdrFLEBFZEXxAppN0jyoYoC';

$params = array(
	'query' => array(
		'format' => 'json',
		'action' => 'query',
		'list' => 'categorymembers',
		'cmprop' => 'title',
		'cmlimit' => 'max'
	)
);

// Gather parameters from URL
if ( !empty($_GET['category']) ) {
	$params['cmtitle'] = $_GET['category'];
} else if ( !empty($_GET['cmcategory']) ) {
	$params['cmtitle'] = $_GET['cmcategory'];
}

if ( !empty($_GET['site']) ) {
	$params['baseURL'] = $_GET['site']; 
} else if ( !empty($_GET['server']) ) {
	$params['baseURL'] = $_GET['server']; 
}

if ( !empty($_GET['namespace']) ) {
	$params['query']['cmnamespace'] = $_GET['namespace'];
} else if ( !empty($_GET['cmnamespace']) ) {
	$params['query']['cmnamespace'] = $_GET['cmnamespace'];
}

if ( !empty($_GET['type']) ) {
	$params['query']['cmtype'] = $_GET['type'];
} else if ( !empty($_GET['cmtype']) ) {
	$params['query']['cmtype'] = $_GET['cmtype'];
}

//Do some data validation
if ( isset($params['cmtitle']) ) {
	$params['category'] = rawurlencode(preg_replace('/(\s|%20)/', '_', $params['cmtitle']));
}

if (!isset($params['baseURL']) or !preg_match("/^[a-z\-]*\.?(mediawiki|toolforge|wik(i(books|data|[mp]edia|news|quote|source|versity|voyage)|tionary)).org/i", $params['baseURL']) ) {
	if (isset($params['baseURL'])) {error_log("Invalid URL: {$params['baseURL']}");}
	$params['baseURL'] = 'en.wikipedia.org';
}

if (isset($params['query']['cmnamespace']) and !preg_match("/^[\d\|\!:]*$/", urldecode($params['query']['cmnamespace'])) ) {
	error_log("Invalid namespace: {$params['query']['cmnamespace']}");
	unset($params['query']['cmnamespace']);
}

if (isset($params['query']['cmtype']) and !preg_match("/^(page|subcat|file)[\d\|\!:]?(page|subcat|file)?[\d\|\!:]?(page|subcat|file)?$/", urldecode($params['query']['cmtype'])) ) {
	error_log("Invalid type: {$params['query']['cmtype']}");
	unset($params['query']['cmtype']);
}

	
// Update API query
if ( empty($params['category']) ) { // No category specified
	buildPage($params);
} else { // Category was specified
	// Normalize parameters
	$params['category'] = preg_replace('/^Category:/i','',$params['category']);
	$params['query']['cmtitle'] = "Category:{$params['cmtitle']}";

	if ( !empty($params['query']['cmnamespace']) ) {
		$params['query']['cmnamespace'] = str_replace(
			array(',', ';', '!', '/'),
			'|',
			$params['query']['cmnamespace']
		);
	}

	// Check for cached list
	$redisKey = implode(
		array(
			$redisKey,
			"https://{$params['baseURL']}/wiki/Category:{$params['cmtitle']}",
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
	if ( !empty($memberList) ) { //List of pages found
		// Get URL parameters to pass on
		$urlVars = array();
		foreach($_GET as $getKey => $getValue) {
			if (!in_array($getKey, array( 'site', 'server', 'category', 'cmcategory', 'namespace', 'cmnamespace', 'type', 'cmtype', 'purge', 'debug' ))) {
				$urlVars[$getKey] = urlencode($getValue);
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
			echo("Location: $targetURL<br>");
		} else {
			header("Location: $targetURL");
		}
	} else { // No page to redirect to
		if ( $memberList !== FALSE ) { // server is valid
			// Check if category exists
			$query = array(
				'format' => 'json',
				'action' => 'query',
				'prop' => '',
				'titles' => "Category:{$params['cmtitle']}"
			);
			$queryURL = 'https://' . $params['baseURL'] . '/w/api.php?' . http_build_query($query);
			$jsonFile = @file_get_contents( $queryURL );
			$data = json_decode($jsonFile, TRUE);
		} else { // server isn't valid
			$queryURL = '';
			$jsonFile = FALSE;
		}
		
		if ( !empty($jsonFile) and isset($data) and isset($data['query']) and isset($data['query']['pages']) and !isset($data['query']['pages']['-1']) ) {
			// category and site exist
			$params['categoryName'] = str_replace('Category:', '', reset($data['query']['pages'])['title']);
			$params['categoryColor'] = '#0645ad';
			$params['siteColor'] = '#0645ad';
		} else { // Invalid API response or category name not found
			$params['categoryName'] = str_replace('_',' ',$params['category']);
			$params['categoryColor'] = '#ba0000';
			if ( !empty($jsonFile) ) { // API returned a valid response, site exists
				$params['siteColor'] = '#0645ad';
			} else { // API returned a valid response, assuming site doesn't exist
				$params['siteColor'] = '#ba0000';
			}
		}
		
		$params['categoryName'] = htmlspecialchars(urldecode($params['categoryName']));
		
		if (isset($_GET['debug'])) {
			echo("Query URL: $queryURL<br>");
			echo("JSON file: $jsonFile<br>");
			echo('<a href="README.html">View documentation</a>.<br>');
		}
		
		buildPage($params);
	}
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
		if ( !empty($_GET['category']) ) {
			$params['category'] = rawurlencode(preg_replace('/(\s|%20)/', '_', $_GET['category']));
		} else if ( !empty($_GET['cmcategory']) ) {
			$params['category'] = rawurlencode(preg_replace('/(\s|%20)/', '_', $_GET['cmcategory']));
		} 
		$targetURL = "https://{$params['baseURL']}/wiki/Special:RandomInCategory/$category";
		if ( !empty($_GET['debug']) ) {
			echo("Error fetching $queryURL. <a href=\"README.html\">View documentation</a>.<br>");
			echo("Location: $targetURL<br>");
		} else {
			header("Location: $targetURL");
		}
		return FALSE;
	}
}

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

function buildPage($params) {
	echo(
'<html>
	<head>
		<link rel="shortcut icon" type="image/x-icon" href="favicon.ico" />
		<script src="https://tools-static.wmflabs.org/cdnjs/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
	</head>
	<body style="font: 14px sans-serif;color: #202122;">
		<h1 style="font: 2em \'Linux Libertine\',\'Georgia\',\'Times\',serif;color: #000;line-height: 1.3;margin-bottom:7.2px;border-bottom: 1px solid #a2a9b1;display: block;">Random page in category</h1>
		<div style="padding: 4px 0px;">'
	);
	$catSuggest = 'placeholder="Category name"';
	if ( !empty($params['category']) ) {
		
		$catSuggest = "value={$params['category']}";
		
		$ct = isset($params['query']['cmtype']) ? $params['query']['cmtype'] : 'item';
		$ns = isset($params['query']['cmnamespace']) ? "in namespace {$params['query']['cmnamespace']} " : '';
		
		echo(
"			<svg style=\"width: 20px; height: 20px;vertical-align: middle;\">
				<g fill=\"#d33\">
					<path d=\"M13.728 1H6.272L1 6.272v7.456L6.272 19h7.456L19 13.728V6.272zM11 15H9v-2h2zm0-4H9V5h2z\"/>
				</g>
			</svg>
			<span style=\"color: #d23;font-weight: 700;line-height: 20px;vertical-align: middle;\">
	There are no {$ct}s {$ns}in the <a style=\"color: {$params['categoryColor']};text-decoration: none;\" href=\"https://{$params['baseURL']}/wiki/Category:{$params['category']}\">{$params['categoryName']}</a> category on <a style=\"color: {$params['siteColor']};text-decoration: none;\" href=\"https://{$params['baseURL']}/\">{$params['baseURL']}</a>.
			</span>"
		);
	}
	echo(
"		</div>
		<form oninput='document.getElementById(\"outputURL\").href = document.getElementById(\"outputURL\").innerHTML = window.location.href.split(/[?#]/)[0] + \"?\" + $(this).serialize();'>
			<div style='margin-top: 12px;'>
				<span style='display: block;padding-bottom: 4px;'>
					<label for='category-input'>
						Category:
					</label>
				</span>
				<input type='text' name='category' id='category-input' {$catSuggest} style='padding: 6px 8px;border: 1px solid #a2a9b1;border-radius: 2px;width: 640px;'>
			</div>"
	);
	foreach($_GET as $getKey => $getValue) {
		if ( !in_array($getKey, ["category", "site", "server", "namespace", "cmnamespace", "type", "cmtype"]) ) {
			echo(
'			<input type="hidden" name="'.urlencode($getKey).'" value="'.urlencode($getValue).'">'
			);
		}
	}
	$cmnamespace = isset($params['query']['cmnamespace']) ? $params['query']['cmnamespace'] : '';
	$cmtype= isset($params['query']['cmtype']) ? $params['query']['cmtype'] : '';
	echo(
'			<div style="margin-top: 12px;display: none;" id="expandedOptions">
				<div style="float: left; padding-right: 8px;">
					<span style="display: block;padding-bottom: 4px;">
						<label for="server-input">
							Server:
						</label>
					</span>
					<input type="text" name="server" id="server-input" value="'.$params['baseURL'].'" style="padding: 6px 8px;border: 1px solid #a2a9b1;border-radius: 2px;width: 320px;">
				</div>
				<div style="float: left;padding-right: 8px;padding-left: 8px;">
					<span style="display: block;padding-bottom: 4px;">
						<label for="cmnamespace-input">
							Namespace number(s):
						</label>
					</span>
					<input type="text" name="cmnamespace" id="cmnamespace-input" value="'.$cmnamespace.'" style="padding: 6px 8px;border: 1px solid #a2a9b1;border-radius: 2px;width: 144px;">
				</div>
				<div style="float: left;padding-left: 8px;">
					<span style="display: block;padding-bottom: 4px;">
						<label for="cmtype-input">
							Type:
						</label>
					</span>
					<select name="cmtype" id="cmtype-input" style="padding: 5px 8px;border: 1px solid #a2a9b1;border-radius: 2px;width: 144px;">
						<option value="'.$cmtype.'" selected>'.$cmtype.'</option>
						<option value="file">File</option>
						<option value="page">Page</option>
						<option value="subcat">Category</option>
						<option value="file|page">File or page</option>
						<option value="file|subcat">File or Category</option>
						<option value="page|subcat">Page or Category</option>
						<option value="file|page|subcat">File, page, or category</option>
					</select>
				</div>
				<div style="clear: both;">
					<div style="padding-top: 12px;padding-bottom: 4px;display: block;">URL:</div>
					<div style="display: block;padding: 5px 8px;border: 1px solid #a2a9b1;border-radius: 2px;width: 626px;overflow-wrap: break-word;"><a id="outputURL"></a></div>
				</div>
			</div>
			<input type="submit" value="Go" style="margin-top: 12px;background-color: #36c;border: 1px solid #36c;border-radius: 2px;padding: 6px 12px;color: #fff;font-weight: 700;">
		</form>
		<div style="font-size: smaller;margin-top: 12px;display: block;"><a href="#" onclick=\'var x = document.getElementById("expandedOptions");if (x.style.display === "none") {x.style.display = "block";this.innerHTML="Hide additional options";} else {x.style.display = "none";this.innerHTML="Show additional options";}\'>Show additional options</a>&nbsp;&middot;&nbsp;<a href="/README.html">View documentation</a></div>
		<script type="text/javascript">document.getElementById("outputURL").href = document.getElementById("outputURL").innerHTML = window.location.href.split(/[#]/)[0];</script>
	</body>
</html>'
	);
}

exit();

/*
		The MIT License (MIT)

		Copyright (c) 2021 Ahecht (https://en.wikipedia.org/wiki/User:Ahecht)

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
