<?php
use \Eventviva\ImageResize;

date_default_timezone_set('UTC');
require_once('vendor/autoload.php');
require_once('lib/Regex.php');
require_once('lib/php_calendar.php');
require_once('lib/format.php');
require_once('lib/config.php');

ORM::configure('mysql:host='.Config::$dbhost.';dbname='.Config::$dbname.';charset=utf8');
ORM::configure('driver_options', array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'));
ORM::configure('username', Config::$dbuser);
ORM::configure('password', Config::$dbpass);

function db_row_to_new_log($row) {
  $date = DateTime::createFromFormat('U.u', floor($row->timestamp/1000).'.'.sprintf('%06d',1000*($row->timestamp%1000)));

  return json_decode(json_encode([
    'type' => $row->type == 64 ? 'join' : 'message',
    'timestamp' => $date->format('U.u'),
    'network' => 'irc',
    'server' => 'freenode',
    'channel' => [
      'id' => $row->channel,
      'name' => $row->channel,
    ],
    'author' => [
      'uid' => $row->nick,
      'nickname' => $row->nick,
      'name' => $row->nick,
      'username' => null
    ],
    'content' => $row->line,
  ]));
}

class ImageProxy {

  public static function url($url) {
    $signature = hash_hmac('sha256', $url, Config::$secret);
    if(preg_match('/^http/', $url))
      $path = '?url=' . urlencode($url) . '&';
    else
      $path = '/' . $url . '?';
    return Config::$base.'img.php'.$path.'sig=' . $signature;
  }

  public static function image($url, $sig) {
    $expectedSignature = hash_hmac('sha256', $url, Config::$secret);
    if($sig == $expectedSignature) {
      $filename = './public/avatars/'.$sig.'.jpg';

      if(preg_match('/^https?:\/\//', $url)) {

        if(file_exists($filename)) {
          header('Content-type: image/jpeg');
          readfile($filename);
          return;
        }

        $client = new GuzzleHttp\Client();

        try {
          $img = $client->request('GET', $url);

          // Resize to 64px
          $image = ImageResize::createFromString($img->getBody());
          $image->resizeToBestFit(64, 64);

          // Save to disk
          $image->save($filename, IMAGETYPE_JPEG, 80);
          $image->output(IMAGETYPE_JPEG, 80);

          return;
        } catch(GuzzleHttp\Exception\ClientException $e) {
        }
      }
    }
    header('Content-type: image/svg+xml');
    readfile('./public/assets/user.svg');
  }

}


function filterText($text) {
	/*
	for($i=0; $i<strlen($text); $i++) {
		if(ord($text[$i]) < 32)
			$text[$i] = '';
	}
	*/

	$text = htmlspecialchars($text, ENT_SUBSTITUTE, 'UTF-8');
  #$text = mb_encode_numericentity($text);
	$text = preg_replace(Regex_URL::$expression, Regex_URL::$replacement, $text);
	$text = preg_replace(Regex_Twitter::$expression, Regex_Twitter::$replacement, $text);
	$text = preg_replace(Regex_WikiPage::$expression, Regex_WikiPage::$replacement, $text);
	return $text;
}

function xmlEscapeText($text, $autolink=TRUE) {
	# escape the source line of text
	$text = str_replace(array('&','<','>','"'), array('&amp;','&lt;','&gt;','&quot;'), $text);
	
	if($autolink) {
		# add links for URLs and twitter names
		$text = preg_replace(Regex_URL::$expression, Regex_URL::$replacement, $text);
		$text = preg_replace(Regex_Twitter::$expression, Regex_Twitter::$replacement, $text);
	}
	
	return $text;
}

function stripIRCControlChars($text) {
	$text = preg_replace('/\x03\d{1,2}/', '', $text);
	$text = preg_replace('/\x03/', '', $text);
	return $text;
}

function trimString($str, $length, $allow_word_break=false) {
// trims $str to $length characters
// if $str is too long, it puts … on the end
// if $allow_word_break is true, doesn't split a word in the middle

	if( strlen($str) <= $length ) {
		return $str;
	} else {
		if( $allow_word_break ) {
			return trim(substr($str,0,$length-3))."...";
		} else {
			$newstr = substr($str,0,$length-3);
			return substr($newstr, 0, strrpos($newstr, " "))."...";
		}
	}
}


function refreshUsers() {
	//if(filemtime('users.json') < time() - 300) {
		$users = file_get_contents('http://pin13.net/mf2/?url=https%3A%2F%2Findiewebcamp.com%2Firc-people');
		if(trim($users)) {
		  $data = json_decode($users);
		  if($data && property_exists($data, 'items') && count($data->items) && count($data->items[0])) {
		    $er = fopen('php://stderr', 'w');
		    fputs($er, 'found ' . count($data->items[0]->children) . ' items'."\n");
		    fclose($er);
  			file_put_contents(dirname(__FILE__).'/data/users.json', $users);
			}
		}
	//}
}

$users = array();

function loadUsers() {
	global $users;
  $filename = dirname(__FILE__).'/data/users.json';
  if(file_exists($filename)){
  	$data = json_decode(file_get_contents($filename));
  	if(property_exists($data, 'items') && property_exists($data->items[0], 'children')) {
    	foreach($data->items[0]->children as $item) {
    		if(in_array('h-card', $item->type)) {
    			$users[] = $item;
    		}
    	}
  	}
  }
}

function userForNick($nick) {
	global $users;

  $nick = strtolower(trim($nick,'_[]'));

	foreach($users as $u) {
		if(@strtolower($u->properties->nickname[0]) == $nick) {
			return $u;
		}
	}
	return null;
}

function userForHost($host) {
  global $users;
  
	foreach($users as $u) {
	  $userHost = property_exists($u->properties, 'url') ? preg_replace('/https?:\/\//','', strtolower($u->properties->url[0])) : false;
		if($userHost && $userHost == strtolower($host)) {
			return $u;
		}
	}
	return null;
}

function debug($thing) {
  if($_SERVER['REMOTE_ADDR'] == '24.21.213.88') {
    var_dump($thing);
  }
}