<?php
/*
 * This file is part of the Goteo Package.
 *
 * (c) Platoniq y Fundación Goteo <fundacion@goteo.org>
 *
 * For the full copyright and license information, please view the README.md
 * and LICENSE files that was distributed with this source code.
 */

namespace Goteo\Console;

use Goteo\Application\App;
use Goteo\Application\Config;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Console {
    static protected $app;
	static protected $console;
	static protected array $commands = [];
	protected $dispatcher;

	public function __construct(EventDispatcherInterface $dispatcher) {
		$this->dispatcher = $dispatcher;
	}

	/**
	 * Creates a new instance of the App ready to run
	 * Next calls to this method will return the current instantiated App
	 * @return App object
	 */
	static public function get() {
		if (!self::$console) {

			// Old constants compatibility generated by the dispatcher
			$url = Config::get('url.main');
			define('SITE_URL', (Config::get('ssl')?'https://':'http://').preg_replace('|^(https?:)?//|i', '', $url));
			define('SEC_URL', SITE_URL);

			self::$app = App::getService('console');
            self::$console = new Application();
            self::$console->setDispatcher(self::$app->getDispatcher());

			foreach (self::$commands as $command) {
				self::$console->add($command);
			}

		}

		return self::$console;
	}

	public function getDispatcher() {
		if (!$this->dispatcher) {
			$this->setDispatcher(new EventDispatcher());
		}

		return $this->dispatcher;
	}

	public function setDispatcher(EventDispatcher $dispatcher) {
		$this->dispatcher = $dispatcher;
		self::get()->setDispatcher($dispatcher);
	}

	static public function add(Command $command) {
		self::$commands[] = $command;
		if (self::$console) {
			self::$console->add($command);
		}
	}

	/**
	 * Dispatches an event
	 * Events can be handled by any subscriber
	 * @return Event                 the result object
	 */
	static public function dispatch(string $eventName, Event $event = null):Event
    {
		return self::$app->getDispatcher()->dispatch($eventName, $event);
	}

	/**
	 * Enables debug mode witch does:
	 *     - *.yml settings always read
	 *     - A bottom html profiler tool will be displayed on the bottom of the page
	 *     - SQL queries will be collected fo statistics
	 *     - Html/php error will be shown
	 * @param  boolean $enable If must or no be enabled (do it before call App::get())
	 *                         A null value does nothing
	 * @return boolean         Returns the current debug mode
	 */
	static public function debug($enable = null) {
		return App::debug($enable);
	}

}
