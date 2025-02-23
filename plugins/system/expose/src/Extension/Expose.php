<?php
/**
 * @package   ExposeJoomla
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

namespace Dionysopoulos\Plugin\System\Expose\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Utilities\IpHelper;
use ReflectionClass;
use ReflectionException;

/**
 * Class plgSystemExpose
 *
 * @noinspection PhpUnused
 */
class Expose extends CMSPlugin implements SubscriberInterface
{
	/**
	 * IPv4 localhost and private network IP ranges
	 */
	private const INTERNAL_NETWORK_IPV4_RANGES = [
		'127.0.0.0/8',
		'10.0.0.0/8',
		'172.16.0.0/12',
		'192.168.0.0/16',
	];

	/**
	 * IPv6 localhost and private network IP ranges
	 */
	private const INTERNAL_NETWORK_IPV6_RANGES = [
		'::1/128',
		'fc00::/7',
	];

	/**
	 * Forwarded hostname
	 *
	 * @var   string|null
	 * @since 2.0.0
	 */
	private $forwardedHost = null;

	/**
	 * Forwarded protocol (URL scheme)
	 *
	 * @var    string|null
	 * @since  2.0.0
	 */
	private $forwardedProto = null;

	/**
	 * Forwarded TCP/IP port
	 *
	 * @var    int|null
	 * @since  2.0.0
	 */
	private $forwardedPort = null;

	/**
	 * Returns an array of events this subscriber will listen to.
	 *
	 * @return  array
	 *
	 * @since   9.0.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onAfterInitialise' => 'handleExpose',
		];
	}

	/**
	 * onAfterInitialize event handler
	 *
	 * @param   Event  $event
	 *
	 * @return  void
	 *
	 * @noinspection PhpUnused
	 * @noinspection PhpUnusedParameterInspection
	 * @throws       ReflectionException
	 * @since        2.0.0
	 */
	public function handleExpose(Event $event)
	{
		// Make sure there is request forwarding information.
		$this->populateForwardedInformation();

		if (empty($this->forwardedHost))
		{
			return;
		}

		// Make sure we're being accessed through Expose
		$strict = $this->params->get('strict', 1);

		if (!$strict)
		{
			$exposed = $this->getApplication()->input->server->get('HTTP_X_EXPOSED_BY', null, 'raw');

			if (empty($exposed) || !is_string($exposed) || substr($exposed, 0, 7) != 'Expose ')
			{
				return;
			}
		}

		// Check the local domain, if configured.
		$localDomain = $this->params->get('domain', '');

		if (!empty($localDomain) && !$this->isDomain($localDomain))
		{
			return;
		}

		// Check the use of an internal network IP address, if configured.
		if ($this->params->get('only_internal', 1) == 1 && !$this->isPrivateIPDomain())
		{
			return;
		}

		$this->enableIPOverrides();
		$this->amendServerHeaders();
		$this->applyLiveSite();
		$this->resetUriCache();
	}

	/**
	 * Get the forwarded hostname, protocol, and port from the request, populating the respective object properties.
	 *
	 * @return  void
	 * @since   2.0.0
	 */
	private function populateForwardedInformation(): void
	{
		$serverInput = $this->getApplication()->input->server;

		// Get the forwarded host
		$this->forwardedHost = $serverInput->get('HTTP_X_FORWARDED_HOST', null, 'raw');

		if (empty($this->forwardedHost))
		{
			// If there's no forwarded hostname we shouldn't proceed; there's no point.
			return;
		}

		// Get the forwarded protocol (scheme)
		$this->forwardedProto = $serverInput->get('HTTP_X_FORWARDED_PROTO', 'http', 'string');
		$this->forwardedProto = in_array($this->forwardedProto, ['http', 'https']) ? $this->forwardedProto : 'http';

		// Get the forwarded port
		$this->forwardedPort = $serverInput->get('HTTP_X_FORWARDED_PORT', -1, 'int');
		$this->forwardedPort = $this->forwardedPort <= 0 || $this->forwardedPort > 65535 ? null : $this->forwardedPort;

		if (strtolower($this->forwardedProto) === 'http' && $this->forwardedPort === 80)
		{
			$this->forwardedPort = null;
		}
		elseif (strtolower($this->forwardedProto) === 'https' && $this->forwardedPort === 443)
		{
			$this->forwardedPort = null;
		}
	}

	/**
	 * Sets a server header in the environment.
	 *
	 * This set the server header in the following contexts:
	 * * Global application server input object.
	 * * `$_SERVER` superglobal.
	 * * `$_ENV` superglobal.
	 *
	 * @param   string  $key                       The key to set.
	 * @param   string  $value                     The value to set it to.
	 * @param   bool    $onlyOverwriteSuperGlobal  Apply the value to superglobals only if the superglobal already has
	 *                                             a value for this key (do not craete a new key in the superglobal).
	 *
	 * @return  void
	 * @since   2.0.0
	 */
	private function setServerHeader(string $key, string $value, bool $onlyOverwriteSuperGlobal = true): void
	{
		// Set the value to the global application input
		$this->getApplication()->input->server->set($key, $value);

		// Set the $_SERVER superglobal directly
		if (!$onlyOverwriteSuperGlobal || isset($_SERVER[$key]))
		{
			$_SERVER[$key] = $value;
		}

		// Set the $_ENV superglobal directly
		if (!$onlyOverwriteSuperGlobal || isset($_ENV[$key]))
		{
			$_ENV[$key] = $value;
		}
	}

	/**
	 * Checks if the site is being accessed through the defined domain.
	 *
	 * @param   string  $localDomain
	 *
	 * @return  bool
	 * @since   1.0.0
	 */
	private function isDomain(string $localDomain): bool
	{
		$uri = Uri::getInstance();

		return $uri->getHost() === $localDomain;
	}

	/**
	 * Is the site being accessed on a domain that resolves to a private network or localhost IP?
	 *
	 * @return  bool
	 * @since   1.0.0
	 */
	private function isPrivateIPDomain(): bool
	{
		// Get the domain name we are being accessed on
		$host = Uri::getInstance()->getHost();

		// Get and check its IPv4, if possible
		$ipv4 = gethostbyname($host);
		$ipv4 = ($ipv4 === $host) ? null : $ipv4;

		if (!empty($ipv4) && IpHelper::IPinList($ipv4, self::INTERNAL_NETWORK_IPV4_RANGES))
		{
			return true;
		}

		// Get and check its IPv6, if possible
		$ipv6 = $this->getIPv6FromHostname($host);

		if (!empty($ipv6) && IpHelper::IPinList($ipv6, self::INTERNAL_NETWORK_IPV6_RANGES))
		{
			return true;
		}

		// All checks failed, this is probably a publicly accessible server.
		return false;
	}

	/**
	 * Resolves a hostname to an IPv6 address, as long as there's an AAAA record for it.
	 *
	 * If the domain name is a CNAME it won't resolve to an IPv6 address. However, if it's a CNAME it's unlikely it's a
	 * local domain. Even in this case you can simply use the local domain option.
	 *
	 * @param   string  $host
	 *
	 * @return  string|null
	 * @since   1.0.0
	 */
	private function getIPv6FromHostname(string $host): ?string
	{
		$dns = dns_get_record($host, DNS_AAAA);

		foreach ($dns as $record)
		{
			if ($record['type'] === 'AAAA')
			{
				return $record['ipv6'];
			}
		}

		return null;
	}

	/**
	 * Forcibly enables the IP overrides for Joomla's IP helper.
	 *
	 * @return  void
	 * @since   2.0.0
	 */
	private function enableIPOverrides(): void
	{
		$mustEnable = true;

		// Check if the Global Configuration setting Behind Load Balancer is enabled; it enables IP overrides.
		if ($this->app->get('behind_loadbalancer', 0))
		{
			$mustEnable = false;
		}

		// Check if Allow IP Overrides has already been set in the IPHelper class.
		if (!$mustEnable)
		{
			$refClass = new ReflectionClass(IpHelper::class);
			$refProp  = $refClass->getProperty('allowIpOverrides');
			$refProp->setAccessible(true);
			$allowedIpOverrides = $refProp->getValue();

			if ($allowedIpOverrides)
			{
				$mustEnable = false;
			}
		}

		// If I must forcibly enable the IP overrides, let's do it.
		if ($mustEnable)
		{
			// Enable IP overrides.
			IpHelper::setAllowIpOverrides(true);

			// Clear the internal visitor IP cache of Joomla.
			$refProp = $refClass->getProperty('ip');
			$refProp->setAccessible(true);
			$refProp->setValue(null);
		}

		// Ask the IP Helper to fix the REMOTE_ADDR, if necessary.
		IpHelper::workaroundIPIssues();

		// Make sure REMOTE_ADDR is fixed everywhere.
		$this->setServerHeader('REMOTE_ADDR', IpHelper::getIp());
	}

	/**
	 * Changes the server headers in the application environment.
	 *
	 * Replaces the REQUEST_SCHEME, SERVER_NAME, and HTTP_HOST in the PHP environment, and Joomla's server input object
	 * in the application.
	 *
	 * This is for extensions going through the $_SERVER superglobal directly or use $app->input->server.
	 *
	 * This is bad practice in Joomla since we have the Joomla\CMS\Uri\Uri class which gives us more accurate
	 * information when there is a discrepancy between $live_site and the hostname reported by the server. When
	 * using Expose this kind of discrepancy is guaranteed!
	 *
	 * @return  void
	 * @since   2.0.0
	 */
	private function amendServerHeaders(): void
	{
		$this->setServerHeader('REQUEST_SCHEME', $this->forwardedProto);
		$this->setServerHeader('SERVER_NAME', $this->forwardedHost);
		$this->setServerHeader('HTTP_HOST', $this->forwardedHost);
	}

	/**
	 * Update the live_site in Joomla's configuration.
	 *
	 * The whole point here is that you do NOT want to edit your configuration.php file every time you access your
	 * site through Expose. So we do the next best thing. Tell Joomla sweet little lies for the duration of this
	 * page load.
	 *
	 * @return  void
	 * @since   2.0.0
	 */
	private function applyLiveSite(): void
	{
		$liveSiteUri = new Uri(sprintf('%s://%s', $this->forwardedProto, $this->forwardedHost));

		if (!empty($this->forwardedPort))
		{
			$liveSiteUri->setPort($this->forwardedPort);
		}

		$config = $this->getApplication()->getConfig();
		$config->set('live_site', $liveSiteUri->toString());
		$this->getApplication()->setConfiguration($config);
	}

	/**
	 * Reset the global Joomla URI helper cache.
	 *
	 * Joomla has already used the Uri object to parse the URL it was accessed on before we have the chance to run.
	 * We will be using Reflection to reset the object's internal cache.
	 *
	 * @return  void
	 * @throws  ReflectionException
	 */
	private function resetUriCache(): void
	{
		$refClass = new ReflectionClass(Uri::class);

		foreach (
			[
				'instances' => [],
				'base'      => [],
				'root'      => [],
				'current'   => null,
			] as $property => $newValue
		)
		{
			$refProp = $refClass->getProperty($property);
			$refProp->setAccessible(true);
			$refProp->setValue($newValue);
		}
	}
}