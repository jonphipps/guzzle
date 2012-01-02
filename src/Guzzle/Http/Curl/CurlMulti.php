<?php

namespace Guzzle\Http\Curl;

use Guzzle\Common\AbstractHasDispatcher;
use Guzzle\Common\ExceptionCollection;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\RequestException;

/**
 * Execute a pool of {@see RequestInterface} objects in parallel using
 * curl_multi
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CurlMulti extends AbstractHasDispatcher implements CurlMultiInterface
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
     * @var array Queued exceptions
     */
    protected $exceptions = array();

    /**
     * @var CurlMulti
     */
    private static $instance;

    /**
     * @var int
     */
    private $scope = -1;

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
     * {@inheritdoc}
     */
    public static function getAllEvents()
    {
        return array(
            // A request was added
            self::ADD_REQUEST,
            // A request was removed
            self::REMOVE_REQUEST,
            // Requests are about to be sent
            self::BEFORE_SEND,
            // The pool finished sending the requests
            self::COMPLETE,
            // A request is still polling (sent to request's event dispatchers)
            self::POLLING_REQUEST,
            // A request exception occurred
            'curl_multi.exception',
            // A curl message was received
            'curl_multi.message'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function __construct()
    {
        $this->multiHandle = curl_multi_init();
    }

    /**
     * {@inheritdoc}
     */
    public function __destruct()
    {
        @curl_multi_close($this->multiHandle);
    }

    /**
     * {@inheritdoc}
     */
    public function add(RequestInterface $request, $async = false)
    {
        $scope = $async ? $this->scope : $this->scope + 1;
        if (!isset($this->requests[$scope])) {
            $this->requests[$scope] = array();
        }
        $this->requests[$scope][] = $request;
        $this->dispatch(self::ADD_REQUEST, array(
            'request' => $request
        ));

        if ($this->state == self::STATE_SENDING) {
            $this->beforeSend($request);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function all()
    {
        $requests = array();
        foreach ($this->requests as $scopedRequests) {
            $requests = array_merge($requests, $scopedRequests);
        }

        return $requests;
    }

    /**
     * {@inheritdoc}
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * {@inheritdoc}
     */
    public function remove(RequestInterface $request)
    {
        if ($this->state == self::STATE_SENDING && $this->multiHandle) {
            $handle = $this->getRequestHandle($request) ?: $request->getParams('curl_handle');
            if ($handle instanceof CurlHandle && $handle->getHandle()) {
                curl_multi_remove_handle($this->multiHandle, $handle->getHandle());
                $handle->close();
                unset($this->handles[spl_object_hash($request)]);
            }
        }

        foreach ($this->requests as $scope => $scopedRequests) {
            foreach ($scopedRequests as $i => $scopedRequest) {
                if ($scopedRequest === $request) {
                    unset($this->requests[$scope][$i]);
                }
            }
        }

        $this->dispatch(self::REMOVE_REQUEST, array(
            'request' => $request
        ));

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function reset()
    {
        // Remove each request
        foreach ($this->all() as $request) {
            $this->remove($request);
        }
        $this->requests = $this->exceptions = array();
        $this->state = self::STATE_IDLE;
        $this->scope = -1;
    }

    /**
     * {@inheritdoc}
     */
    public function send()
    {
        $this->scope++;

        // Don't prepare for sending again if send() is called while sending
        if ($this->state != self::STATE_SENDING) {
            try {
                $requests = $this->all();
                $this->dispatch(self::BEFORE_SEND, array(
                    'requests' => $requests
                ));
                $this->state = self::STATE_SENDING;
                foreach ($requests as $request) {
                    if ($request->getState() != RequestInterface::STATE_TRANSFER) {
                        $this->beforeSend($request);
                    }
                }
            } catch (\Exception $e) {
                $this->exceptions[] = $e;
            }
        }

        try {
            $this->perform();
        } catch (\Exception $e) {
            $this->exceptions[] = $e;
        }

        $this->scope--;

        // Don't re-complete if another scope already completed the transfers
        if ($this->state !== self::STATE_COMPLETE) {
            $this->state = self::STATE_COMPLETE;
            $this->dispatch(self::COMPLETE);
            $this->state = self::STATE_IDLE;
        }

        if (!empty($this->exceptions)) {
            $collection = new ExceptionCollection('Errors during multi transfer');
            while ($e = array_shift($this->exceptions)) {
                $collection->add($e);
            }
            $this->reset();
            throw $collection;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function count()
    {
        return count($this->all());
    }

    /**
     * Prepare for sending
     *
     * @param RequestInterface $request Request to prepare
     */
    protected function beforeSend(RequestInterface $request)
    {
        try {
            $request->setState(RequestInterface::STATE_TRANSFER);
            $request->dispatch('request.before_send', array(
                'request' => $request
            ));
            // Requests might decide they don't need to be sent just before xfer
            if ($request->getState() != RequestInterface::STATE_TRANSFER) {
                $this->remove($request);
            } else if ($request->getParams()->get('queued_response')) {
                $this->remove($request);
                $request->setState(RequestInterface::STATE_COMPLETE);
            } else {
                curl_multi_add_handle($this->multiHandle, $this->createCurlHandle($request)->getHandle());
            }
        } catch (\Exception $e) {
            $this->exceptions[] = $e;
        }
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
     * Get the data from the multi handle
     */
    protected function perform()
    {
        $active = $failedSelects = 0;
        $pendingRequests = !$this->scope ? $this->count() : !empty($this->requests[$this->scope]);

        while ($pendingRequests) {

            while ($mrc = curl_multi_exec($this->multiHandle, $active) == CURLM_CALL_MULTI_PERFORM);

            // @codeCoverageIgnoreStart
            if ($mrc != CURLM_OK) {
                throw new CurlException('curl_multi_exec returned ' . $mrc);
            }
            // @codeCoverageIgnoreEnd

            // Get messages from curl handles
            while ($done = curl_multi_info_read($this->multiHandle)) {
                $this->dispatch('curl_multi.message', $done);
                foreach ($this->all() as $request) {
                    $handle = $this->getRequestHandle($request);
                    if ($handle->getHandle() === $done['handle']) {
                        try {
                            $this->processResponse($request, $handle, $done);
                        } catch (\Exception $e) {
                            $this->remove($request);
                            $request->setState(RequestInterface::STATE_ERROR);
                            $this->dispatch(self::MULTI_EXCEPTION, array(
                                'exception' => $e,
                                'all_exceptions' => $this->exceptions
                            ));
                            $this->exceptions[] = $e;
                        }
                        break;
                    }
                }
            }

            // Notify each request as polling and handled queued responses
            $scopedPolling = $this->scope <= 0 ? $this->all() : $this->requests[$this->scope];
            $pendingRequests = !empty($scopedPolling);
            foreach ($scopedPolling as $request) {
                $request->dispatch(self::POLLING_REQUEST, array(
                    'curl_multi' => $this,
                    'request'    => $request
                ));
            }

            if ($pendingRequests) {
                if (!$active) {
                    // Requests are not actually pending a cURL select call, so
                    // we need to delay in order to prevent eating too much CPU
                    usleep(30000);
                } else {
                    $select = curl_multi_select($this->multiHandle, 0.3);
                    // Select up to 25 times for a total of 7.5 seconds
                    if (!$select && $this->scope > 0 && ++$failedSelects > 25) {
                        // There are cases where curl is waiting on a return
                        // value from a parent scope in order to remove a curl
                        // handle.  This check will defer to a parent scope for
                        // handling the rest of the connection transfer.
                        // @codeCoverageIgnoreStart
                        break;
                        // @codeCoverageIgnoreEnd
                    }
                }
            }
        }
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

        // Set the transfer stats on the response
        $log = $handle->getStderr();
        if (null !== $log) {
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

            // Add request headers to the request exactly as they were sent
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
        }

        $request->setState(RequestInterface::STATE_COMPLETE);

        if ($request->getState() != RequestInterface::STATE_TRANSFER) {
            $this->remove($request);
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
}