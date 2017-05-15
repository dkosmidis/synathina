<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.sef
 *
 * @copyright   Copyright (C) 2005 - 2016 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

/**
 * Core SEF Plugin.
 *
 * @since  1.5
 */
class PlgSystemSef extends JPlugin
{
	/**
	 * Application object.
	 *
	 * @var    JApplicationCms
	 * @since  3.5
	 */
	protected $app;

	/**
	 * Add the canonical uri to the head.
	 *
	 * @return  void
	 *
	 * @since   3.5
	 */
	public function onAfterDispatch()
	{
		$doc = $this->app->getDocument();

		if (!$this->app->isSite() || $doc->getType() !== 'html')
		{
			return;
		}

		$uri     = JUri::getInstance();
		$domain  = $this->params->get('domain');

		if ($domain === false || $domain === '')
		{
			$domain = $uri->toString(array('scheme', 'host', 'port'));
		}

		$link = $domain . JRoute::_('index.php?' . http_build_query($this->app->getRouter()->getVars()), false);

		if (rawurldecode($uri->toString()) !== $link)
		{
			$doc->addHeadLink(htmlspecialchars($link), 'canonical');
		}
	}

	/**
	 * Convert the site URL to fit to the HTTP request.
	 *
	 * @return  void
	 */
	public function onAfterRender()
	{
		if (!$this->app->isSite() || $this->app->get('sef', '0') == '0')
		{
			return;
		}

		// Replace src links.
		$base   = JUri::base(true) . '/';
		$buffer = $this->app->getBody();

		// Replace index.php URI by SEF URI.
		if (strpos($buffer, 'href="index.php?') !== false)
		{
			preg_match_all('#href="index.php\?([^"]+)"#m', $buffer, $matches);
			foreach ($matches[1] as $urlQueryString)
			{
				$buffer = str_replace('href="index.php?' . $urlQueryString . '"', 'href="' . JRoute::_('index.php?' . $urlQueryString) . '"', $buffer);
			}
			$this->checkBuffer($buffer);
		}

		// Check for all unknown protocals (a protocol must contain at least one alpahnumeric character followed by a ":").
		$protocols = '[a-zA-Z0-9\-]+:';
		$attributes = array('href=', 'src=', 'poster=');
		foreach ($attributes as $attribute)
		{
			if (strpos($buffer, $attribute) !== false)
			{
				$regex  = '#\s+' . $attribute . '"(?!/|' . $protocols . '|\#|\')([^"]+)"#m';
				$buffer = preg_replace($regex, ' ' . $attribute . '"' . $base . '$1"', $buffer);
				$this->checkBuffer($buffer);
			}
		}

		// Replace all unknown protocals in javascript window open events.
		if (strpos($buffer, 'window.open(') !== false)
		{
			$regex  = '#onclick="window.open\(\'(?!/|' . $protocols . '|\#)([^/]+[^\']*?\')#m';
			$buffer = preg_replace($regex, 'onclick="window.open(\'' . $base . '$1', $buffer);
			$this->checkBuffer($buffer);
		}

		// Replace all unknown protocols in onmouseover and onmouseout attributes.
		$attributes = array('onmouseover=', 'onmouseout=');
		foreach ($attributes as $attribute)
		{
			if (strpos($buffer, $attribute) !== false)
			{
				$regex  = '#' . $attribute . '"this.src=([\']+)(?!/|' . $protocols . '|\#|\')([^"]+)"#m';
				$buffer = preg_replace($regex, $attribute . '"this.src=$1' . $base . '$2"', $buffer);
				$this->checkBuffer($buffer);
			}
		}

		// Replace all unknown protocols in CSS background image.
		if (strpos($buffer, 'style=') !== false)
		{
			$regex  = '#style=\s*[\'\"](.*):\s*url\s*\([\'\"]?(?!/|' . $protocols . '|\#)([^\)\'\"]+)[\'\"]?\)#m';
			$buffer = preg_replace($regex, 'style="$1: url(\'' . $base . '$2$3\')', $buffer);
			$this->checkBuffer($buffer);
		}

		// Replace all unknown protocols in OBJECT param tag.
		if (strpos($buffer, '<param') !== false)
		{
			// OBJECT <param name="xx", value="yy"> -- fix it only inside the <param> tag.
			$regex  = '#(<param\s+)name\s*=\s*"(movie|src|url)"[^>]\s*value\s*=\s*"(?!/|' . $protocols . '|\#|\')([^"]*)"#m';
			$buffer = preg_replace($regex, '$1name="$2" value="' . $base . '$3"', $buffer);
			$this->checkBuffer($buffer);

			// OBJECT <param value="xx", name="yy"> -- fix it only inside the <param> tag.
			$regex  = '#(<param\s+[^>]*)value\s*=\s*"(?!/|' . $protocols . '|\#|\')([^"]*)"\s*name\s*=\s*"(movie|src|url)"#m';
			$buffer = preg_replace($regex, '<param value="' . $base . '$2" name="$3"', $buffer);
			$this->checkBuffer($buffer);
		}

		// Replace all unknown protocols in OBJECT tag.
		if (strpos($buffer, '<object') !== false)
		{
			$regex  = '#(<object\s+[^>]*)data\s*=\s*"(?!/|' . $protocols . '|\#|\')([^"]*)"#m';
			$buffer = preg_replace($regex, '$1data="' . $base . '$2"', $buffer);
			$this->checkBuffer($buffer);
		}

		// Use the replaced HTML body.
		$this->app->setBody($buffer);
	}

	/**
	 * Check the buffer.
	 *
	 * @param   string  $buffer  Buffer to be checked.
	 *
	 * @return  void
	 */
	private function checkBuffer($buffer)
	{
		if ($buffer === null)
		{
			switch (preg_last_error())
			{
				case PREG_BACKTRACK_LIMIT_ERROR:
					$message = "PHP regular expression limit reached (pcre.backtrack_limit)";
					break;
				case PREG_RECURSION_LIMIT_ERROR:
					$message = "PHP regular expression limit reached (pcre.recursion_limit)";
					break;
				case PREG_BAD_UTF8_ERROR:
					$message = "Bad UTF8 passed to PCRE function";
					break;
				default:
					$message = "Unknown PCRE error calling PCRE function";
			}

			throw new RuntimeException($message);
		}
	}

	/**
	 * Replace the matched tags.
	 *
	 * @param   array  &$matches  An array of matches (see preg_match_all).
	 *
	 * @return  string
	 *
	 * @deprecated  4.0  No replacement.
	 */
	protected static function route(&$matches)
	{
		JLog::add(__METHOD__ . ' is deprecated, no replacement.', JLog::WARNING, 'deprecated');

		$url   = $matches[1];
		$url   = str_replace('&amp;', '&', $url);
		$route = JRoute::_('index.php?' . $url);

		return 'href="' . $route;
	}
}