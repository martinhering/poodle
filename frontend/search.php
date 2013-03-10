<?php

include_once('config.php');

function __autoload_elastica ($class) {
	$path = str_replace('_', '/', $class);

	if (file_exists($INSTALLTION_PATH . $path . '.php')) {
	require_once($INSTALLTION_PATH . $path . '.php');
	}
}
spl_autoload_register('__autoload_elastica');


function truncate_string($string, $max_length)
{
    if (mb_strlen($string, 'UTF-8') > $max_length){
        $string = mb_substr($string, 0, $max_length, 'UTF-8');
        $pos = mb_strrpos($string, ' ', false, 'UTF-8');
        if($pos === false) {
            return mb_substr($string, 0, $max_length, 'UTF-8').' …';
        }
        return mb_substr($string, 0, $pos, 'UTF-8').' …';
    }else{
        return $string;
    }
}

function emphasize_snippets ($_source, $snippets, &$changed)
{
	$source = $_source;
	$banned_words = array("podcast","title", "video", "pubDate");
	
	foreach($snippets as $snippet) {
		if (in_array($snippet, $banned_words)) {
			continue;
		}
		$source = preg_replace("/($snippet)/i", "<b>$1</b>", $source);
	}
	
	$changed = false;
	if (strcmp($_source, $source) != 0) {
		$changed = true;
	}
	
	return $source;
}

function find_sliding_emphasize_window($string, $snippets, $window_size) {
	
	$max_length = strlen($string);
	$max_offset = $max_length-$window_size;
	
	$max_count = 0;
	$best_offset = 0;
	
	for($i=0; $i<$max_offset; )
	{
		$substr = strtoupper(substr($string, $i, $window_size));
		$count = 0;
		foreach($snippets as $snippet) {
			$count += substr_count($substr, strtoupper($snippet));
		}
		
		if ($count > $max_count) {
			$max_count = $count;
			$best_offset = $i;
		}
		
		$pos = mb_strpos($substr, " ");
		
		//echo "$i $best_offset $pos ".substr($substr, 0, $pos).".\n";
		
		$i+=$pos+1;
		
		//echo "$i $count $best_offset\n";
		
	}
	return $best_offset;
}


function logSearch($query, $lang)
{
	$day = date("Y-m-d");
	$ip = $_SERVER['REMOTE_ADDR'];
	$now = date("Y-m-d H:i:s O");

	$fd = fopen("$LOG_DIR_PATH/search-$day.log", "a");
	fwrite($fd, "$now|$ip|$query|$lang\n");
	fclose($fd);
}

function getLangCode($code = "en")
{
	$LANGUAGE = $code;
	if (isset($_SERVER["HTTP_ACCEPT_LANGUAGE"]))
	{
		$lang_code = strtolower(substr($_SERVER["HTTP_ACCEPT_LANGUAGE"],0,2));
		$languages = array("en" => "en", "de" => "de");
		if (array_key_exists($lang_code, $languages)) {
			$LANGUAGE = $languages[$lang_code];
		}
	}
	return $LANGUAGE;
}


$q = stripslashes($_GET["q"]);
$lang = stripslashes($_GET["lang"]);

if (strlen($q) > 0) {
	logSearch($q, $lang);
}



$searchTerm = (strlen($q) > 0) ? $q : 'instacast';
$langTerm = (strlen($lang) > 0) ? $lang : getLangCode();

$elasticaClient = new Elastica_Client($SEARCH_CLUSTER);

$elasticaIndex = $elasticaClient->getIndex($langTerm);
$elasticaQuery 		= new Elastica_Query();

$query = array('query' => array(
	'query_string' => array("query" => $searchTerm,
							"default_field" => "_all",
							"default_operator" => "and",
							"fuzzy_prefix_length" => 1)
	)
);
//

$elasticaQuery->setRawQuery($query);
$elasticaQuery->setFrom(0);
$elasticaQuery->setLimit(25);

//Search on the index.
$elasticaResultSet 	= $elasticaIndex->search($elasticaQuery);


$elasticaResults 	= $elasticaResultSet->getResults();
$totalResults 		= $elasticaResultSet->getTotalHits();

$search_terms = explode(" ",$searchTerm);

preg_match_all('/[\p{L}\d]+/u', $searchTerm, $search_terms_matches);
$search_terms = $search_terms_matches[0];


?>

<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
	<title>Poodle: <?=$totalResults?> total results for query &quot;<?=$searchTerm?>&quot;</title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<link rel="stylesheet" href="results.css" type="text/css" media="screen"/>
	<script src='http://b.instaca.st/instacast-button.js' async></script>
</head>
<body>

<div id="search">
	<div class="content">
	<div id="title"><b>Poodle</b> is an experimental Search Engine for Podcasts.</div>

	<form action="search.php" method="get">
		<input type="text" name="q" value="<?=htmlspecialchars($searchTerm)?>" size="60"><input type="submit" value="Poodle" class="submit">
		<div id="language-select">
			<div class="language"><input type="radio" name="lang" value="en" class="radio" <?=($langTerm=="en")?"checked":""?>>English</div>
			<div class="language"><input type="radio" name="lang" value="de" class="radio" <?=($langTerm=="de")?"checked":""?>>Deutsch</div>
		</div>
	</form>
	</div>
</div>

<div id="resultbox">

<div id="total" class="content">
	<div class="term"><?=$totalResults?></div> total results for query <div class="term"><?=$searchTerm?></div>
</div>

<div id="results" class="content">

<?php
foreach ($elasticaResults as $elasticaResult) :

	//print_r($elasticaResult);
	$my_search_terms = $search_terms;

	$title = emphasize_snippets(htmlspecialchars($elasticaResult->title), $my_search_terms, $changed_title);
	$podcast_title = emphasize_snippets(htmlspecialchars($elasticaResult->podcast_title), $my_search_terms, $changed_podcast_title);
	
	//echo $elasticaResult->description."\n";
	$elastic_description = $elasticaResult->description;
	if (strlen($elastic_description) == 0) {
		$elastic_description = $elasticaResult->summary;
	}
	
	$window_offset = find_sliding_emphasize_window($elastic_description,$my_search_terms, 250);
	
	$short_description = mb_substr($elastic_description, $window_offset, 250);
	$short_description = htmlspecialchars($short_description);
	$short_description = emphasize_snippets($short_description, $my_search_terms, $changed_description);
?>

<div class="result">
	<div class="title"><a href="<?=$elasticaResult->linkURL?>"><?=$title?></a> 
		<div class="podcast_title">(<?=$podcast_title?>)</div>
	</div>
	<div class="link"><?=$elasticaResult->linkURL?></div>
	<div class="description"><?=$short_description?></div>
	
	<?php if (count($elasticaResult->chapters) > 0) : ?><div class="chapters">
	<?php foreach($elasticaResult->chapters as $chapter) :
		$time = (int)($chapter["time"]);
		$time_str = sprintf("%02d:%02d:%02d", ($time/3600), (($time/60)%60), ($time%60));
		$link = $chapter["link"];
		$title = emphasize_snippets($chapter["title"], $my_search_terms, $changed_chapter_title);
		

			if (strlen($link) > 0) {
				?><div class="chapter"><a href="<?=$elasticaResult->linkURL."#t=$time_str"?>"><?=$time_str?></a> <a href="<?=$link?>"><?=$title?></a></div><?php
			}
			else {
				?><div class="chapter"><a href="<?=$elasticaResult->linkURL."#t=$time_str"?>"><?=$time_str?></a> <?=$title?></div><?php
			}

	?>
		
	<?php endforeach; ?>
	<?php endif; ?>
	<?php if (count($elasticaResult->chapters) > 0) : ?></div><?php endif; ?>
	
	<div class="button"><a href="<?=$elasticaResult->podcast_sourceURL?>" class='instacast-button'></a></div>
</div>

<?php
endforeach;
?>
</div>

<div id="boxes">
	<div class="box">
		<h6>Example Queries:</h6>
		<p>In what episodes of "Amplified" is Jim talking about Heineken?<br>
		<b>podcast_title:amplified heineken</b></p>
		
		<p>When was Dan Benjamin talking about buddhism (bad spelling)?<br>
		<b>"Dan Benjamin" buddism~</b></p>
		
		<p>Let me see a video of the iPhone 5!<br>
		<b>"iphone 5" podcast_video:true</b></p>
		
		<p>Give my all episodes of Hypercrital of Dec 2012!<br>
		<b>podcast_title:"hypercritical" pubDate:[2012-12-01 TO 2012-12-31]</b></p>
	</div>
		
</div>
</div>

</body>

</html>