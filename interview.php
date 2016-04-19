<?php
$table = "";

if( isset( $_GET['category'] ) && isset( $_GET['limit'] ) ) {
	$data = scoreArticles( $_GET['category'], $_GET['limit'] );
	if( $data !== false ) $table = loadTableHTML( $data );
}

loadHTML( $table );

//Scores a list of aritcles of size $limit and returns a sorted array of articles with scores, most readable first.
function scoreArticles( $category, $limit ) {
	$articleResume = false;
	$extractResume = false;
	$tarticles = array();
	$paragraphs = array();		//List of paragraphs
	$articles = array();		//List of articles
	$scores = array();          //List of scores.  Index should match to $articles.  This makes sorting easier.
	$returnArray = array();		//This is the array being returned
	if( ($limit != "max" && (int) $limit < 1) ) return false;
	do {						//Loop until we have all the articles processed.  	
		$extracts = array();	//Batch of extracts
		$tarticles = getArticlesFromCat( $category, $limit, $articleResume );
		if( $limit != "max" ) $limit -= count( $tarticles );
		$tarticles = array_chunk( $tarticles, 50, true );	//The text extractor only handles 50 titles at most.			
		foreach( $tarticles as $chunk ) { 					//Handle each chunk
			do { 					                        //Since the limit is 20, keep running until the batch of 50 is complete.
				$extracts = getStartingParagraph( $chunk, $extractResume );
				foreach( $extracts as $extract ) {
					$paragraph = $extract['paragraph'];
					$paralength = strlen( $paragraph );
					$words = explode( " ", $paragraph );
					$sentences = explode( ". ", $paragraph );
					$largestSent = max(array_map('strlen', $sentences));
					$smallestSent = min(array_map('strlen', $sentences));
					$averageSent = (array_sum(array_map('strlen', $sentences)))/count( $words );
					$largestWord = max(array_map('strlen', $words));
					$smallestWord = min(array_map('strlen', $words));
					$averageWord = (array_sum(array_map('strlen', $words)))/count( $words );
					$score = 0;								//If an article gets this score, it's too readable. :p
					//Score the article.  The lower the score the better.
					//A long first paragraph is usually a sign that it is either not concise, or is giving too much information.
					//The longer the paragraph the less readable the article likely is.
					$score += .25*$paralength;
					//More words, especially smaller, indicate an issue with conciseness
					$score += .5*count($words);
					//A large sentence means there is too much effort going on in an explanation.
					//Longer sentences are harder to follow, unlike shorter sentences.
					$score += $largestSent/$paralength*100;
					//Shorter sentences are likely more concise.
					$score -= ($largestSent-$smallestSent)/$paralength*100;
					//If the average sentence length is, the topic must be more complex than usual.
					$score += $averageSent*.5;
					//Large words are likely very hard to understand at first.
					$score += $largestWord*10;
					//Smaller words not including words of 3 letters or less, are much easier to read
					$score -= ($largestWord-max( array( $smallestWord, 4 ) ))*10;
					//The average word length is 5.5 letters.
					$score += ($averageWord - 5.5)*50;
					//Word count to sentence count shouldn't be ridiculous either.  Very easy sentences have 8 words on average
					$score += ((count( $words )/count( $sentences )) - 8)*15;
					$paragraphs[] = $paragraph;
					$articles[] = $extract['title'];
					$scores[] = round( $score, 2 );;
				}
			} while( $extractResume !== false);
		} 
	} while( $articleResume !== false && ( (int) $limit > 0 || $limit == "max" ) );
	arsort( $scores ); 					//Sort the scores in ascending order
	foreach( $scores as $id=>$score ) {
		$returnArray[] = array( 'title'=>$articles[$id], 'paragraph'=>$paragraphs[$id], 'score'=>$score ); 	//Put them together
	}
	return $returnArray;
}

//Load articles contained in a given category
function getArticlesFromCat( $category, $limit, &$resume ) {
	$queryArray = array();
	$returnArray = array();
	$queryArray['format'] = "php";
	$queryArray['rawcontinue'] = 1;		//I use the old method as I more familiar with it.  Call me old.:p
	$queryArray['action'] = "query";
	$queryArray['list'] = "categorymembers";
	if( strpos( $category, "Category:" ) === false ) $queryArray['cmtitle'] = "Category:$category";
	else $queryArray['cmtitle'] = $category;
	$queryArray['cmprop'] = "ids|title";
	$queryArray['cmdir'] = "asc";
	$queryArray['cmnamespace'] = 0;
	$queryArray['cmlimit'] = $limit;
	if( $resume !== false ) $queryArray['cmcontinue'] = $resume;
	$res = query( $queryArray );
	if( !isset( $res['query-continue']['categorymembers']['cmcontinue'] ) ) $resume = false;
	else $resume = $res['query-continue']['categorymembers']['cmcontinue'];
	foreach( $res['query']['categorymembers'] as $page ) {
		$returnArray[] = $page['title'];
	}
	return $returnArray;
}

//Run an API query with the given parameters
function query( $params ) {
	$ch = initCurl();				//Why open and close here? Well there's hardly any load when opening and closing
									//handles and this provides cleaner and more maintainable code
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $params );
	$res = curl_exec( $ch );
	curl_close( $ch );
	if( !$res ) return false;
	$res = unserialize( $res );		//I use unserialize because I'm used to doing it.  I can easily use JSON if you wanted me to.
	return $res;
}

//Initialize the curl handle
function initCurl() {
	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_MAXCONNECTS, 100 );
	curl_setopt( $ch, CURLOPT_MAXREDIRS, 10 );
	curl_setopt( $ch, CURLOPT_ENCODING, 'gzip' );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 100 );
	curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 0 );
	curl_setopt( $ch, CURLOPT_URL, "https://en.wikipedia.org/w/api.php" );
	curl_setopt( $ch, CURLOPT_POST, 1 );
	curl_setopt( $ch, CURLOPT_HTTPGET, 0 );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );	//For reasons that escape me, CURL fails without this.
	return $ch;
}

//Extract 1st paragraph from the given batch of articles
function getStartingParagraph( $articles, &$resume ) {
	$queryArray = array();
	$returnArray = array();
	$offset = 0;
	if( $resume !== false ) $offset = $resume;
	$queryArray['format'] = "php";
	$queryArray['rawcontinue'] = 1;		//I use the old method as I more familiar with it.  Call me old.:p
	$queryArray['action'] = "query";
	$queryArray['prop'] = "extracts";
	$queryArray['exintro'] = 1;
	$queryArray['exlimit'] = "max";
	$queryArray['explaintext'] = 1;
	$queryArray['exsectionformat'] = "plain";
	if( $resume !== false ) $queryArray['excontinue'] = $resume;
	$queryArray['titles'] = implode( "|", $articles );
	$res = query( $queryArray );
	if( !isset( $res['query-continue']['extracts']['excontinue'] ) ) $resume = false;
	else $resume = $res['query-continue']['extracts']['excontinue'];
	$i = 0;
	$res['query']['pages'] = array_slice($res['query']['pages'], $offset );		//The API is very bad at not listing results already returned
	foreach( $res['query']['pages'] as $id=>$page ) {
		$i++;
		$extract = "";
		$extract = $page['extract'];
		if( isset( $page['extract'] ) ) $extract = $page['extract'];
		$paragraphs = explode( "\n", $extract );		//Each paragraph is sepereated by a newline.
		$returnArray[] = array( 'pageid'=>$page['pageid'], 'title'=>$page['title'], 'paragraph'=>$paragraphs[0] );
		if( $i == 20 ) break;    	//The API is very bad at not listing results it didn't process yet.
	}
	return $returnArray;
}

function loadHTML( $table = "" ) {
	global $_GET;
	$category = "";
	$limit = "";                                                   
	if( isset( $_GET['category'] ) ) $category = $_GET['category'];
	if( isset( $_GET['limit'] ) ) $limit = $_GET['limit'];
	$HTML = str_replace( "{{{table}}}", $table, file_get_contents( 'interview.html' ) );
	$HTML = str_replace( "{{{category}}}", $category, $HTML );
	$HTML = str_replace( "{{{limit}}}", $limit, $HTML );
	echo $HTML;
}

function loadTableHTML( $data ) {
	$tableString = "";
	foreach( $data as $article ) {
		$tableString .= "<tr class=\"trow \"><td>";
		$tableString .= $article['title'];
		$tableString .= "</td><td>".$article['score'];
		$tableString .= "</td><td>".$article['paragraph'];
		$tableString .= "</td></tr>";
	}
	return $tableString;
}