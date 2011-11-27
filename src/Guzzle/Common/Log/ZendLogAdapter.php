<?php

namespace Guzzle\Common\Log;

use Zend\Log\Logger;

/**
 * Adapts a ZF2 Logger object
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ZendLogAdapter extends AbstractLogAdapter
{
    /**
     * Adapt a ZF2 logger
     * 
     * @param Logger $logObject Log object to adapt
     * @throws InvalidArgumentException
     */
    public function __construct($logObject) 
    {
        if (!($logObject instanceof Logger)) {
            throw new \InvalidArgumentException(
                'Object must be an instance of Zend\\Log\\Logger'
            );
        }

        $this->log = $logObject;
    }

    /**
     * {@inheritdoc}
     */
    public function log($message, $priority = LOG_INFO, $extras = null)
    {
        $this->log->log($message, $priority, $extras);

        return $this;
    }
}