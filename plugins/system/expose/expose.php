<?php
/**
 * @package   ExposeJoomla
 * @copyright Copyright (c)2020-2020 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;

class plgSystemExpose extends CMSPlugin
{
	public function onAfterInitialise()
	{
		// Make sure we're being accessed through Expose
		$exposed = $_SERVER['HTTP_X_EXPOSED_BY'] ?? null;

		if (empty($exposed) || !is_string($exposed) || substr($exposed, 0, 7) != 'Expose ')
		{
			return;
		}

		// Get the forwarded host and protocol
		$forwardedHost  = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? null;
		$forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'http';
		$forwardedProto = in_array($forwardedProto, ['http', 'https']) ? $forwardedProto : 'http';

		// No host? Something went wrong; bail.
		if (empty($forwardedHost))
		{
			return;
		}

		// Change of the live site URL to the forwarded protocol and host
		Factory::getConfig()->set('live_site', sprintf('%s://%s', $forwardedProto, $forwardedHost));

		// At this point Joomla may have already picked up the domain name from the server. Change it!
		$uri = Uri::getInstance();
		$uri->setScheme($forwardedProto);
		$uri->setHost($forwardedHost);

		// This shouldn't be necessary but you can't be *too* cautious
		$_SERVER['REQUEST_SCHEME'] = $forwardedProto;
		$_SERVER['SERVER_NAME'] = $forwardedHost;
		$_SERVER['HTTP_HOST'] = $forwardedHost;
	}
}