<?php
ini_set('memory_limit', '256M');
$app_version = 'RandomInCategory/20250218';
ini_set('user_agent', $app_version . ' (https://randomincategory.toolforge.org/; en:User:Ahecht) PHP/' . PHP_VERSION);

// Random In Category Tool
// -----------------------
//
// Replacement for 'Special:RandomInCategory' that actually chooses a page randomly. Includes options for filtering results by namespace and type.
// e.g.: https://RandomInCategory.toolforge.org/?site=en.wikipedia.org&category=AfC_pending_submissions_by_age/0_days_ago&cmnamespace=2|118&cmtype=page&debug=true
//
// Set the following rewrite rules to allow accessing via a URL such as
// https://RandomInCategory.toolforge.org/AfC_pending_submissions_by_age/0_days_ago?site=en.wikipedia.org&cmnamespace=2|118&cmtype=page&debug=true:
//url.rewrite-if-not-file += ( "^/[Rr]andom[Ii]n[Cc]ategory/([^\?]+)\?(.*)$" => "/index.php?category=$1&$2" )
//url.rewrite-if-not-file += ( "^/[Rr]andom[Ii]n[Cc]ategory/([^\?]*)$" => "/index.php?category=$1" )
//url.rewrite-if-not-file += ( "^/([^\?]+)\?(.*)$" => "/index.php?category=$1&$2" )
//url.rewrite-if-not-file += ( "^/([^\?]*)$" => "/index.php?category=$1" )
//
//webservice --backend=kubernetes php7.4 start

// Set defaults
$params = array(
	'query' => array(
		'format' => 'json',
		'action' => 'query',
		'list' => 'categorymembers',
		'cmprop' => 'title',
		'cmlimit' => 'max'
	),
	'opts' => array(
		'http'=> array(
			'method' => "GET",
			'protocol_version' => '1.1',
			'header' => ['Connection: close']
		)
	),
	'category' => '',
	'categories' => [],
	'pageLimit' => 100000,
	'hugeCategory' => FALSE,
	'errorMsg' => '',
	'appVersion' => $app_version
);

// Set up caching
$redisBaseKey = $params['appVersion'];
$context = stream_context_create($params['opts']);
$redisBaseKey .= @file_get_contents('../redis.key', false, $context) ?: 'G6YfmVEhxQdrFLEBFZEXxAppN0jyoYoC';

// Gather parameters from URL
foreach($_GET as $getKey => $getValue) {
	if ( ( strtolower( substr($getKey, 0, 8) ) == "category" ) or ( strtolower( substr($getKey, 0, 10) ) == "cmcategory" ) ) {
		foreach (explode('|', $getValue) as $expValue) {
			if ( $expValue != '' ) {
				$params['categories'][] = "Category:" . preg_replace('/^Category:/i','',$expValue);
			}
		}
	}
}
$params['categories'] = gettype($params['categories']) == "array" ? array_unique($params['categories']) : null;
$params['baseURL'] = !empty($_GET['site']) ? $_GET['site'] : (!empty($_GET['server']) ? $_GET['server'] : null);
$params['query']['cmtype'] = !empty($_GET['type']) ? $_GET['type'] : (!empty($_GET['cmtype']) ? $_GET['cmtype'] : null);
$params['query']['cmnamespace'] = !empty($_GET['namespace']) ? $_GET['namespace'] : (!empty($_GET['cmnamespace']) ? $_GET['cmnamespace'] : null);
if ( isset($_GET['returntype']) ) {
	$params['returntype'] = ($_GET['returntype'] == 'article' or $_GET['returntype'] == 'subject') ? 'subject' : ($_GET['returntype'] == 'talk' ? 'talk' : null);
}

$htmlTop = "<html><head>
	<link rel='shortcut icon' type='image/x-icon' href='favicon.ico' />
	<meta name='application-name' content='{$params['appVersion']}' />
</head><body style='font: 14px sans-serif;color: #202122;'>
";

if ( !empty($_GET['debug']) ) { echo($htmlTop); }

//Check that we're only querying wikimedia wikis
if ( !isset($params['baseURL']) or $params['baseURL'] == '' ) {
	$params['baseURL'] = 'en.wikipedia.org';
} elseif ( !preg_match("/^[a-z\-]*\.?(mediawiki|toolforge|wik(i(books|data|[mp]edia|news|quote|source|versity|voyage)|tionary)).org$/i", $params['baseURL']) ) {
	//if (isset($params['baseURL'])) {error_log("Invalid URL: {$params['baseURL']}. From ".json_encode($_GET));}
	$params['errorMsg'] = "This tool only works on wikis hosted by <a href='https://www.wikimedia.org/'>Wikimedia</a>. <a href='//{$params['baseURL']}'>{$params['baseURL']}</a> is not a valid Wikimedia wiki.";
	buildPage($params);
	exit();
}

if ( isset($params['query']['cmnamespace']) ) {
	$orig = $params['query']['cmnamespace'];
	//decode and normalize separators to |
	$params['query']['cmnamespace'] = str_replace( array(',', ';', ':', '!', '/'), '|', urldecode(urldecode($params['query']['cmnamespace'])) );
	//substitute "article" with "0"
	$params['query']['cmnamespace'] = preg_replace( '/article/i', '0', $params['query']['cmnamespace'] );
	//remove anything that's not a number or separator
	$params['query']['cmnamespace'] = preg_replace('/[^\|\d]/', '', $params['query']['cmnamespace']);
	if ( !preg_match("/^\d+(\|\d+)*$/", $params['query']['cmnamespace']) ) { //invalid namespace format
		$errorLog = "Invalid namespace: {$params['query']['cmnamespace']}  ({$orig}). From ".json_encode($_GET);
		error_log($errorLog);
		if ( !empty($_GET['debug']) ) { echo("<p>{$errorLog}</p>"); }
		unset($params['query']['cmnamespace']);
	}
}

if ( isset($params['query']['cmtype']) ) {
	$orig = $params['query']['cmtype'];
	//decode and normalize separators to |
	$params['query']['cmtype'] = str_replace( array(',', ';', ':', '!', '/'), '|', urldecode(urldecode($params['query']['cmtype'])) );
	//remove anything that's not a letter or separator
	$params['query']['cmtype'] = preg_replace( '/[^a-z\|]/i', '', $params['query']['cmtype'] );
	if ( !preg_match("/^(page|subcat|file)(\|(page|subcat|file))?(\|(page|subcat|file))?$/", $params['query']['cmtype']) ) {
		$errorLog = "Invalid type: {$params['query']['cmtype']} ({$orig}). From ".json_encode($_GET);
		error_log($errorLog);
		if ( !empty($_GET['debug']) ) { echo("<p>{$errorLog}</p>"); }
		unset($params['query']['cmtype']);
	}
}

// Run API queries
$numCats = count($params['categories']);
if ( $numCats == 0 ) { // No categories specified 
	if ( !empty($_GET['debug']) ) { echo("<p>Category list empty.</p>"); }
	buildPage($params);
	exit();
} else { // Category was specified
	$memberList = array();
	
	reset($params['categories']);
	while( key($params['categories']) !== NULL ) {
		list($catKey, $catName) = [key($params['categories']),current($params['categories'])];
		next($params['categories']);
		$params['query']['cmtitle'] = $catName;
		$catName = rawurlencode(preg_replace('/(\s|%20)/', '_', $catName));
		$params['category'] = $params['category'].$catName.'|';
		if ( !empty($_GET['debug']) ) {
			echo("<p>Category $catKey: $catName</p>");
		}
		
		// Check for cached list
		$redisKey = implode(
			array(
				$redisBaseKey,
				"https://{$params['baseURL']}/wiki/{$params['query']['cmtitle']}",
				$params['query']['cmnamespace'] ?? null,
				$params['query']['cmtype'] ?? null
			), ':'
		);

		$cache = getCache($redisKey);

		if ( $cache and $cache['data'] and $cache['timestamp'] and ( (time() - $cache['timestamp']) < 600) and empty($_GET['purge']) ) {
			$jsonCache = json_decode( $cache['data'] );
			if ( gettype($jsonCache) == "array" ) {
				$memberList = array_merge( $memberList, $jsonCache );
			}
		} else {
			// Check if category exists and is small enough
			$query = array(
				'format' => 'json',
				'action' => 'query',
				'prop' => 'categoryinfo',
				'titles' => $params['query']['cmtitle']
			);
			$queryURL = 'https://' . $params['baseURL'] . '/w/api.php?' . http_build_query($query);
			$jsonFile = file_get_contents( $queryURL, false, $context );
			if ( !empty($_GET['debug']) ) {
				echo("<p>Query URL: $queryURL<br>");
				echo("JSON file: $jsonFile</p>");
			}
			$thisMemberList = FALSE;
			if ($jsonFile and !empty($jsonFile)) { // API call executed successfully
				$data = json_decode($jsonFile, TRUE);
				if ( isset($data) and isset($data['query']) and isset($data['query']['pages']) ) {
					$firstPage = reset($data['query']['pages']);
					if ( isset($data['query']['pages']['-1']) or isset($firstPage['missing']) or !isset($firstPage['categoryinfo']) ) {
						//Missing or not a category
						if ( preg_match('/^category:/i', $params['query']['cmtitle']) ) {
							if ( !empty($_GET['debug']) ) { echo("<p>Let's try again without the prefix:</p>"); }
							$params['categories'][$catKey] = preg_replace('/^category:/i','',$params['query']['cmtitle']);
							key($params['categories']) ? prev($params['categories']) : end($params['categories']); //retry
						}
					} elseif ( !isset($firstPage['invalid']) ) {
						// category and site exist
						$params['pageCount'][$catKey] = intval($firstPage['categoryinfo']['pages']);
						if ($params['pageCount'][$catKey] <= $params['pageLimit']) {
							$thisMemberList = getMembers($params);
						} else {
							$params['hugeCategory'] = reset($data['query']['pages'])['title'];
						}
					}
				}
			}
			if ( gettype($thisMemberList) == "array" ) {
				setCache( $redisKey, json_encode($thisMemberList) );
				$memberList = array_merge( $memberList, $thisMemberList );
			}
			$cache['timestamp'] = time();
		}
	}
	// Remove trailing '|' from list of categories
	$params['category'] = substr($params['category'], 0, -1);
	// Remove duplicate page titles
	if ( gettype($memberList) == "array" ) { $memberList = array_unique($memberList); }

	// Output URL or redirect
	if ($params['hugeCategory'] == FALSE) {
		if ( !empty($memberList) ) { //List of pages found and none are huge
			// Get URL parameters to pass on
			$urlVars = array();
			foreach($_GET as $getKey => $getValue) {
				if (!in_array($getKey, array( 'site', 'server', 'namespace', 'cmnamespace', 'type', 'cmtype', 'purge', 'debug', 'returntype' ))) {
					if ( ( strtolower( substr($getKey, 0, 8) ) != "category" ) and ( strtolower( substr($getKey, 0, 10) ) != "cmcategory" ) ) {
						$urlVars[$getKey] = urlencode($getValue);
					}
				}
			}
			
			$targetPage = $memberList[array_rand($memberList)];
			
			if ( isset($params['returntype']) ) {
				$targetPage = getAssociatedPage($params, $targetPage);
			}
			
			$targetPage = wikiencode($targetPage);

			if ( sizeof($urlVars) ) {
				$targetURL = "https://{$params['baseURL']}/w/index.php?title=$targetPage&".http_build_query($urlVars);
			} else {
				$targetURL = "https://{$params['baseURL']}/wiki/$targetPage";
			}
			
			if ( !empty($_GET['debug']) ) {
				echo('<p>Cache age: ' . (time() - $cache['timestamp']) . 's. ');
				echo("Items in categories {$params['category']}: " . sizeof($memberList) . '.<br>');
				echo("Location: $targetURL</p>");
			} else {
				header("Location: $targetURL");
			}
		} else { // No page to redirect to
			foreach($params['categories'] as $catKey => $catName) { //Find information on each category
				if ( $memberList !== FALSE ) { // server is valid
					// Check if category exists
					$query = array(
						'format' => 'json',
						'action' => 'query',
						'prop' => '',
						'titles' => $params['query']['cmtitle']
					);
					$queryURL = 'https://' . $params['baseURL'] . '/w/api.php?' . http_build_query($query);
					$jsonFile = @file_get_contents( $queryURL, false, $context );
					$data = json_decode($jsonFile, TRUE);
				} else { // server isn't valid
					$queryURL = '';
					$jsonFile = FALSE;
				}
				
				if ( !empty($jsonFile) and isset($data) and isset($data['query']) and isset($data['query']['pages']) and !isset($data['query']['pages']['-1']) ) {
					// category and site exist
					$params['categoryName'][$catKey] = str_replace('Category:', '', reset($data['query']['pages'])['title']);
					$params['categoryColor'][$catKey] = '#0645ad';
					$params['siteColor'] = '#0645ad';
				} else { // Invalid API response or category name not found
					$params['categoryName'][$catKey] = str_replace('_',' ',$catName);
					$params['categoryColor'][$catKey] = '#ba0000';
					if ( !empty($jsonFile) ) { // API returned a valid response, site exists
						$params['siteColor'] = '#0645ad';
					} else { // API did not return a valid response, assuming site doesn't exist
						$params['siteColor'] = '#ba0000';
					}
				}
				
				$params['categoryName'][$catKey] = htmlspecialchars(urldecode($params['categoryName'][$catKey]));
				
				if ( !empty($_GET['debug']) ) {
					echo("<p>Query URL: $queryURL<br>");
					echo("JSON file: $jsonFile</p>");
				}
			}
			
			//Categories specified but no pages found. Generate error message.
			$ct = isset($params['query']['cmtype']) ? $params['query']['cmtype'] : 'item';
			$ns = isset($params['query']['cmnamespace']) ? "in namespace {$params['query']['cmnamespace']} " : '';
			
			$params['errorMsg'] = "There are no {$ct}s {$ns}in the ";
			
			foreach($params['categories'] as $catKey => $catName) { //List each category
				$orOrNot = "";
				if ($catKey < $numCats - 1) {
					$orOrNot = "or ";
				}
				$dispName = str_replace('_',' ',$catName);
				$params['errorMsg'] = "{$params['errorMsg']}<a style=\"color: {$params['categoryColor'][$catKey]};text-decoration: none;\" href=\"https://{$params['baseURL']}/wiki/{$catName}\">{$params['categoryName'][$catKey]}</a> {$orOrNot}";
			}
			
			$pluralCats = "y";
			if ($numCats > 1) {
				$pluralCats = "ies";
			}
			
			$params['errorMsg'] = "{$params['errorMsg']}categor{$pluralCats} on <a style=\"color: {$params['siteColor']};text-decoration: none;\" href=\"https://{$params['baseURL']}/\">{$params['baseURL']}</a>.";
			
			buildPage($params);
		}
	} else {
		$startURL = "https://{$params['baseURL']}/wiki/";
		$encodedCat = rawurlencode(preg_replace('/(\s|%20)/', '_', $params['hugeCategory']));
		if ( !empty($_GET['debug']) or ($numCats > 1) ) {
			if ( empty($_GET['debug']) ) { echo($htmlTop); }
			echo("	<p><a href='{$startURL}{$encodedCat}'>{$params['hugeCategory']}</a> is too large for this tool. ");
			echo("	Try using <a href='{$startURL}Special:RandomInCategory/{$encodedCat}'>Special:RandomInCategory/{$params['hugeCategory']}</a> instead.</p>");
			if ( empty($_GET['debug']) ) { echo('</body></html>'); }
		} else {
			header("Location: {$startURL}Special:RandomInCategory/{$encodedCat}");
		}
	}
}
if ( !empty($_GET['debug']) ) { echo('<p><a href="README.html">View documentation</a>.</p></body></html>'); }

function wikiencode($text) {
	$output = "";
	foreach (mb_str_split(str_replace(" ", "_", $text)) as $char) {
		if (preg_match('/[^\p{L}\p{N}!$()*,.\/:;@~_\-]/u',$char)) {
			$char = urlencode($char);
		}
		$output .= $char;
	}
	return $output;
}

function getMembers($params, $cont = false) {
	$memberList = array();
	
	if ( gettype($cont) == "array" ) {
		$params['query'] = array_merge($params['query'], $cont);
	}
	
	$queryURL = 'https://' . $params['baseURL'] . '/w/api.php?' . http_build_query($params['query']);
	$context = stream_context_create($params['opts']);
	$jsonFile = @file_get_contents( $queryURL, false, $context );
	if ( !empty($_GET['debug']) ) {
		echo("<p>Query URL: $queryURL<br>");
		echo("JSON file: $jsonFile</p>");
	}

	if ($jsonFile) { // API call executed successfully
		$data = json_decode($jsonFile, TRUE);
		if ( isset($data) ) {
			if ( isset($data['query']) && isset($data['query']['categorymembers']) ) {
				foreach ($data['query']['categorymembers'] as $item) {
					if ( $item['title'] ) {
						$memberList[] = $item['title'];
					}
				}
			}
			if ( isset($data['continue']) ) {
				$newMembers = getMembers($params, $data['continue']);
				if ( gettype($memberList) == "array" && gettype($newMembers) == "array" ) {
					$memberList = array_merge( $memberList, $newMembers );
				}
			}
		} else {
			error_log("Error parsing response from $queryURL. Response size: " . strlen($jsonFile) . ". Member list length: " . count($memberList));
		}
		return $memberList;
	} else { // API call failed
		error_log( "Error fetching $queryURL. ".json_encode($http_response_header) );
		$params['category'] = null;
		if ( !empty($_GET['category']) or !empty($_GET['cmcategory']) ) {
			$params['category'] = rawurlencode(preg_replace('/(\s|%20)/', '_', (!empty($_GET['category']) ? $_GET['category'] : $_GET['cmcategory']) ));
		} 
		
		$targetURL = "https://{$params['baseURL']}/wiki/Special:RandomInCategory/{$params['category']}";
		if ( !empty($_GET['debug']) ) {
			echo("<p>Error fetching <a href=\"$queryURL\">$queryURL</a>.<br>");
			echo("Location: <a href=\"$targetURL\">$targetURL</a></p>");
		} else if (count($params['categories']) > 1) {
			echo("Error fetching data for {$params['category']}. Try using <a href=\"$targetURL\">$targetURL</a> or <a href=\"README.html\">view documentation</a>.<br>");
		} else {
			header("Location: $targetURL");
		}
		return FALSE;
	}
}

function getAssociatedPage($params, $title) {
	$query = array(
		'action' => 'query',
		'format' => 'json',
		'prop' => 'info',
		'titles' => $title,
		'formatversion' => '2',
		'inprop' => 'subjectid|associatedpage|talkid'
	);
	
	$queryURL = 'https://' . $params['baseURL'] . '/w/api.php?' . http_build_query($query);
	$context = stream_context_create($params['opts']);
	$jsonFile = @file_get_contents( $queryURL, false, $context );
	
	if ($jsonFile) { // API call executed successfully
		$data = json_decode($jsonFile, TRUE);
		if ( isset($data) && isset($data['query']) && isset($data['query']['pages']) && isset($data['query']['pages'][0]) && isset($data['query']['pages'][0]['associatedpage']) ) {
			$item = $data['query']['pages'][0];
			if ( (($params['returntype'] == 'talk') && isset($item['talkid'])) 
				|| (($params['returntype'] == 'subject') && isset($item['subjectid'])) )
			{
				return $item['associatedpage'];
			}
		}
	} else {
		error_log("Error fetching $queryURL.");
		if ( !empty($_GET['debug']) ) {
			echo("<p>Error fetching $queryURL.</p>");
		}
	}
	
	return $title;
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
		<meta name="application-name" content="' . $params['appVersion'] . '" />
		<script src="https://tools-static.wmflabs.org/cdnjs/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
		<script>
			function addCatRow(node, counter) {
				$(node).after(\'<input type="text" name="category\' + (counter + 1) + \'" id="category-input\' + (counter + 1) + \'" placeholder="Category name" style="margin-top: 12px;padding: 6px 8px;border: 1px solid #a2a9b1;border-radius: 2px;width: 640px;"><a href="#" id="category-add-\' + (counter + 1) + \'" onClick="addCatRow(this, \' + (counter + 1) + \')"><svg style=\"width: 20px; height: 20px;vertical-align: middle;\"><title>add</title><path fill=\"#36c\" d=\"M11 9V4H9v5H4v2h5v5h2v-5h5V9z\"/></svg></a>\');
				$("#category-add-" + counter).remove();
			}
		</script>
	</head>
	<body style="font: 14px sans-serif;color: #202122;">
		<h1 style="font: 2em \'Linux Libertine\',\'Georgia\',\'Times\',serif;color: #000;line-height: 1.3;margin-bottom:7.2px;border-bottom: 1px solid #a2a9b1;display: block;">Random page in category</h1>
		<div style="padding: 4px 0px;">'
	);
	
	$catSuggest = 'placeholder="Category name"';
	$numCats = count($params['categories']);
	if ( $numCats == 1 ) {
		if ( isset($params['categoryName']) && isset($params['categoryName'][0]) ) {
			$catSuggest = 'value="' . str_replace( 'Category:', '', $params['categoryName'][0] ) . '"';
		} else {
			$catSuggest = 'value="' . str_replace( 'Category:', '', reset($params['categories']) ) . '"';
		}
	}
	
	if ( $params['errorMsg'] != "" ) {
		echo(
"			<svg style=\"width: 20px; height: 20px;vertical-align: middle;\">
				<g fill=\"#d33\">
					<path d=\"M13.728 1H6.272L1 6.272v7.456L6.272 19h7.456L19 13.728V6.272zM11 15H9v-2h2zm0-4H9V5h2z\"/>
				</g>
			</svg>
			<span style=\"color: #d23;font-weight: 700;line-height: 20px;vertical-align: middle;\">
				{$params['errorMsg']}
			</span>"
		);
	}
	
	echo(
"		</div>
		<form oninput='document.getElementById(\"outputURL\").href = document.getElementById(\"outputURL\").innerHTML = window.location.href.split(/(?<!\/)\/(?!\/)/)[0] + \"/?\" + $(this).serialize().replaceAll(\"%20\",\"_\");'>
			<div style='margin-top: 12px;'>
				<span style='display: block;padding-bottom: 4px;'>
					<label for='category-input'>
						Category:
					</label>
				</span>
				<input type='text' name='category' id='category-input' {$catSuggest} style='padding: 6px 8px;border: 1px solid #a2a9b1;border-radius: 2px;width: 640px;'><a href='#' id='category-add-1' onClick='addCatRow(this, 1)'><svg style=\"width: 20px; height: 20px;vertical-align: middle;\"><title>add</title><path fill=\"#36c\" d=\"M11 9V4H9v5H4v2h5v5h2v-5h5V9z\"/></svg></a>
			</div>"
	);
	foreach($_GET as $getKey => $getValue) {
		if ( !in_array($getKey, ["site", "server", "namespace", "cmnamespace", "type", "cmtype"]) ) {
			if ( ( strtolower( substr($getKey, 0, 8) ) != "category" ) and ( strtolower( substr($getKey, 0, 10) ) != "cmcategory" ) ) {
				echo(
'			<input type="hidden" name="'.urlencode($getKey).'" value="'.urlencode($getValue).'">'
				);
			}
		}
	}
	$cmnamespace = isset($params['query']['cmnamespace']) ? $params['query']['cmnamespace'] : '';
	$cmtype= isset($params['query']['cmtype']) ? $params['query']['cmtype'] : '';
	$returntype= isset($params['query']['returntype']) ? $params['query']['returntype'] : '';
	echo(
'			<div style="margin-top: 12px;display: none;" id="expandedOptions">
				<div style="float: left; padding-right: 8px;">
					<span style="display: block;padding-bottom: 4px;">
						<label for="server-input">
							Server:
						</label>
					</span>
					<input type="text" name="server" id="server-input" value="'.$params['baseURL'].'" style="padding: 6px 8px;border: 1px solid #a2a9b1;border-radius: 2px;width: 155px;">
				</div>
				<div style="float: left;padding-right: 8px;padding-left: 8px;">
					<span style="display: block;padding-bottom: 4px;">
						<label for="cmnamespace-input">
							Namespace number(s):
						</label>
					</span>
					<input type="text" name="cmnamespace" id="cmnamespace-input" value="'.$cmnamespace.'" style="padding: 6px 8px;border: 1px solid #a2a9b1;border-radius: 2px;width: 144px;">
				</div>
				<div style="float: left;padding-right: 8px;padding-left: 8px;">
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
				<div style="float: left;padding-left: 8px;">
					<span style="display: block;padding-bottom: 4px;">
						<label for="cmtype-input">
							Return Type:
						</label>
					</span>
					<select name="returntype" id="cmtype-input" style="padding: 5px 8px;border: 1px solid #a2a9b1;border-radius: 2px;width: 144px;">
						<option value="'.$returntype.'" selected>'.$returntype.'</option>
						<option value="">Any</option>
						<option value="subject">Subject/Article</option>
						<option value="talk">Talk</option>
					</select>
				</div>

				<div style="clear: both;">
					<div style="padding-top: 12px;padding-bottom: 4px;display: block;">URL:</div>
					<div style="display: block;padding: 5px 8px;border: 1px solid #a2a9b1;border-radius: 2px;width: 622px;overflow-wrap: break-word;"><a id="outputURL"></a></div>
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

		Copyright (c) 2020-2024 Ahecht (https://en.wikipedia.org/wiki/User:Ahecht)

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
