<?php

class TextTrustImpl {
  /**
   Records the vote.
   Called via ajax, so this must be static.
  */
  static function handleVote($user_name_raw, $page_id_raw = 0, 
			     $rev_id_raw = 0, $page_title_raw = ""){
    
    global $wgWikiTrustContentServerURL;
    $response = new AjaxResponse("0");
    
    $dbr =& wfGetDB( DB_SLAVE );
    
    $userName = $dbr->strencode($user_name_raw, $dbr);
    $page_id = $dbr->strencode($page_id_raw, $dbr);
    $rev_id = $dbr->strencode($rev_id_raw, $dbr);
    $page_title = $dbr->strencode($page_title_raw, $dbr);
    
    if($page_id){
      // First, look up the id numbers from the page and user strings
      $res = $dbr->select('user', array('user_id'), 
			  array('user_name' => $userName), array());
      if ($res){
	$row = $dbr->fetchRow($res);
	$user_id = $row['user_id'];
	if (!$user_id) {
	  $user_id = 0;
	}
      }
      $dbr->freeResult( $res ); 
      
      $ctx = stream_context_create(
				   array('http' => array(
							 'timeout' => 
							 self::TRUST_TIMEOUT
							 )
					 )
				   );
      
      $vote_str = ("Voting at " . $wgWikiTrustContentServerURL . "vote=1&rev=$rev_id&page=$page_id&user=$user_id&page_title=$page_title&time=" . wfTimestampNow());
      $colored_text = file_get_contents($wgWikiTrustContentServerURL . "vote=1&rev=".urlencode($rev_id)."&page=".urlencode($page_id)."&user=".urlencode($user_id)."&page_title=".urlencode($page_title)."&time=" . urlencode(wfTimestampNow()), 0, $ctx);
      $response = new AjaxResponse($vote_str);	   
    }
    return $response;
  }
  
  /*
   Callback for parser function.
  */
  static function handleParserRe($matches){
    
    $normalized_value = min(self::MAX_TRUST_VALUE, 
			    max(self::MIN_TRUST_VALUE, 
				(($matches[1] + .5) * 
				 self::TRUST_MULTIPLIER) 
				/ self::$median));
    $class = self::$COLORS[$normalized_value];
    $output = self::TRUST_OPEN_TOKEN . "span class=\"$class\"" 
      . " onmouseover=\"Tip('".str_replace("&#39;","\\'",$matches[3])
      ."')\" onmouseout=\"UnTip()\""
      . " onclick=\"showOrigin(" 
      . $matches[2] . ")\"" . self::TRUST_CLOSE_TOKEN;
    if (self::$first_span){
      self::$first_span = false;
    } else {
      $output = self::TRUST_OPEN_TOKEN . "/span" . self::TRUST_CLOSE_TOKEN . $output;
    }
    return $output;
  }

  /*
   Callback for Images.
  */
  static function getImageInfo($matches){
    global $wgWikiApiURL;

    /** Still not working
    $data = array('action'=>'parse',
		  'text'=>"[[File:".$matches[2]."]]",
		  'format' => 'json'
		  );
   
    $image_info_raw = file_get_contents($wgWikiApiURL
					.http_build_query($data));
    $image_json = json_decode($image_info_raw, true);
    $image_text = $image_json["parse"]["text"]["*"];
    $image_texts = explode("<p>", $image_text);
    $image_final = explode("</p>", $image_texts[1]);
    
    return $image_final[0];
    */
    return  '<a href="/wiki/File:'.$matches[2].'" class="image">File:'.$matches[2].'</a>';

  }

	/**
   Returns colored markup.
	 
   @return colored markup.
  */
  static function getColoredText($page_title_raw,
				 $page_id_raw = NULL, 
				 $rev_id_raw = NULL){
    global $wgParser, $wgWikiTrustContentServerURL, $wgWikiApiURL, $wgUser;
    global $wgMemc;

    $response = new AjaxResponse("");
    $request_headers = apache_request_headers();

    // Try to use gzip for the content, if possible.
    // Ian - This isn't working with redherring, for some reason.
    if (strstr($request_headers["Accept-Encoding"], "gzip")){
      //  $response->setContentType("gzip");
    }

    // Can set this to use client side caching, but this can also cause
    // problems.
    // Mark that the content can be cached
    // $response->setCacheDuration(self::TRUST_CACHE_VALID);
    
    if(!$page_id_raw || !$rev_id_raw){
      $data = array('action'=>'query',
		    'prop'=>'revisions',
		    'titles'=>$page_title_raw,
		    'rvlimit'=>'1',
		    'rvprop' => 'ids',
		    'format' => 'json'
		    );
      
      $page_info_raw = file_get_contents($wgWikiApiURL
					 .http_build_query($data));
      $page_json = json_decode($page_info_raw, true);
      $pages_arr = array_keys($page_json["query"]["pages"]);

      // Now, parse out only what we need
      if(!$page_id_raw){
	$page_id_raw = $pages_arr[0];
      }

      if(!$rev_id_raw){
	$rev_id_raw = $page_json["query"]["pages"][$page_id_raw]["revisions"][0]["revid"];
      }
    }

    $dbr =& wfGetDB( DB_SLAVE );
    
    $page_id = $dbr->strencode($page_id_raw, $dbr);
    $rev_id = $dbr->strencode($rev_id_raw, $dbr);
    $page_title = $dbr->strencode($page_title_raw, $dbr);    

    // Check the If-Modified-Since header.
    // If the timestamp of the requested revision is earlier than the IMS 
    // header, return 304 and do nothing further.
    $rev_ts = '19700101000000';
    $res = $dbr->select('wikitrust_colored_markup', 
			array('revision_createdon'), 
			array('revision_id' => $rev_id), array());
    if ($res){
      $row = $dbr->fetchRow($res);
      $rev_ts = $row['revision_createdon'];

      if (!$rev_ts) {
	$rev_ts = '19700101000000';
      }
    }
    $dbr->freeResult($res); 
    if($response->checkLastModified($rev_ts)){
      return $response;
    }

    // See if we have a cached version of the colored text, or if 
    // we need to generate new text.
    $memcKey = wfMemcKey( 'revisiontext', 'revid', $rev_id);
    $cached_text = $wgMemc->get($memcKey);
    if($cached_text){
      $response->addText($cached_text);
      return $response; 
    }

    // Since we are here, we need to get the colored HTML the hard way.
    $ctx = stream_context_create(
				 array('http' => array(
						       'timeout' => 
						       self::TRUST_TIMEOUT
						       )
				       )
				 );
    
    // Should we do doing this via HTTPS?
    $colored_raw = (file_get_contents($wgWikiTrustContentServerURL . "rev=" . 
				      urlencode($rev_id) . 
				      "&page=".urlencode($page_id).
				      "&page_title=".
				      urlencode($page_title)."&time=".
				      urlencode(wfTimestampNow())."&user="
				      .urlencode(0)."", 0, $ctx));
    
    if ($colored_raw && $colored_raw != self::NOT_FOUND_TEXT_TOKEN){
    
      // Inflate. Pick off the first 10 bytes for python-php conversion.
      $colored_raw = gzinflate(substr($colored_raw, 10));
      
      // Pick off the median value first.
      $colored_data = explode(",", $colored_raw, 2);
      $colored_text = $colored_data[1];
      if (preg_match("/^[+-]?(([0-9]+)|([0-9]*\.[0-9]+|[0-9]+\.[0-9]*)|
			    (([0-9]+|([0-9]*\.[0-9]+|[0-9]+\.[0-9]*))[eE][+-]?[0-9]+))$/", $colored_data[0])){
	self::$median = $colored_data[0];
	if ($colored_data[0] == 0){
	  self::$median = self::TRUST_DEFAULT_MEDIAN;
	}
      }

      // First, make sure that there are not any instances of our tokens in the colored_text
      $colored_text = str_replace(self::TRUST_OPEN_TOKEN, "", $colored_text);
      $colored_text = str_replace(self::TRUST_CLOSE_TOKEN, "", 
				  $colored_text);
      
      $colored_text = preg_replace("/&apos;/", "'", $colored_text, -1);      
      $colored_text = preg_replace("/&amp;/", "&", $colored_text, -1);
      
      $colored_text = preg_replace("/&lt;/", self::TRUST_OPEN_TOKEN, 
				   $colored_text, -1);
      $colored_text = preg_replace("/&gt;/", self::TRUST_CLOSE_TOKEN, 
				   $colored_text, -1);

      $title = Title::newFromText($page_title);
      $options = ParserOptions::newFromUser($wgUser);
      $parsed = $wgParser->parse($colored_text, $title, $options);
      $text = $parsed->getText();
      
      $count = 0;
      // Update the trust tags
      $text = preg_replace_callback("/\{\{#t:(\d+),(\d+),(.*?)\}\}/",
				    "TextTrustImpl::handleParserRe",
				    $text,
				    -1,
				    $count
				    );
      
      // Update open, close, images, and links.
      $text = preg_replace('/' . self::TRUST_OPEN_TOKEN . '/', "<"
			   , $text, -1, $count);
      // Regex broken for some pages.
      // Removing for now.
      /**
      $text = preg_replace('/<a href="(.*?)(File):(.*?)" (.*?)>/'
			   , self::TRUST_OPEN_TOKEN, $text, -1, $count);
      $text = preg_replace('/<a href="(.*?)(Image):(.*?)" (.*?)>/'
      , self::TRUST_OPEN_TOKEN, $text, -1, $count); */
      $text = preg_replace('/<a href="(.*?)title=(.*?)&amp;action=edit&amp;redlink=1" class="new" title="(.*?) \(not yet written\)">/'
			   , '<a href="/wiki/$2" title="$3">'
			   , $text, -1, $count);
      /* $text = preg_replace_callback(
				    '/'.self::TRUST_OPEN_TOKEN
				    .'(Image|File):(.*?)<\/a>/'
				    ,"TextTrustImpl::getImageInfo"
				    ,$text, -1, $count);
      */
      $text = preg_replace('/' . self::TRUST_CLOSE_TOKEN .'/', ">", $text
			   , -1, $count);
      $text = preg_replace('/<\/p>/', "</span></p>", $text, -1, $count);
      $text = preg_replace('/<p><\/span>/', "<p>", $text, -1, $count);
      $text = preg_replace('/<li><\/span>/', "<li>", $text, -1, $count);
      
      // Save the finished text in the cache.
      $wgMemc->set($memcKey, $text, self::TRUST_CACHE_VALID);

      // And finally return the colored HTML.
      $response->addText($text);

      // And mark that we have the colored version, for cache control.
      $dbw = wfGetDB( DB_MASTER );
      $dbw->begin();
      $dbw->insert( 'wikitrust_colored_markup',
		    array(
			  'revision_id' => $rev_id,
			  'revision_text' => "memcached",
			  'revision_createdon' => wfTimestampNow()),
		    'Database::insert',
		    array('IGNORE'));
      $dbw->commit();
      
    } else {
      // text not found.
      $response = new AjaxResponse(self::NOT_FOUND_TEXT_TOKEN);
    } 
    
    return $response;
  }
}

?>
