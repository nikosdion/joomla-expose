<?php
/**
 * @package   ExposeJoomla
 * @copyright Copyright (c)2020-2024 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') or die;

use Dionysopoulos\Plugin\System\Expose\Extension\Expose;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class implements ServiceProviderInterface {
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 *
	 * @since   2.0.0
	 */
	public function register(Container $container)
	{
		$container->set(
			PluginInterface::class,
			function (Container $container) {
				$dispatcher = $container->get(DispatcherInterface::class);
				$config     = (array) PluginHelper::getPlugin('system', 'expose');
				$plugin     = new Expose(
					$dispatcher,
					$config
				);

				$plugin->setApplication(Factory::getApplication());

				return $plugin;
			}
		);
	}
};
