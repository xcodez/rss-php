<?php namespace PhpMyCoder\RssPhp;

/**
 * RSS for PHP - small and easy-to-use library for consuming an RSS Feed
 *
 * @author     David Grudl
 * @copyright  Copyright (c) 2008 David Grudl
 * @license    New BSD License
 * @link       http://phpfashion.com/
 * @version    1.1
 */
class Feed
{
	/** @var int */
	public static $cacheExpire = 86400; // 1 day

	/** @var string */
	public static $cacheDir;

	/** @var SimpleXMLElement */
	protected $xml;


	/**
	 * Loads RSS channel.
	 * @param  string  RSS feed URL
	 * @param  string  optional user name
	 * @param  string  optional password
	 * @return Feed
	 * @throws FeedException
	 */
	public static function loadRss($url, $user = NULL, $pass = NULL)
	{
		$xml = new SimpleXMLElement(self::httpRequest($url, $user, $pass), LIBXML_NOWARNING | LIBXML_NOERROR);
		if (!$xml->channel) {
			throw new FeedException('Invalid channel.');
		}

		self::adjustNamespaces($xml->channel);

		foreach ($xml->channel->item as $item) {
			// converts namespaces to dotted tags
			self::adjustNamespaces($item);

			// generate 'timestamp' tag
			if (isset($item->{'dc:date'})) {
				$item->timestamp = strtotime($item->{'dc:date'});
			} elseif (isset($item->pubDate)) {
				$item->timestamp = strtotime($item->pubDate);
			}
		}

		$feed = new self;
		$feed->xml = $xml->channel;
		return $feed;
	}


	/**
	 * Loads Atom channel.
	 * @param  string  Atom feed URL
	 * @param  string  optional user name
	 * @param  string  optional password
	 * @return Feed
	 * @throws FeedException
	 */
	public static function loadAtom($url, $user = NULL, $pass = NULL)
	{
		$xml = new SimpleXMLElement(self::httpRequest($url, $user, $pass), LIBXML_NOWARNING | LIBXML_NOERROR);
		if (!in_array('http://www.w3.org/2005/Atom', $xml->getDocNamespaces(), TRUE)) {
			throw new FeedException('Invalid channel.');
		}

		// generate 'timestamp' tag
		foreach ($xml->entry as $entry) {
			$entry->timestamp = strtotime($entry->updated);
		}

		$feed = new self;
		$feed->xml = $xml;
		return $feed;
	}


	/**
	 * Returns property value. Do not call directly.
	 * @param  string  tag name
	 * @return SimpleXMLElement
	 */
	public function __get($name)
	{
		return $this->xml->{$name};
	}


	/**
	 * Sets value of a property. Do not call directly.
	 * @param  string  property name
	 * @param  mixed   property value
	 * @return void
	 */
	public function __set($name, $value)
	{
		throw new Exception("Cannot assign to a read-only property '$name'.");
	}


	/**
	 * Converts a SimpleXMLElement into an array.
	 * @param  SimpleXMLElement
	 * @return array
	 */
	public function toArray(SimpleXMLElement $xml = NULL)
	{
		if ($xml === NULL) {
			$xml = $this->xml;
		}

		if (!$xml->children()) {
			return (string) $xml;
		}

		$arr = array();
		foreach ($xml->children() as $tag => $child) {
			if (count($xml->$tag) === 1) {
				$arr[$tag] = $this->toArray($child);
			} else {
				$arr[$tag][] = $this->toArray($child);
			}
		}

		return $arr;
	}


	/**
	 * Process HTTP request.
	 * @param  string  URL
	 * @param  string  user name
	 * @param  string  password
	 * @return string
	 * @throws FeedException
	 */
	private static function httpRequest($url, $user, $pass)
	{
		if (self::$cacheDir) {
			$cacheFile = self::$cacheDir . '/feed.' . md5($url) . '.xml';
			if (@filemtime($cacheFile) + self::$cacheExpire > time()) {
				return file_get_contents($cacheFile);
			}
		}

		if (extension_loaded('curl')) {
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_URL, $url);
			if ($user !== NULL || $pass !== NULL) {
				curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass");
			}
			curl_setopt($curl, CURLOPT_HEADER, FALSE);
			curl_setopt($curl, CURLOPT_TIMEOUT, 20);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE); // no echo, just return result
			if (!ini_get('open_basedir')) {
				curl_setopt($curl, CURLOPT_FOLLOWLOCATION, TRUE); // sometime is useful :)
			}
			$result = curl_exec($curl);
			$ok = curl_errno($curl) === 0 && curl_getinfo($curl, CURLINFO_HTTP_CODE) === 200;

		} elseif ($user === NULL && $pass === NULL) {
			$result = file_get_contents($url);
			$ok = is_string($result);

		} else {
			throw new FeedException('PHP extension CURL is not loaded.');
		}

		if (!$ok) {
			if (isset($cacheFile)) {
				$result = @file_get_contents($cacheFile);
				if (is_string($result)) {
					return $result;
				}
			}
			throw new FeedException('Cannot load channel.');
		}

		if (isset($cacheFile)) {
			file_put_contents($cacheFile, $result);
		}

		return $result;
	}


	/**
	 * Generates better accessible namespaced tags.
	 * @param  SimpleXMLElement
	 * @return void
	 */
	private static function adjustNamespaces($el)
	{
		foreach ($el->getNamespaces(TRUE) as $prefix => $ns) {
			$children = $el->children($ns);
			foreach ($children as $tag => $content) {
				$el->{$prefix . ':' . $tag} = $content;
			}
		}
	}

}
