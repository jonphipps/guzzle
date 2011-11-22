<?php

namespace Guzzle\Http\Curl;

use Guzzle\Common\ExceptionCollection;
use Guzzle\Common\Event\AbstractSubject;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\RequestException;

/**
 * Execute a pool of {@see RequestInterface} objects in parallel using
 * curl_multi.
 *
 * Signals emitted:
 *
 *  event           context             description
 *  -----           -------             -----------
 *  add_request     RequestInterface    A request was added to the pool
 *  remove_request  RequestInterface    A request was removed from the pool
 *  reset           null                The pool was reset
 *  before_send     array               The pool is about to be sent
 *  complete        array               The pool finished sending the requests
 *  polling_request RequestInterface    A request is still polling
 *  polling         null                Some requests are still polling
 *  exception       RequestException    A request exception occurred
 *
 * @author  michael@guzzlephp.org
 */
class CurlMulti extends AbstractSubject implements CurlMultiInterface
{
    /**
     * @var resource cURL multi handle.
     */
    protected $multiHandle;

    /**
     * @var array Attached {@see RequestInterface} objects.
     */
    protected $requests = array();

    /**
     * @var string The current state of the pool
     */
    protected $state = self::STATE_IDLE;

    /**
     * @var array Curl handles owned by the mutli handle
     */
    protected $handles = array();

    /**
     * @var CurlMulti
     */
    private static $instance;

    /**
     * Get a cached instance of the curl mutli object
     *
     * @return CurlMulti
     */
    public static function getInstance()
    {
        // @codeCoverageIgnoreStart
        if (!self::$instance) {
            self::$instance = new self();
        }
        // @codeCoverageIgnoreEnd

        return self::$instance;
    }

    /**
     * Construct a request pool
     */
    public function __construct()
    {
        $this->multiHandle = curl_multi_init();
    }

    /**
     * Closes the curl multi handle
     */
    public function __destruct()
    {
        @curl_multi_close($this->multiHandle);
    }

    /**
     * Add a request to the pool.
     *
     * @param RequestInterface $request Returns the Request that was added
     */
    public function add(RequestInterface $request)
    {
        $this->requests[] = $request;
        
        if ($this->state == self::STATE_SENDING && !$request->getParams()->get('queued_response')) {
            // Attach a request while the pool is being sent.  This is currently
            // used to implement exponential backoff
            curl_multi_add_handle($this->multiHandle, $this->createCurlHandle($request)->getHandle());
        }

        $this->getEventManager()->notify(self::ADD_REQUEST, $request);
        
        return $request;
    }

    /**
     * Get an array of attached {@see RequestInterface}s.
     *
     * @return array Returns an array of attached requests.
     */
    public function all()
    {
        return $this->requests;
    }

    /**
     * Get the current state of the Pool
     *
     * @return string
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * Remove a request from the pool.
     *
     * @param RequestInterface $request Request to detach.
     *
     * @return RequestInterface Returns the Request object that was removed
     */
    public function remove(RequestInterface $request)
    {
        if ($this->state == self::STATE_SENDING && $this->multiHandle) {
            $handle = $this->getRequestHandle($request) ?: $request->getParams('curl_handle');
            if ($handle instanceof CurlHandle && $handle->getHandle()) {
                curl_multi_remove_handle($this->multiHandle, $handle->getHandle());
                $handle->close();
                $this->removeRequestHandle($request);
            }
        }
        $this->requests = array_values(array_filter($this->requests, function($req) use ($request) {
            return $req !== $request;
        }));
        $this->getEventManager()->notify(self::REMOVE_REQUEST, $request);
        
        return $request;
    }

    /**
     * Reset the state of the Pool and remove any attached RequestInterface objects
     */
    public function reset()
    {
        // Remove each request
        foreach ($this->requests as $request) {
            $this->remove($request);
        }
        $this->state = self::STATE_IDLE;
        // Notify any observers of the reset event
        $this->getEventManager()->notify(self::RESET);
    }

    /**
     * Execute the curl multi requests.  If you attempt to send() while the
     * requests are already being sent, FALSE will be returned.
     *
     * @return bool Returns TRUE on success or FALSE on failure
     * @throws ExceptionCollection if any requests threw exceptions during the
     *      transfer.
     */
    public function send()
    {
        if ($this->state == self::STATE_SENDING || empty($this->requests)) {
            return false;
        }

        $exceptions = array();
        $this->getEventManager()->notify(self::BEFORE_SEND, $this->requests);
        $this->state = self::STATE_SENDING;
        $sending = $this->requests;

        foreach ($this->requests as $request) {
            $request->setState(RequestInterface::STATE_TRANSFER);
            $request->getEventManager()->notify('request.before_send');
            // Requests might decide they don't need to be sent just before xfer
            if ($request->getState() != RequestInterface::STATE_TRANSFER) {
                $this->remove($request);
            } else if ($request->getParams()->get('queued_response')) {
                $this->removeQueuedRequest($request, $exceptions);
            } else {
                curl_multi_add_handle($this->multiHandle, $this->createCurlHandle($request)->getHandle());
            }
        }

        try {
            $this->perform($exceptions);
        } catch (\Exception $e) {
            $exceptions[] = $e;
        }

        $this->state = self::STATE_COMPLETE;
        $this->getEventManager()->notify(self::COMPLETE, $sending);
        $this->reset();

        // Throw any Request exceptions encountered during the transfer
        if (count($exceptions)) {
            $collection = new ExceptionCollection('Errors during multi transfer');
            foreach ($exceptions as $e) {
                $collection->add($e);
            }
            throw $collection;
        }

        return true;
    }

    /**
     * Get the number of requests in the pool
     *
     * @return int
     */
    public function count()
    {
        return count($this->requests);
    }

    /**
     * Create a curl handle for a request
     *
     * @param RequestInterface $request Request
     *
     * @return CurlHandle
     */
    protected function createCurlHandle(RequestInterface $request)
    {
        $wrapper = CurlHandle::factory($request);
        $this->handles[spl_object_hash($request)] = $wrapper;
        $request->getParams()->set('curl_handle', $wrapper);
        
        return $wrapper;
    }

    /**
     * Check for errors and fix headers of a request based on a curl response
     *
     * @param RequestInterface $request Request to process
     * @param CurlHandle $handle Curl handle object
     * @param array $curl Curl message returned from curl_multi_info_read
     *
     * @throws CurlException on Curl error
     */
    protected function processResponse(RequestInterface $request, CurlHandle $handle, array $curl)
    {
        // Check for errors on the handle
        if (CURLE_OK != $curl['result']) {
            $handle->setErrorNo($curl['result']);
            $e = new CurlException('[curl] ' . $handle->getErrorNo() . ': '
                . $handle->getError() . ' [url] ' . $handle->getUrl()
                . ' [info] ' . var_export($handle->getInfo(), true)
                . ' [debug] ' . $handle->getStderr());
            $e->setRequest($request)
              ->setError($handle->getError(), $handle->getErrorNo());
            $handle->close();
            throw $e;
        }

        // cURL can modify the request from it's initial HTTP message.  The
        // following code parses the sent HTTP request headers from cURL and
        // updates the request object to most accurately reflect the HTTP
        // message sent over the wire.

        // Set the transfer stats on the response
        $log = $handle->getStderr();
        $request->getResponse()->setInfo(array_merge(array(
            'stderr' => $log
        ), $handle->getInfo()));

        // Parse the cURL stderr output for outgoing requests
        $headers = '';
        fseek($handle->getStderr(true), 0);
        while (($line = fgets($handle->getStderr(true))) !== false) {
            if ($line && $line[0] == '>') {
                $headers = substr(trim($line), 2) . "\r\n";
                while (($line = fgets($handle->getStderr(true))) !== false) {
                    if ($line[0] == '*' || $line[0] == '<') {
                        break;
                    } else {
                        $headers .= trim($line) . "\r\n";
                    }
                }
            }
        }

        if ($headers) {
            $parsed = RequestFactory::parseMessage($headers);
            if (!empty($parsed['headers'])) {
                $request->setHeaders(array());
                foreach ($parsed['headers'] as $name => $value) {
                    $request->setHeader($name, $value);
                }
            }
            if (!empty($parsed['protocol_version'])) {
                $request->setProtocolVersion($parsed['protocol_version']);
            }
        }

        $request->setState(RequestInterface::STATE_COMPLETE);
    }

    /**
     * Get the data from the multi handle
     */
    protected function perform(array &$exceptions)
    {
        $active = false;
        $selectResult = 1;
        $pendingRequests = !empty($this->requests);
        
        while ($pendingRequests) {
            
            if ($selectResult) {

                while ($mrc = curl_multi_exec($this->multiHandle, $active) == CURLM_CALL_MULTI_PERFORM);

                // @codeCoverageIgnoreStart
                if ($mrc != CURLM_OK) {
                    throw new CurlException('curl_multi_exec returned ' . $mrc);
                }
                // @codeCoverageIgnoreEnd

                // Get messages from curl handles
                while ($done = curl_multi_info_read($this->multiHandle)) {
                    foreach ($this->requests as $request) {
                        $handle = $this->getRequestHandle($request);
                        if ($handle->getHandle() === $done['handle']) {
                            try {
                                $this->processResponse($request, $handle, $done);
                            } catch (\Exception $e) {
                                $request->setState(RequestInterface::STATE_ERROR);
                                $this->getEventManager()->notify('exception', $e);
                                $exceptions[] = $e;
                            }
                            // Account for requests that need to be retried
                            if ($request->getState() != RequestInterface::STATE_TRANSFER) {
                                $this->remove($request);
                            }
                            break;
                        }
                    }
                }

                $pendingRequests = false;
                foreach ($this->requests as $request) {
                    $request->getEventManager()->notify(self::POLLING_REQUEST, $this);
                    // Account for requests with queued responses added in xfer
                    if ($request->getParams()->get('queued_response')) {
                        $this->removeQueuedRequest($request, $exceptions);
                    }
                    $pendingRequests = true;
                }

                $this->getEventManager()->notify(self::POLLING);
            }

            if ($active) {
                while (($selectResult = curl_multi_select($this->multiHandle)) == 0);
            } else if ($pendingRequests) {
                // Requests are not actually pending a cURL select call, so
                // we need to delay in order to prevent eating too much CPU
                usleep(30000);
            }
        }
    }

    /**
     * Remove a request that has a queued response
     *
     * @param RequestInterface $request Request to remove
     * @param array $exceptions Exceptions array
     */
    protected function removeQueuedRequest(RequestInterface $request, &$exceptions)
    {
        try {
            $this->remove($request);
            $request->setState(RequestInterface::STATE_COMPLETE);
        } catch (\Exception $e) {
            $exceptions[] = $e;
        }
    }

    /**
     * Get the curl handle associated with a request
     *
     * @param RequestInterface $request Request
     *
     * @return CurlHandle|null
     */
    private function getRequestHandle(RequestInterface $request)
    {
        $hash = spl_object_hash($request);
        
        return isset($this->handles[$hash]) ? $this->handles[$hash] : null;
    }

    /**
     * Removes a request handle from the cache
     *
     * @param RequestInterface $request
     */
    private function removeRequestHandle(RequestInterface $request)
    {
        $hash = spl_object_hash($request);
        if (isset($this->handles[$hash])) {
            unset($this->handles[$hash]);
        }
    }
}