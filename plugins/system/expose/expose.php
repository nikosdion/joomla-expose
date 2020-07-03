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

		/**
		 * Workaround 1. Extensions going through the $_SERVER superglobal directly or use $app->input->server
		 *
		 * This is bad practice in Joomla since we have the Joomla\CMS\Uri\Uri class which gives us more accurate
		 * information when there is a discrepancy between $live_site and the hostname reported by the server. When
		 * using Expose this kind of discrepancy is guaranteed!
		 */
		$_SERVER['REQUEST_SCHEME'] = $forwardedProto;
		$_SERVER['SERVER_NAME']    = $forwardedHost;
		$_SERVER['HTTP_HOST']      = $forwardedHost;

		/**
		 * Workaround 2. Update $live_site in the global configuration for this page load
		 *
		 * The whole point here is that you do NOT want to edit your configuration.php file every time you access your
		 * site through Expose. So we do the next best thing. Tell Joomla sweet little lies for the duration of this
		 * page load.
		 */
		Factory::getConfig()->set('live_site', sprintf('%s://%s', $forwardedProto, $forwardedHost));

		/**
		 * Workaround 3. Update the Uri instances.
		 *
		 * Joomla has already used the Uri object to parse the URL it was accessed on before we have the chance to run.
		 * We will be using Reflection to update the internal instances' host and protocol.
		 */
		// use reflection to modify the Uri class properties
		try
		{
			$refClass = new ReflectionClass(Uri::class);
		}
		catch (ReflectionException $e)
		{
			return;
		}

		$oldDomain = Uri::getInstance()->getHost();

		// Update all Uri instances in memory
		$refInstances = $refClass->getProperty('instances');
		$refInstances->setAccessible(true);
		$instances = array_map(function (Uri $uri) use ($oldDomain, $forwardedHost, $forwardedProto) {
			if ($uri->getHost() != $oldDomain)
			{
				return $uri;
			}

			$uri->setScheme($forwardedProto);
			$uri->setHost($forwardedHost);

			return $uri;
		}, $refInstances->getValue());
		$refInstances->setValue($instances);

		/**
		 * Workaround 4. Base and root URLs
		 *
		 * In some cases (notably: the administrator application) Joomla has already tried to get the base and root URLs
		 * through the Uri class methods. This populates the class variables which now point to the real, not the
		 * Exposed, domain name. Therefore, if they are alreayd set, we need to update them using Reflection.
		 */
		$refBase = $refClass->getProperty('base');
		$refBase->setAccessible(true);
		$base = $refBase->getValue();

		if (!empty($base['prefix'] ?? ''))
		{
			$uri = new Uri(Uri::base());
			$uri->setHost($forwardedHost);
			$uri->setScheme($forwardedProto);
			$refBase->setValue([
				'prefix' => $uri->toString(['scheme', 'user', 'pass', 'host', 'port']),
				'path' => ($uri->toString(['path']) == '/') ? '' : $uri->toString(['path'])
			]);
		}

		$refRoot = $refClass->getProperty('root');
		$refRoot->setAccessible(true);
		$root = $refRoot->getValue();

		if (!empty($root['prefix'] ?? ''))
		{
			$uri = new Uri(Uri::root());
			$uri->setHost($forwardedHost);
			$uri->setScheme($forwardedProto);
			$refRoot->setValue([
				'prefix' => $uri->toString(['scheme', 'user', 'pass', 'host', 'port']),
				'path' => ($uri->toString(['path']) == '/') ? '' : $uri->toString(['path'])
			]);
		}

		$refCurrent = $refClass->getProperty('current');
		$refCurrent->setAccessible(true);
		$refCurrent->setValue(null);
	}
}