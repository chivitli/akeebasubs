<?php
/**
 * @package        akeebasubs
 * @copyright      Copyright (c)2010-2017 Nicholas K. Dionysopoulos / AkeebaBackup.com
 * @license        GNU GPLv3 <http://www.gnu.org/licenses/gpl.html> or later
 */

defined('_JEXEC') or die();

JLoader::import('joomla.plugin.plugin');

use FOF30\Container\Container;
use Akeeba\Subscriptions\Admin\Model\Levels;
use Akeeba\Subscriptions\Admin\Model\Subscriptions;
use FOF30\Date\Date;

class plgSystemAsfixrenewalsflag extends JPlugin
{
	/**
	 * Should this plugin be allowed to run? True if FOF can be loaded and the Akeeba Subscriptions component is enabled
	 *
	 * @var  bool
	 */
	private $enabled = true;

	/**
	 * Public constructor. Overridden to load the language strings.
	 */
	public function __construct(& $subject, $config = array())
	{
		if (!defined('FOF30_INCLUDED') && !@include_once(JPATH_LIBRARIES . '/fof30/include.php'))
		{
			$this->enabled = false;
		}

		// Do not run if Akeeba Subscriptions is not enabled
		JLoader::import('joomla.application.component.helper');

		if (!JComponentHelper::isEnabled('com_akeebasubs'))
		{
			$this->enabled = false;
		}

		if (!is_object($config['params']))
		{
			JLoader::import('joomla.registry.registry');
			$config['params'] = new JRegistry($config['params']);
		}

		parent::__construct($subject, $config);

		// Timezone fix; avoids errors printed out by PHP 5.3.3+ (thanks Yannick!)
		if (function_exists('date_default_timezone_get') && function_exists('date_default_timezone_set'))
		{
			if (function_exists('error_reporting'))
			{
				$oldLevel = error_reporting(0);
			}

			$serverTimezone = @date_default_timezone_get();

			if (empty($serverTimezone) || !is_string($serverTimezone))
			{
				$serverTimezone = 'UTC';
			}

			if (function_exists('error_reporting'))
			{
				error_reporting($oldLevel);
			}

			@date_default_timezone_set($serverTimezone);
		}
	}

	/**
	 * Called when Joomla! is booting up and checks the subscriptions statuses.
	 * If a subscription is close to expiration, it sends out an email to the user.
	 */
	public function onAfterInitialise()
	{
		if (!$this->enabled)
		{
			return;
		}

		// Check if we need to run
		if (!$this->doIHaveToRun())
		{
			return;
		}

		$this->onAkeebasubsCronTask('fixrenewalsflag');
	}

	public function onAkeebasubsCronTask($task, $options = array())
	{
		if (!$this->enabled)
		{
			return;
		}

		if ($task != 'fixrenewalsflag')
		{
			return;
		}

		$defaultOptions = array(
			'time_limit' => 2,
		);

		$options = array_merge($defaultOptions, $options);

		\JLog::add("Starting Fixing the Contact flag on renewals- Time limit {$options['time_limit']} seconds", \JLog::DEBUG, "akeebasubs.cron.fixrenewalsflag");

		// Get today's date
		JLoader::import('joomla.utilities.date');
		$jNow = new Date();
		$now  = $jNow->toUnix();

		// Start the clock!
		$clockStart = microtime(true);

		// Get and loop all subscription levels
		/** @var Levels $levelsModel */
		$levelsModel = Container::getInstance('com_akeebasubs')->factory->model('Levels')->tmpInstance();
		$levels = $levelsModel
			->enabled(1)
			->get(true);

		// Update the last run info before doing the actual work
		$this->setLastRunTimestamp();

		/** @var Levels $level */
		foreach ($levels as $level)
		{
			\JLog::add("Processing level " . $level->title, \JLog::DEBUG, "akeebasubs.cron.fixrenewalsflag");
		}
	}

	/**
	 * Fetches the com_akeebasubs component's parameters as a JRegistry instance
	 *
	 * @return JRegistry The component parameters
	 */
	private function getComponentParameters()
	{
		JLoader::import('joomla.registry.registry');
		$component = JComponentHelper::getComponent('com_akeebasubs');

		if ($component->params instanceof JRegistry)
		{
			$cparams = $component->params;
		}
		elseif (!empty($component->params))
		{
			$cparams = new JRegistry($component->params);
		}
		else
		{
			$cparams = new JRegistry('{}');
		}

		return $cparams;
	}

	/**
	 * "Do I have to run?" - the age old question. Let it be answered by checking the
	 * last execution timestamp, stored in the component's configuration.
	 */
	private function doIHaveToRun()
	{
		// Get the component parameters
		$componentParameters      = $this->getComponentParameters();

		// Is scheduling enabled?
		// WARNING: DO NOT USE $componentParameters HERE! THIS IS A PLUGIN PARAMETER NOT A COMPONENT PARAMETER
		$scheduling  = $this->params->get('scheduling', 1);

		if (!$scheduling)
		{
			return false;
		}

		// Find the next execution time (midnight GMT of the next day after the last time we ran the scheduling)
		$lastRunUnix = $componentParameters->get('plg_akeebasubs_asfixrenewalsflag_timestamp', 0);
		$dateInfo    = getdate($lastRunUnix);
		$nextRunUnix = mktime(0, 0, 0, $dateInfo['mon'], $dateInfo['mday'], $dateInfo['year']);
		$nextRunUnix += 24 * 3600;

		// Get the current time
		$now = time();

		// have we reached the next execution time?
		return ($now >= $nextRunUnix);
	}

	/**
	 * Saves the timestamp of this plugin's last run
	 */
	private function setLastRunTimestamp($timestamp = null)
	{
		$lastRun = is_null($timestamp) ? time() : $timestamp;
		$params  = $this->getComponentParameters();
		$params->set('plg_akeebasubs_asfixrenewalsflag_timestamp', $lastRun);

		$db = Container::getInstance('com_akeebasubs')->db;

		$data  = $params->toString('JSON');
		$query = $db->getQuery(true)
		            ->update($db->qn('#__extensions'))
		            ->set($db->qn('params') . ' = ' . $db->q($data))
		            ->where($db->qn('element') . ' = ' . $db->q('com_akeebasubs'))
		            ->where($db->qn('type') . ' = ' . $db->q('component'));
		$db->setQuery($query);
		$db->execute();
	}
}