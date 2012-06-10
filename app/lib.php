<?php

//force local settings
ini_set( "allow_url_fopen", 1 );
ini_set('default_charset', 'utf-8');
date_default_timezone_set('America/Los_Angeles');
error_reporting(E_ALL);


/**
 * MySQL database info
 *
 */
$db = array
(
	'host' => 'localhost',
	'name' => 'seeds_aggregator',
	'user' => 'root',
	'pass' => 'root'
);

/**
 * Direct URL to RSS/Atom (xml) feeds of Farmers
 *
 */
$farmers = array
(
	'http://interferencearchive.org/rss',
	'http://chrisstain.com/rss',
	'http://nicolaslampert.wordpress.com/feed/',
	'http://dignidadrebelde.com/blog/rss/user/2',
	'http://favianna.typepad.com/faviannacom_art_activism/rss.xml',
	'http://ideasinpictures.org/feed/',
	'http://mulchthief.blogspot.com/feeds/posts/default?alt=rss',
	'http://slifer-freeman.tumblr.com/rss',
	'http://kevincaplicki.tumblr.com/rss'
);


/**
 * Seeds class.
 *
 *
 */
class Seeds {

	/**
	 * get function.
	 *
	 * @access public
	 * @static
	 * @param int $json (default: 0)
	 * @param int $offset (default: 0)
	 * @param int $num (default: 12)
	 * @return void
	 */

	static public function get($json = 0, $offset = 0, $num = 12)
	{

		$sql = sprintf("SELECT farmer, link, title, description, image, posted FROM seeds ORDER BY posted DESC LIMIT %d, %d", $offset, $num) ;

		$posts = Seeds_Utils::get_all( $sql );

		if ( $json == 1) 
		{
			header('Content-type: application/json');
			print json_encode( $posts );
		}
		else 
		{
			return $posts;
		}
	}

}


/**
 * Seeds_Feed class.
 *
	 <code>
	 foreach ($farmers as $farmer)
	 {
	 $f = new Seeds_Feed( $farmer );
	 $f->process();
	 }
	 </code>
 *
 */
class Seeds_Feed {

	public  $url;
	public  $farmer;

	private $quirks = array('tumblr');
	private $content = '';

	/**
	 * __construct function.
	 *
	 * @access public
	 * @param mixed $url
	 * @return void
	 */
	public function __construct($url)
	{
		$this->url = $url;
		$seed = Seeds_Fetcher::factory();
		$this->content = $seed->get($url);
	}

	/**
	 * process function.
	 *
	 * @access public
	 * @return void
	 */
	public function process()
	{
		if ( is_object( $this->content ) ) 
		{
			$this->parse();

			$this->find_ppm();

		}
	}

	/**
	 * parse function.
	 *
	 * @access private
	 * @return void
	 */
	private function parse()
	{
		$this->farmer = $this->content->channel->link;
		$this->save_channel( $this->content->channel );

		foreach ( $this->content->channel->item as $item ) 
		{
			$this->save_item($item);
		}
	}

	/**
	 * save_item function.
	 *
	 * @access private
	 * @param mixed $i
	 * @return void
	 */
	private function save_item($i)
	{
		$sql = array(
			'q' => "REPLACE INTO seeds SET
				guid		= ? ,
				farmer		= ? ,
				link		= ? ,
				title		= ? ,
				description	= ? ,
				image		= ? ,
				posted		= ?
			",
			'p' => array(
				$i->guid,
				$this->farmer,
				$i->link,
				filter_var($i->title, FILTER_SANITIZE_STRING),
				Seeds_Utils::cleaner($i->description),
				self::pix_scan($i),
				strtotime( $i->pubDate )
			
		));

		Seeds_Utils::prepped($sql);

	}


	/**
	 * pix_scan function.
	 *
	 * try to figure out a main pix
	 *
	 * @access private
	 * @param mixed $item
	 * @return void
	 */
	private function pix_scan($item)
	{
		$img = '';

		if ( isset($item->enclosure) ) 
		{
			$img = (string) $item->enclosure->attributes()->url[0];
		}

		//most posts have images
		elseif (isset($this->content->channel->generator) && stristr($this->content->channel->generator, 'tumblr') ||
			    isset($this->content->channel->generator) && stristr($this->content->channel->generator, 'blogger')	) 
		{
			$dom = new DOMDocument;
			libxml_use_internal_errors(true);
			$dom->loadHTML( $item->description );
			$images = $dom->getElementsByTagName('img');

			if ( is_object($images->item(0)) ) 
			{
				$img = $images->item(0)->getAttribute('src');
			}
		}

		//default to FB friendly images : <meta property="og:image"
		elseif ( isset($this->content->channel->generator) && stristr($this->content->channel->generator, 'wordpress.com') ||
			isset($this->content->channel->generator) && stristr($this->content->channel->generator, 'typepad.com') ) 
		{
			$dom = new DOMDocument;
			libxml_use_internal_errors(true);
			$dom->loadHTML( file_get_contents( $item->link ) ); //TODO: abstract like fetcher
			$metas = $dom->getElementsByTagName('meta');

			foreach ($metas as $meta) 
			{
				//grab the first and ignore the rest
				if ( $meta->getAttribute('property') == 'og:image' ) 
				{
					$img = $meta->getAttribute('content');
				}
			}
		}

		//last chance, just git something
		if ($img == '') 
		{
			$dom = new DOMDocument;
			libxml_use_internal_errors(true);
			$dom->loadHTML( file_get_contents( $item->link ) ); //TODO: abstract like fetcher
			$body = $dom->getElementsByTagName('body');

			foreach ($body as $b) 
			{
				$images = $b->getElementsByTagName('img');

				if ( is_object($images->item(0))) 
				{
					$img = $images->item(0)->getAttribute('src');
				}
			}
		}

		//filter out 1x1 tracking pixels
		if (!stristr($img, 'pixel.quantserve.com') && !stristr($img, '.googleusercontent.com/tracker'))
			return $img ;

	}

	/**
	 * find_ppm function:
	 * find avg posts per month - yes, ignoring 0 months for now
	 *
	 * @access private
	 * @return void
	 */
	private function find_ppm()
	{
		$sql = sprintf("SELECT count(guid) as count FROM seeds WHERE farmer='%s' GROUP BY MONTH(FROM_UNIXTIME(posted))",$this->farmer) ;

		$posts = Seeds_Utils::get_all( $sql );

		$c = 0;

		foreach ($posts as $p) 
		{
			$c += $p['count'];
		}
		$avg = $c / count($posts);

		$up = sprintf("UPDATE farmers SET frequency=%d WHERE farmer='%s' ", ceil($avg), $this->farmer);

		Seeds_Utils::query($up);
	}

	//set weight based on avg posts p month
	private function set_weight(){}

	/**
	 * save_channel function.
	 *
	 * @access private
	 * @param mixed $c
	 * @return void
	 */
	private function save_channel($c)
	{

		$sql = array(
			'q' => "REPLACE INTO farmers SET
				farmer		= ? ,
				title		= ? ,
				description	= ?
			",
			'p' => array(
				$this->farmer,
				filter_var($c->title, FILTER_SANITIZE_STRING),
				Seeds_Utils::cleaner($c->description)
		));

		Seeds_Utils::prepped($sql);
	}

	/**
	 * gc function.
	 *
	 * @access public
	 * @static
	 * @return void
	 */
	public static function gc()
	{
		Seeds_Utils::query("DELETE FROM seeds WHERE posted < DATE_SUB(CURDATE(),INTERVAL 1 YEAR)");
	}

}

/**
 * Seeds_Utils class.
 */
class Seeds_Utils {

	/**
	 * prepped function.
	 *
	 * @access public
	 * @static
	 * @param array $sql
	 * @return void
	 */
	static function prepped(array $sql)
	{
		$db = Seeds_Database::getInstance();
		$p  = $db->prepare($sql['q']);
		return $p->execute($sql['p']);
	}

	/**
	 * query function.
	 *
	 * @access public
	 * @static
	 * @param mixed $sql
	 * @return void
	 */
	static function query($sql)
	{
		$db = Seeds_Database::getInstance();
		return $db->query($sql);
	}

	/**
	 * get_all function.
	 *
	 * @access public
	 * @static
	 * @param mixed $sql
	 * @return void
	 */
	static function get_all($sql)
	{
		$db = Seeds_Database::getInstance();
		$res= $db->query($sql);
		if ($res)
			return $res->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * cleaner function.
	 *
	 * @access public
	 * @static
	 * @param mixed $text
	 * @return void
	 */
	static function cleaner($text)
	{
		$text = preg_replace("/<script[^>]*>.*?< *script[^>]*>/i", "", $text);
		$text = preg_replace("/<script[^>]*>/i", "", $text);
		$text = preg_replace("/<style[^>]*>.*<*style[^>]*>/i", "", $text);
		$text = self::strip_tags_attributes($text,'<p><a>');

		return trim($text);
	}


	/**
	 * Sanitize for javascript attributes that will remain on allowed html tags
	 *
	 * thanks!: http://www.experts-exchange.com/Web_Development/Web_Languages-Standards/PHP/Q_24765149.html
	 *
	 * @access private
	 * @param mixed $text
	 * @param array $allowed
	 * @return string $text
	 */
	private function strip_tags_attributes($text, $allowed)
	{
		$disabled = array
		(
			'onabort','onactivate','onafterprint','onafterupdate','onbeforeactivate', 'onbeforecopy', 'onbeforecut',
			'onbeforedeactivate', 'onbeforeeditfocus', 'onbeforepaste', 'onbeforeprint', 'onbeforeunload', 'onbeforeupdate', 'onblur', 'onbounce',
			'oncellchange', 'onchange', 'onclick', 'oncontextmenu', 'oncontrolselect', 'oncopy', 'oncut', 'ondataavaible', 'ondatasetchanged',
			'ondatasetcomplete', 'ondblclick', 'ondeactivate', 'ondrag', 'ondragdrop', 'ondragend', 'ondragenter', 'ondragleave', 'ondragover',
			'ondragstart', 'ondrop', 'onerror', 'onerrorupdate', 'onfilterupdate', 'onfinish', 'onfocus', 'onfocusin', 'onfocusout', 'onhelp',
			'onkeydown', 'onkeypress', 'onkeyup', 'onlayoutcomplete', 'onload', 'onlosecapture', 'onmousedown', 'onmouseenter', 'onmouseleave',
			'onmousemove', 'onmoveout', 'onmouseover', 'onmouseup', 'onmousewheel', 'onmove', 'onmoveend', 'onmovestart', 'onpaste',
			'onpropertychange', 'onreadystatechange', 'onreset', 'onresize', 'onresizeend', 'onresizestart', 'onrowexit', 'onrowsdelete',
			'onrowsinserted', 'onscroll', 'onselect', 'onselectionchange', 'onselectstart', 'onstart', 'onstop', 'onsubmit', 'onunload'
		);

		return preg_replace('/<(.*?)>/ie',
			"'<' . preg_replace(array('/javascript:[^\"\']*/i', '/(" . implode('|', $disabled) . ")[ \\t\\n]*=[ \\t\\n]*[\"\'][^\"\']*[\"\']/i', '/\s+/'), array('', '', ' '), stripslashes('\\1')) . '>'",
			strip_tags($text, $allowed));
	}


	/**
	 * resolve_link function.
	 *
	 * @access public
	 * @static
	 * @param mixed $url
	 * @return void
	 */
	static function resolve_link($url)
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		$a = curl_exec($ch);
		if (preg_match('#Location: (.*)#', $a, $r))
			$l = trim($r[1]);

		return self::remove_queryString($l);
	}

	/**
	 * remove_queryString function.
	 *
	 * @access private
	 * @param mixed $url
	 * @return void
	 */
	private function remove_queryString($url)
	{
		$u = explode('?',$url,-1);
		return $u[0];
	}


}


/**
 * Seeds_Database class.
 * Database Singleton
 */
class Seeds_Database {
	private static $instance=NULL;
	private $dbh;

	/**
	 * __construct function.
	 *
	 * @access private
	 * @return void
	 */
	private function __construct()
	{
		global $db;

		try
		{
			$this->dbh = new PDO('mysql:host='. $db['host'] .';dbname='. $db['name'] , $db['user'], $db['pass'], array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8")  );
			$this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		}
		catch(PDOException $e)
		{
			echo $e->getMessage();
		}
	}

	/**
	 * getInstance function.
	 *
	 * @access public
	 * @static
	 * @return void
	 */
	public static function getInstance()
	{
		if (!self::$instance) {
			self::$instance = new Seeds_Database();
		}

		return self::$instance;
	}

	/**
	 * prepare function.
	 *
	 * @access public
	 * @param mixed $sql
	 * @return void
	 */
	public function prepare($sql)
	{
		try
		{
			return $this->dbh->prepare($sql);
		}
		catch(PDOException $e)
		{
			echo $e->getMessage();
		}

	}

	/**
	 * query function.
	 *
	 * @access public
	 * @param mixed $sql
	 * @return void
	 */
	public function query($sql)
	{
		try
		{
			return $this->dbh->query($sql);
		}
		catch(PDOException $e)
		{
			echo $e->getMessage();
		}

	}

	/**
	 * quote function.
	 *
	 * @access public
	 * @param mixed $sql
	 * @return void
	 */
	public function quote($sql)
	{
		return $this->dbh->quote($sql);
	}

}


/**
 * Abstract Seeds_Fetcher class.
 *
 * @abstract
 */
abstract class Seeds_Fetcher {

	private $method;

	public function __construct(){}

	/**
	 * get function.
	 *
	 * @access public
	 * @abstract
	 * @static
	 * @param mixed $url
	 * @return void
	 */
	abstract static public function get($url);

	/**
	 * factory function.
	 *
	 * @access public
	 * @static
	 * @return void
	 */
	public static function factory()
	{
		if ( function_exists('simplexml_load_file') ) 
		{
			return new Seeds_Fetcher_XML();
		}
		elseif ( ini_get('allow_url_fopen') === true ) 
		{
			return new Seeds_Fetcher_File();
		}
		elseif ( function_exists('curl_init') ) 
		{
			return new Seeds_Fetcher_Curl();
		}
		else 
		{
			trigger_error('Seeds requires allow_url_fopen enabled or Curl to be installed', E_ERROR );
		}
	}

}

/**
 * Seeds_Fetcher_XML class.
 *
 * @extends Seeds_Fetcher
 */
class Seeds_Fetcher_XML extends Seeds_Fetcher {

	/**
	 * get function.
	 *
	 * @access public
	 * @static
	 * @param mixed $url
	 * @return void
	 */
	public static function get($url)
	{
		return simplexml_load_file( urlencode($url) );
	}

}


/**
 * Seeds_Fetcher_File class.
 *
 * @extends Seeds_Fetcher
 */
class Seeds_Fetcher_File extends Seeds_Fetcher {

	/**
	 * get function.
	 *
	 * @access public
	 * @static
	 * @param mixed $url
	 * @return void
	 */
	public static function get($url)
	{
		$content = file_get_contents( urlencode($url) );

		return new SimpleXmlElement($content);
	}

}

/**
 * Seeds_Fetcher_Curl class.
 *
 * @extends Seeds_Fetcher
 */
class Seeds_Fetcher_Curl extends Seeds_Fetcher {

	/**
	 * get function.
	 *
	 * @access public
	 * @static
	 * @param mixed $url
	 * @return void
	 */
	public static function get($url)
	{
		$ch = curl_init( urlencode($url) );

		curl_setopt($ch, CURLOPT_CRLF, 1); /* avoid issues */
		curl_setopt($ch, CURLOPT_HEADER, 0); /* turn on when caching */
		curl_setopt($ch, CURLOPT_USERAGENT, 'Seedsbot/0.1 (+http://justseeds.com/)');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

		$content = curl_exec($ch);

		if (curl_errno($ch)) 
		{
			trigger_error('Curl error:'.  curl_error($ch), E_WARNING );
		}

		curl_close($ch);

		return new SimpleXmlElement($content);
	}

}
