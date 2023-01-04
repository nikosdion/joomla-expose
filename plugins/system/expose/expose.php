<?php
/**
 * @package   ExposeJoomla
 * @copyright Copyright (c)2020-2023 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;

/**
 * Class plgSystemExpose
 *
 * @noinspection PhpUnused
 */
class plgSystemExpose extends CMSPlugin
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

	public function onAfterInitialise()
	{
		// Make sure we're being accessed through Expose
		$exposed = $_SERVER['HTTP_X_EXPOSED_BY'] ?? null;

		if (empty($exposed) || !is_string($exposed) || substr($exposed, 0, 7) != 'Expose ')
		{
			return;
		}

		// Do we need to check the local domain?
		$localDomain = $this->params->get('domain', '');

		if (!empty($localDomain) && !$this->isDomain($localDomain))
		{
			return;
		}

		// Do we need to check the local IP?
		if ($this->params->get('only_internal', 1) == 1 && !$this->isPrivateIPDomain())
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
		$refClass = new ReflectionClass(Uri::class);

		$oldDomain = Uri::getInstance()->getHost();

		// Update all Uri instances in memory
		$refInstances = $refClass->getProperty('instances');
		$refInstances->setAccessible(true);
		$serverUri = Uri::getInstance();
		$serverUri->setScheme($forwardedProto);
		$serverUri->setHost($forwardedHost);
		$instances = [
			'SERVER' => new Uri($serverUri->toString())
		];
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
				'path'   => ($uri->toString(['path']) == '/') ? '' : $uri->toString(['path']),
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
				'path'   => ($uri->toString(['path']) == '/') ? '' : $uri->toString(['path']),
			]);
		}

		$refCurrent = $refClass->getProperty('current');
		$refCurrent->setAccessible(true);
		$refCurrent->setValue(null);
	}

	/**
	 * Converts inet_pton output to bits string
	 *
	 * @param   string  $inet  The in_addr representation of an IPv4 or IPv6 address
	 *
	 * @return  string
	 */
	private function inet_to_bits($inet)
	{
		if (strlen($inet) == 4)
		{
			$unpacked = unpack('C4', $inet);
		}
		else
		{
			$unpacked = unpack('C16', $inet);
		}

		$binaryip = '';

		foreach ($unpacked as $byte)
		{
			$binaryip .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
		}

		return $binaryip;
	}

	/**
	 * Checks if an IPv6 address $ip is part of the IPv6 CIDR block $cidrnet
	 *
	 * @param   string  $ip       The IPv6 address to check, e.g. 21DA:00D3:0000:2F3B:02AC:00FF:FE28:9C5A
	 * @param   string  $cidrnet  The IPv6 CIDR block, e.g. 21DA:00D3:0000:2F3B::/64
	 *
	 * @return  bool
	 */
	private function checkIPv6CIDR($ip, $cidrnet)
	{
		$ip       = inet_pton($ip);
		$binaryip = $this->inet_to_bits($ip);

		[$net, $maskbits] = explode('/', $cidrnet);
		$net       = inet_pton($net);
		$binarynet = $this->inet_to_bits($net);

		$ip_net_bits = substr($binaryip, 0, $maskbits);
		$net_bits    = substr($binarynet, 0, $maskbits);

		return $ip_net_bits === $net_bits;
	}

	/**
	 * Is it an IPv6 IP address?
	 *
	 * @param   string  $ip  An IPv4 or IPv6 address
	 *
	 * @return  boolean  True if it's IPv6
	 */
	private function isIPv6($ip)
	{
		if (strstr($ip, ':'))
		{
			return true;
		}

		return false;
	}

	/**
	 * Checks if an IP is contained in a list of IPs or IP expressions
	 *
	 * @param   string        $ip       The IPv4/IPv6 address to check
	 * @param   array|string  $ipTable  An IP expression (or a comma-separated or array list of IP expressions) to
	 *                                  check against
	 *
	 * @return  null|boolean  True if it's in the list
	 */
	private function IPinList($ip, $ipTable = '')
	{
		// No point proceeding with an empty IP list
		if (empty($ipTable))
		{
			return false;
		}

		// If the IP list is not an array, convert it to an array
		if (!is_array($ipTable))
		{
			if (strpos($ipTable, ',') !== false)
			{
				$ipTable = explode(',', $ipTable);
				$ipTable = array_map(function ($x) {
					return trim($x);
				}, $ipTable);
			}
			else
			{
				$ipTable = trim($ipTable);
				$ipTable = [$ipTable];
			}
		}

		// If no IP address is found, return false
		if ($ip == '0.0.0.0')
		{
			return false;
		}

		// If no IP is given, return false
		if (empty($ip))
		{
			return false;
		}

		// Sanity check
		if (!function_exists('inet_pton'))
		{
			return false;
		}

		// Get the IP's in_adds representation
		$myIP = @inet_pton($ip);

		// If the IP is in an unrecognisable format, quite
		if ($myIP === false)
		{
			return false;
		}

		$ipv6     = $this->isIPv6($ip);
		$binaryip = $this->inet_to_bits($myIP);

		foreach ($ipTable as $ipExpression)
		{
			$ipExpression = trim($ipExpression);

			[$net, $maskbits] = explode('/', $ipExpression, 2);

			if ($ipv6 && !$this->isIPv6($net))
			{
				// Do not apply IPv4 filtering on an IPv6 address
				continue;
			}

			if (!$ipv6 && $this->isIPv6($net))
			{
				// Do not apply IPv6 filtering on an IPv4 address
				continue;
			}

			if ($ipv6)
			{
				// Perform an IPv6 CIDR check
				if ($this->checkIPv6CIDR($myIP, $ipExpression))
				{
					return true;
				}

				// If we didn't match it proceed to the next expression
				continue;
			}

			if (!$ipv6 && strstr($maskbits, '.'))
			{
				// Convert IPv4 netmask to CIDR
				$long     = ip2long($maskbits);
				$base     = ip2long('255.255.255.255');
				$maskbits = 32 - log(($long ^ $base) + 1, 2);
			}

			// Convert network IP to in_addr representation
			$net = @inet_pton($net);

			// Sanity check
			if ($net === false)
			{
				continue;
			}

			// Get the network's binary representation
			$binarynet            = $this->inet_to_bits($net);
			$expectedNumberOfBits = $ipv6 ? 128 : 24;
			$binarynet            = str_pad($binarynet, $expectedNumberOfBits, '0', STR_PAD_RIGHT);

			// Check the corresponding bits of the IP and the network
			$ip_net_bits = substr($binaryip, 0, $maskbits);
			$net_bits    = substr($binarynet, 0, $maskbits);

			if ($ip_net_bits == $net_bits)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks if the site is being accessed through the defined domain
	 *
	 * @param   string  $localDomain
	 *
	 * @return  bool
	 */
	private function isDomain(string $localDomain): bool
	{
		$uri = Uri::getInstance();

		return $uri->getHost() === $localDomain;
	}

	/**
	 * Is the site being accessed on a domain that resolves to a private network or localhost IP?
	 *
	 * @return bool
	 */
	private function isPrivateIPDomain(): bool
	{
		// Get the domain name we are being accessed on
		$host = Uri::getInstance()->getHost();

		// Get and check its IPv4, if possible
		$ipv4 = gethostbyname($host);
		$ipv4 = ($ipv4 === $host) ? null : $ipv4;

		if (!empty($ipv4) && $this->IPinList($ipv4, self::INTERNAL_NETWORK_IPV4_RANGES))
		{
			return true;
		}

		// Get and check its IPv6, if possible
		$ipv6 = $this->getIPv6FromHostname($host);

		if (!empty($ipv6) && $this->IPinList($ipv6, self::INTERNAL_NETWORK_IPV6_RANGES))
		{
			return true;
		}

		// All checks failed, this is probably a publicly accessible server.
		return false;
	}

	/**
	 * Resolves a hostname to an IPv6 address -- as long as there's a AAAA record for it.
	 *
	 * If the domain name is a CNAME it won't resolve to an IPv6 address. However, if it's a CNAME it's unlikely that
	 * it's a local domain. Even in this case you can simply use the local domain option.
	 *
	 * @param   string  $host
	 *
	 * @return  string|null
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
}