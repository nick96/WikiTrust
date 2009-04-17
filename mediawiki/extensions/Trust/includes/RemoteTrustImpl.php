<?php

class TextTrustImpl {

  ## Types of analysis to perform.
  const TRUST_EVAL_VOTE = 0;
  const TRUST_EVAL_EDIT = 10;
  const TRUST_EVAL_MISSING = 15;

  ## the css tag to use
  const TRUST_CSS_TAG = "background-color"; ## color the background
  #$TRUST_CSS_TAG = "color"; ## color just the text
  
  ## ColorText is called multiple times, but we only want to color true text.
  const DIFF_TOKEN_TO_COLOR = "Lin";

  ## Trust normalization values;
  const MAX_TRUST_VALUE = 9;
  const MIN_TRUST_VALUE = 0;
  const TRUST_MULTIPLIER = 10;
  
  ## Token to split trust and origin values on
  const TRUST_SPLIT_TOKEN = ',';

  ## Token to be replaed with <
  const TRUST_OPEN_TOKEN = "QQampo:";
  
  ## Token to be replaed with >
  const TRUST_CLOSE_TOKEN = ":ampc:";

  ## Server forms
  const NOT_FOUND_TEXT_TOKEN = "TEXT_NOT_FOUND";
  const TRUST_COLOR_TOKEN = "<!--trust-->";

  ## Context for communicating with the trust server
  const TRUST_TIMEOUT = 10;

  ## Default median to avoid div by 0 errors
  const TRUST_DEFAULT_MEDIAN = 1;

  ## Median Value of Trust
  static $median = 1.0;

  ## Don't close the first opening span tag
  static $first_span = true;
    
  ## map trust values to html color codes
  static $COLORS = array(
		  "trust0",
		  "trust1",
		  "trust2",
		  "trust3",
		  "trust4",
		  "trust5",
		  "trust6",
		  "trust7",
		  "trust9",
		  "trust10",
		  );

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
		
    $headers =  "POST "
			.$url['protocol'].$url['host'].$url['path']." HTTP/1.0".$eol.
			"Host: ".$url['host'].$eol.
			"Referer: ".$url['protocol'].$url['host'].$url['path'].$eol.
			"Content-Type: application/x-www-form-urlencoded".$eol.
			"Content-Length: ".strlen($url['query']).$eol.
			$eol.$url['query'];
    $fp = fsockopen($url['host'], $url['port'], $errno, $errstr, 30);
    if($fp) {
      fputs($fp, $headers);
      $result = '';
      while(!feof($fp)) { $result .= fgets($fp, 128); }
      fclose($fp);
      if (!$headers) {
        //removes headers
        $pattern="/^.*\r\n\r\n/s";
        $result=preg_replace($pattern,'',$result);
      }
      return $result;
    }
	}
  
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
      
      $vote_str = ("Voting at " 
									 . $wgWikiTrustContentServerURL 
									 . "vote=1&rev=$rev_id&page=$page_id&user=$user_id"
									 . "&page_title=$page_title&time=" . wfTimestampNow());
      $colored_text = file_get_contents($wgWikiTrustContentServerURL 
																				. "vote=1&rev=".urlencode($rev_id)
																				."&page=".urlencode($page_id)
																				."&user=".urlencode($user_id)
																				."&page_title="
																				.urlencode($page_title)
																				."&time=" 
																				. urlencode(wfTimestampNow()), 0
																				, $ctx);
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

	/**
   Returns colored markup.
	 
   @return colored markup.
  */
  static function ucscOutputBeforeHTML(&$out, &$text){
		
    global $wgParser, $wgWikiTrustContentServerURL, $wgUser, $wgTitle
			, $wgScriptPath, $wgWikiTrustShowVoteButton, $wgUseAjax;
		
		// Load the i18n strings
		wfLoadExtensionMessages('RemoteTrust');

		// Add the css and js
		$out->addScript("<script type=\"text/javascript\" src=\""
										.$wgScriptPath
										."/extensions/Trust/js/trust.js\"></script>");
		$out->addScript("<link rel=\"stylesheet\" type=\"text/css\" href=\""
										.$wgScriptPath."/extensions/Trust/css/trust.css\">"); 
	
    $dbr =& wfGetDB( DB_SLAVE );
    
		$rev_id = $out->getRevisionId();
		$page_id = $wgTitle->getArticleID();
		$page_title = $wgTitle->getDBkey();
    $user_id = $wgUser->getID();

    // If there is not a revId, assume it is the most recent one.
    if(!$rev_id){
      $res = $dbr->select('page', array('page_latest'), 
                          array('page_id' => $page_id), array());
      if ($res){
        $row = $dbr->fetchRow($res);
        $rev_id = $row['page_latest'];
      }
      $dbr->freeResult( $res ); 
    }
    
		// Set this up so we can parse things later.
		$options = ParserOptions::newFromUser($wgUser);

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
																			.urlencode($user_id)."", 0, $ctx));

    if ($colored_raw && $colored_raw != self::NOT_FOUND_TEXT_TOKEN
        && $colored_raw != "bad"){

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
			
      $parsed = $wgParser->parse($colored_text, $wgTitle, $options);
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
      $text = preg_replace('/' . self::TRUST_OPEN_TOKEN . '/', 
													 "<", $text, -1, $count);  
      $text = preg_replace('/' . self::TRUST_CLOSE_TOKEN .'/', 
													 ">", $text, -1, $count);
      $text = preg_replace('/<\/p>/', "</span></p>", $text, -1, $count);
      $text = preg_replace('/<p><\/span>/', "<p>", $text, -1, $count);
      $text = preg_replace('/<li><\/span>/', "<li>", $text, -1, $count);
			$text = '<script type="text/javascript" src="'
				.$wgScriptPath
				.'/extensions/Trust/js/wz_tooltip.js"></script>' . $text;

      $msg = $wgParser->parse(wfMsgNoTrans("wgTrustExplanation"), 
															$wgTitle, 
															$options);
			$text = $text . $msg->getText();

			if ($wgWikiTrustShowVoteButton && $wgUseAjax){
				$text = "<div id='vote-button'><input type='button' name='vote' "
					. "value='" 
					. wfMsgNoTrans("wgTrustVote")
					. "' onclick='startVote()' /></div><div id='vote-button-done'>"
					. wfMsgNoTrans("wgTrustVoteDone") 
					. "</div>"
					. $text;
			}

    } else {
      // text not found.
      $msg = $wgParser->parse(wfMsgNoTrans("wgNoTrustExplanation"), 
															$wgTitle, 
															$options);
			$text = $msg->getText() . $text;
    }
    
    return true;
  }

	public static function ucscArticleSaveComplete(&$article, 
																								 &$user, 
																								 &$text, 
																								 &$summary,
																								 &$minoredit, 
																								 &$watchthis, 
																								 &$sectionanchor, 
																								 &$flags, 
																								 &$revision){

		global $wgWikiTrustContentServerURL;
		
    $userName = $user->getName();
    $page_id = $article->getTitle()->getArticleID();
    $rev_id = $revision->getID();
		$page_title = $article->getTitle()->getDBkey();
		$user_id = $user->getID();
		$parentId = $revision->getParentId();
		
		$colored_text = self::file_post_contents($wgWikiTrustContentServerURL 
																						 . "edit=1&rev=".urlencode($rev_id)
																						 ."&page=".urlencode($page_id)
																						 ."&user=".urlencode($user_id)
																						 ."&parentId".urlencode($parentId)
																						 ."&text=".urlencode($text)
																						 ."&page_title="
																						 .urlencode($page_title)
																						 ."&time=" 
																						 . urlencode(wfTimestampNow()));
		
		return true;
	}
}

?>
