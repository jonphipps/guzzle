<?php

namespace Guzzle\Tests\Mock;

use Guzzle\Common\Event\SubjectInterface;
use Guzzle\Common\Event\EventManager;
use Guzzle\Common\Event\ObserverInterface;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class MockObserver implements ObserverInterface
{
    public $notified = 0;
    public $subject;
    public $context;
    public $event;
    public $log = array();
    public $logByEvent = array();
    public $events = array();

   /**
     * {@inheritdoc}
     */
    public function update(SubjectInterface $subject, $event, $context = null)
    {
        $this->notified++;
        $this->subject = $subject;
        $this->context = $context;
        $this->event = $event;
        $this->events[] = $event;
        $this->log[] = array($event, $context);
        $this->logByEvent[$event] = $context;
        
        return true;
    }
}