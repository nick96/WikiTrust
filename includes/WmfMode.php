<?php

class WikiTrust extends WikiTrustBase {
  /**
   Does a POST HTTP request
  */
  static function file_post_contents($url,$headers=false) {
    $url = parse_url($url);

    if (!isset($url['port'])) {
      if ($url['scheme'] == 'http') { $url['port']=80; }
      elseif ($url['scheme'] == 'https') { $url['port']=443; }
    }
    $url['query']=isset($url['query'])?$url['query']:'';
		
    $url['protocol']=$url['scheme'].'://';
    $eol="\r\n";
		
    $headers =  "POST ".
		$url['protocol'].$url['host'].$url['path']." HTTP/1.0".$eol.
		"Host: ".$url['host'].$eol.
		"Referer: ".$url['protocol'].$url['host'].$url['path'].$eol.
		"Content-Type: application/x-www-form-urlencoded".$eol.
		"Content-Length: ".strlen($url['query']).$eol.
		$eol.
		$url['query'];
    $fp = fsockopen($url['host'], $url['port'], $errno, $errstr, 30);
    if($fp) {
      fputs($fp, $headers);
      $result = '';
      while(!feof($fp)) { $result .= fgets($fp, 128); }
      fclose($fp);
      if (!$headers) {
        //removes headers
	// NOTE: these backslashes work, b/c they are actual chars!
        $pattern="/^.*\r\n\r\n/s";
        $result=preg_replace($pattern,'',$result);
      }
      return $result;
    }
  }
  
  static function vote_recordVote(&$response, $user_id, $page_id, $rev_id)
  {
    $ctx = stream_context_create(
	      array('http' => array('timeout' => self::TRUST_TIMEOUT))
	    );

    // TODO: we need a shared key!
    $colored_text = file_get_contents($wgWikiTrustContentServerURL 
		. "vote=1&rev=".urlencode($rev_id)
		."&page=".urlencode($page_id)
		."&user=".urlencode($user_id)
		."&page_title=".urlencode($page_title)
		."&time=".urlencode(wfTimestampNow()), 0
	    , $ctx);
    $response = new AjaxResponse($vote_str);	   
    return $response;
  }
  

  static function color_getColorData($rev_id)
  {
    $ctx = stream_context_create(
		 array('http' => array('timeout' => self::TRUST_TIMEOUT))
	 );

    global $wgTitle, $wgUser;
    $page_id = $wgTitle->getArticleID();
    $page_title = $wgTitle->getDBkey();
    $user_id = $wgUser->getID();
    
    $colored_raw = (file_get_contents($wgWikiTrustContentServerURL .
                        "rev=" .  urlencode($rev_id) . 
			"&page=".urlencode($page_id) .
			"&page_title=" . urlencode($page_title) .
                        "&time=" . urlencode(wfTimestampNow()) .
			"&user=" . urlencode($user_id),
                0, $ctx));


    if (!$colored_raw
	|| $colored_raw == self::NOT_FOUND_TEXT_TOKEN
	|| $colored_raw == "bad")
    {
      return '';
    }

    // Inflate. Pick off the first 10 bytes for python-php conversion.
    $colored_raw = gzinflate(substr($colored_raw, 10));
      
    // Pick off the median value first.
    $colored_data = explode(",", $colored_raw, 2);
    $colored_text = $colored_data[1];
    if (preg_match("/^[+-]?(([0-9]+)|([0-9]*\.[0-9]+|[0-9]+\.[0-9]*)|
			  (([0-9]+|([0-9]*\.[0-9]+|[0-9]+\.[0-9]*))[eE][+-]?[0-9]+))$/", $colored_data[0]))
    {
      self::$median = $colored_data[0];
      if ($colored_data[0] == 0)
	self::$median = self::TRUST_DEFAULT_MEDIAN;
    }
    return $colored_text;
  }

  public static function ucscArticleSaveComplete(&$article, 
				 &$user, &$text, &$summary,
				 &$minoredit, &$watchthis, 
				 &$sectionanchor, &$flags, 
				 &$revision)
  {
    $userName = $user->getName();
    $page_id = $article->getTitle()->getArticleID();
    $rev_id = $revision->getID();
    $page_title = $article->getTitle()->getDBkey();
    $user_id = $user->getID();
    $parentId = $revision->getParentId();
		
    global $wgWikiTrustContentServerURL;
    $colored_text = self::file_post_contents($wgWikiTrustContentServerURL 
			 ."edit=1&rev=".urlencode($rev_id)
			 ."&page=".urlencode($page_id)
			 ."&user=".urlencode($user_id)
			 ."&parentId".urlencode($parentId)
			 ."&text=".urlencode($text)
			 ."&page_title=".urlencode($page_title)
			 ."&time=".urlencode(wfTimestampNow()));
		
    return true;
  }
}

?>