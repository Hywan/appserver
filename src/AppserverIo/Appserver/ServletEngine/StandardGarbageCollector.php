<?php

/**
 * \AppserverIo\Appserver\ServletEngine\StandardGarbageCollector
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * PHP version 5
 *
 * @author    Tim Wagner <tw@appserver.io>
 * @copyright 2015 TechDivision GmbH <info@appserver.io>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/appserver-io/appserver
 * @link      http://www.appserver.io
 */

namespace AppserverIo\Appserver\ServletEngine;

use AppserverIo\Logger\LoggerUtils;
use AppserverIo\Appserver\Core\AbstractDaemonThread;
use AppserverIo\Psr\Servlet\ServletSessionInterface;

/**
 * A thread which pre-initializes session instances and adds them to the
 * the session pool.
 *
 * @author    Tim Wagner <tw@appserver.io>
 * @copyright 2015 TechDivision GmbH <info@appserver.io>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/appserver-io/appserver
 * @link      http://www.appserver.io
 *
 * @property \Psr\Log\loggerInterface[]                                    $loggers         The logger instances
 * @property \AppserverIo\Appserver\ServletEngine\SessionFactory           $sessionFactory  The session factory
 * @property \AppserverIo\Storage\StorageInterface                         $sessions        The sessions
 * @property \AppserverIo\Appserver\ServletEngine\SessionSettingsInterface $sessionSettings Settings for the session handling
 */
class StandardGarbageCollector extends AbstractDaemonThread implements GarbageCollectorInterface
{

    /**
     * Injects the available logger instances.
     *
     * @param array $loggers The logger instances
     *
     * @return void
     */
    public function injectLoggers(array $loggers)
    {
        $this->loggers = $loggers;
    }

    /**
     * Injects the sessions.
     *
     * @param \AppserverIo\Storage\StorageInterface $sessions The sessions
     *
     * @return void
     */
    public function injectSessions($sessions)
    {
        $this->sessions = $sessions;
    }

    /**
     * Injects the session factory.
     *
     * @param \AppserverIo\Appserver\ServletEngine\SessionFactory $sessionFactory The session factory
     *
     * @return void
     */
    public function injectSessionFactory($sessionFactory)
    {
        $this->sessionFactory = $sessionFactory;
    }

    /**
     * Injects the session settings.
     *
     * @param \AppserverIo\Appserver\ServletEngine\SessionSettingsInterface $sessionSettings Settings for the session handling
     *
     * @return void
     */
    public function injectSessionSettings($sessionSettings)
    {
        $this->sessionSettings = $sessionSettings;
    }

    /**
     * Returns all sessions actually attached to the session manager.
     *
     * @return \AppserverIo\Storage\StorageInterface The container with sessions
     */
    public function getSessions()
    {
        return $this->sessions;
    }

    /**
     * Returns the session factory instance.
     *
     * @return \AppserverIo\Appserver\ServletEngine\SessionFactory The session factory instance
     */
    public function getSessionFactory()
    {
        return $this->sessionFactory;
    }

    /**
     * Returns the session settings.
     *
     * @return \AppserverIo\Appserver\ServletEngine\SessionSettingsInterface The session settings
     */
    public function getSessionSettings()
    {
        return $this->sessionSettings;
    }

    /**
     * Initializes and starts the garbage collector.
     *
     * @return void
     */
    public function initialize()
    {
    }

    /**
     * This method will be invoked before the while() loop starts and can be used
     * to implement some bootstrap functionality.
     *
     * @return void
     */
    public function bootstrap()
    {

        // setup autoloader
        require SERVER_AUTOLOADER;

        // try to load the profile logger
        if (isset($this->loggers[LoggerUtils::PROFILE])) {
            $this->profileLogger = $this->loggers[LoggerUtils::PROFILE];
            $this->profileLogger->appendThreadContext('servlet-engine-garbage-collector');
        }
    }

    /**
     * This is invoked on every iteration of the daemons while() loop.
     *
     * @param integer $timeout The timeout before the daemon wakes up
     *
     * @return void
     */
    public function iterate($timeout)
    {

        // call parent method and sleep for the default timeout
        parent::iterate($timeout);

        // collect the session garbage
        $this->collectGarbage();

        // profile the size of the sessions
        if ($this->profileLogger) {
            $this->profileLogger->debug(
                sprintf('Collect garbage for session pool with size: %d', sizeof($this->getSessions()))
            );
        }
    }

    /**
     * Returns the default path to persist sessions.
     *
     * @param string $toAppend A relative path to append to the session save path
     *
     * @return string The default path to persist session
     */
    private function getSessionSavePath($toAppend = null)
    {
        // load the default path
        $sessionSavePath = $this->getSessionSettings()->getSessionSavePath();

        // check if we've something to append
        if ($toAppend != null) {
            $sessionSavePath = $sessionSavePath . DIRECTORY_SEPARATOR . $toAppend;
        }

        // return the session save path
        return $sessionSavePath;
    }

    /**
     * Collects the session garbage.
     *
     * @return integer The number of expired and removed sessions
     */
    protected function collectGarbage()
    {

        // counter to store the number of removed sessions
        $sessionRemovalCount = 0;

        // the probability that we want to collect the garbage (float <= 1.0)
        $garbageCollectionProbability = $this->getSessionSettings()->getGarbageCollectionProbability();

        // calculate if the want to collect the garbage now
        $decimals = strlen(strrchr($garbageCollectionProbability, '.')) - 1;
        $factor = ($decimals > - 1) ? $decimals * 10 : 1;

        // if we can to collect the garbage, start collecting now
        if (rand(0, 100 * $factor) <= ($garbageCollectionProbability * $factor)) {
            // we want to know what inactivity timeout we've to check the sessions for
            $inactivityTimeout = $this->getSessionSettings()->getInactivityTimeout();
            // iterate over all session and collect the session garbage
            if ($inactivityTimeout !== 0) {
                // iterate over all sessions and remove the expired ones
                foreach ($this->getSessions() as $session) {
                    // check if we've a session instance
                    if ($session instanceof ServletSessionInterface) {
                        // load the sessions last activity timestamp
                        $lastActivitySecondsAgo = time() - $session->getLastActivityTimestamp();

                        // if session has been expired, destroy and remove it
                        if ($lastActivitySecondsAgo > $inactivityTimeout) {
                            // load the session-ID
                            $sessionId = $session->getId();

                            // first remove the session from the session factory
                            $this->getSessionFactory()->removeBySessionId($sessionId);

                            // then remove the session from the session manager
                            $this->getSessions()->remove($sessionId);

                            // destroy the session if not already done
                            if ($sessionId != null) {
                                $session->destroy(
                                    sprintf(
                                        'Session %s was inactive for %s seconds, more than the configured timeout of %s seconds.',
                                        $sessionId,
                                        $lastActivitySecondsAgo,
                                        $inactivityTimeout
                                    )
                                );
                            }

                            // prepare the session filename
                            $sessionFilename = $this->getSessionSavePath($this->getSessionSettings()->getSessionFilePrefix() . $sessionId);

                            // delete the file containing the session data if available
                            if (file_exists($sessionFilename)) {
                                unlink($sessionFilename);
                            }

                            // raise the counter of expired session
                            $sessionRemovalCount++;
                        }
                    }
                }
            }
        }
    }
}
