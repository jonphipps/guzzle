<?php

namespace Guzzle\Tests\Http\Curl;

use Guzzle\Common\Collection;
use Guzzle\Common\Event\ObserverInterface;
use Guzzle\Common\Event\SubjectInterface;
use Guzzle\Http\Client;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Curl\CurlHandle;
use Guzzle\Http\Curl\CurlMulti;
use Guzzle\Common\ExceptionCollection;;
use Guzzle\Http\Curl\CurlException;
use Guzzle\Tests\Mock\MockMulti;

/**
 * @group server
 * @author Michael Dowling <michael@guzzlephp.org>
 * @covers Guzzle\Http\Curl\CurlMulti
 */
class ExceptionCollectionTest extends \Guzzle\Tests\GuzzleTestCase implements ObserverInterface
{
    /**
     * @var Guzzle\Http\Curl\CurlMulti
     */
    private $multi;
    
    /**
     * @var Guzzle\Common\Collection
     */
    private $updates;

    /**
     * Prepares the environment before running a test.
     */
    protected function setUp()
    {
        parent::setUp();
        $this->updates = new Collection();
        $this->multi = new MockMulti();
        $this->multi->getEventManager()->attach($this);
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::getInstance
     */
    public function testReturnsCachedInstance()
    {
        $c = CurlMulti::getInstance();
        $this->assertInstanceOf('Guzzle\\Http\\Curl\\CurlMultiInterface', $c);
        $this->assertSame($c, CurlMulti::getInstance());
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::__construct
     * @covers Guzzle\Http\Curl\CurlMulti::__destruct
     */
    public function testConstructorCreateMultiHandle()
    {
        $this->assertInternalType('resource', $this->multi->getHandle());
        $this->assertEquals('curl_multi', get_resource_type($this->multi->getHandle()));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::__destruct
     */
    public function testDestructorClosesMultiHandle()
    {
        $handle = $this->multi->getHandle();
        $this->multi->__destruct();
        $this->assertFalse(is_resource($handle));
    }

    /**
     * @covers Guzzle\Http\Curl\curlMulti::add
     * @covers Guzzle\Http\Curl\curlMulti::all
     * @covers Guzzle\Http\Curl\curlMulti::count
     */
    public function testRequestsCanBeAddedAndCounted()
    {
        $multi = new CurlMulti();
        $multi->getEventManager()->attach($this);
        $request1 = new Request('GET', 'http://www.google.com/');
        $multi->add($request1);
        $this->assertEquals(array($request1), $multi->all());

        $request2 = new Request('POST', 'http://www.google.com/');
        $multi->add($request2);
        $this->assertEquals(array($request1, $request2), $multi->all());
        $this->assertEquals(2, count($multi));

        $this->assertTrue($this->updates->hasKey(CurlMulti::ADD_REQUEST) !== false);
        $this->assertFalse($this->updates->hasKey(CurlMulti::REMOVE_REQUEST) !== false);
        $this->assertFalse($this->updates->hasKey(CurlMulti::POLLING) !== false);
        $this->assertFalse($this->updates->hasKey(CurlMulti::COMPLETE) !== false);
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::remove
     * @covers Guzzle\Http\Curl\CurlMulti::all
     */
    public function testRequestsCanBeRemoved()
    {
        $request1 = new Request('GET', 'http://www.google.com/');
        $this->multi->add($request1);
        $request2 = new Request('PUT', 'http://www.google.com/');
        $this->multi->add($request2);
        $this->assertEquals(array($request1, $request2), $this->multi->all());
        $this->assertSame($this->multi, $this->multi->remove($request1));
        $this->assertEquals(array($request2), $this->multi->all());
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::reset
     */
    public function testsResetRemovesRequestsAndResetsState()
    {
        $request1 = new Request('GET', 'http://www.google.com/');
        $this->multi->add($request1);
        $this->multi->reset();
        $this->assertEquals(array(), $this->multi->all());
        $this->assertEquals('idle', $this->multi->getState());
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::send
     * @covers Guzzle\Http\Curl\CurlMulti::getState
     */
    public function testSendsRequestsInParallel()
    {
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\nBody");
        $this->assertEquals('idle', $this->multi->getState());
        $request = new Request('GET', $this->getServer()->getUrl());
        $this->multi->add($request);
        $this->multi->send();

        $this->assertEquals('idle', $this->multi->getState());

        $this->assertTrue($this->updates->hasKey(CurlMulti::ADD_REQUEST) !== false);
        $this->assertTrue($this->updates->hasKey(CurlMulti::POLLING) !== false);
        $this->assertTrue($this->updates->hasKey(CurlMulti::COMPLETE) !== false);

        $this->assertEquals(array('add_request', $request), $this->updates->get(CurlMulti::ADD_REQUEST));
        $this->assertEquals(array('complete', null), $this->updates->get(CurlMulti::COMPLETE));
        
        $this->assertEquals('Body', $request->getResponse()->getBody()->__toString());

        // Sending it again will not do anything because there are no requests
        $this->multi->send();
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::send
     */
    public function testSendsRequestsThroughCurl()
    {
        $this->getServer()->enqueue(array(
            "HTTP/1.1 204 No content\r\n" .
            "Content-Length: 0\r\n" .
            "Server: Jetty(6.1.3)\r\n\r\n",

            "HTTP/1.1 200 OK\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "Content-Length: 4\r\n" .
            "Server: Jetty(6.1.3)\r\n\r\n" .
            "\r\n" .
            "data"
        ));

        $request1 = new Request('GET', $this->getServer()->getUrl());
        $request1->getEventManager()->attach($this);
        $request2 = new Request('GET', $this->getServer()->getUrl());
        $request2->getEventManager()->attach($this);
        
        $this->multi->add($request1);
        $this->multi->add($request2);
        $this->multi->send();

        $response1 = $request1->getResponse();
        $response2 = $request2->getResponse();

        $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $response1);
        $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $response2);

        $this->assertTrue($response1->getBody(true) == 'data' || $response2->getBody(true) == 'data');
        $this->assertTrue($response1->getBody(true) == '' || $response2->getBody(true) == '');
        $this->assertTrue($response1->getStatusCode() == '204' || $response2->getStatusCode() == '204');
        $this->assertNotEquals((string) $response1, (string) $response2);

        $this->assertTrue($this->updates->hasKey('request.before_send') !== false);
        $this->assertInternalType('array', $this->updates->get('request.before_send'));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::send
     */
    public function testSendsThroughCurlAndAggregatesRequestExceptions()
    {
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "Content-Length: 4\r\n" .
            "Server: Jetty(6.1.3)\r\n" .
            "\r\n" .
            "data",

            "HTTP/1.1 204 No content\r\n" .
            "Content-Length: 0\r\n" .
            "Server: Jetty(6.1.3)\r\n" .
            "\r\n",

            "HTTP/1.1 404 Not Found\r\n" .
            "Content-Length: 0\r\n" .
            "\r\n"
        ));

        $request1 = new Request('GET', $this->getServer()->getUrl());
        $request2 = new Request('HEAD', $this->getServer()->getUrl());
        $request3 = new Request('GET', $this->getServer()->getUrl());
        $this->multi->add($request1);
        $this->multi->add($request2);
        $this->multi->add($request3);

        try {
            $this->multi->send();
            $this->fail('ExceptionCollection not thrown when aggregating request exceptions');
        } catch (ExceptionCollection $e) {

            $this->assertInstanceOf('ArrayIterator', $e->getIterator());
            $this->assertEquals(1, count($e));
            $exceptions = $e->getIterator();

            $response1 = $request1->getResponse();
            $response2 = $request2->getResponse();
            $response3 = $request3->getResponse();

            $this->assertNotEquals((string) $response1, (string) $response2);
            $this->assertNotEquals((string) $response3, (string) $response1);
            $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $response1);
            $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $response2);
            $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $response3);

            $failed = $exceptions[0]->getResponse();
            $this->assertEquals(404, $failed->getStatusCode());
            $this->assertEquals(1, count($e));

            // Test the IteratorAggregate functionality
            foreach ($e as $excep) {
                $this->assertEquals($failed, $excep->getResponse());
            }
        }
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::send
     * @covers Guzzle\Http\Curl\CurlMulti::processResponse
     */
    public function testCurlErrorsAreCaught()
    {
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");

        try {
            $request = RequestFactory::create('GET', 'http://127.0.0.1:9876/');
            $request->setClient(new Client());
            $request->getCurlOptions()->set(CURLOPT_FRESH_CONNECT, true);
            $request->getCurlOptions()->set(CURLOPT_TIMEOUT, 0);
            $request->getCurlOptions()->set(CURLOPT_CONNECTTIMEOUT, 1);
            $request->send();
            $this->fail('CurlException not thrown');
        } catch (CurlException $e) {
            $m = $e->getMessage();
            $this->assertContains('[curl] 7:', $m);
            $this->assertContains('[url] http://127.0.0.1:9876/', $m);
            $this->assertContains('[debug] ', $m);
            $this->assertContains('[info] array (', $m);
            $this->assertContains('Connection refused', $m);
        }
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::removeQueuedRequest
     */
    public function testRemovesQueuedRequests()
    {
        $request = RequestFactory::create('GET', 'http://127.0.0.1:9876/');
        $request->setClient(new Client());
        $request->setResponse(new Response(200), true);
        $this->multi->add($request);
        $this->multi->send();
        $this->assertTrue($this->updates->hasKey(CurlMulti::ADD_REQUEST) !== false);
        $this->assertTrue($this->updates->hasKey(CurlMulti::POLLING) === false);
        $this->assertTrue($this->updates->hasKey(CurlMulti::COMPLETE) !== false);
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::removeQueuedRequest
     */
    public function testRemovesQueuedRequestsAddedInTransit()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
        ));
        $client = new Client($this->getServer()->getUrl());
        $r = $client->get();
        $r->getEventManager()->attach(function($subject, $event) use ($client) {
            if ($event == 'request.receive.status_line') {
                // Create a request using a queued response
                $request = $client->get()->setResponse(new Response(200), true);
                $request->send();
            }
        });

        $r->send();
        $this->assertEquals(1, count($this->getServer()->getReceivedRequests(false)));
    }
    
    /**
     * @covers Guzzle\Http\Curl\CurlMulti
     */
    public function testProperlyBlocksBasedOnRequestsInScope()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\ntest1",
            "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\ntest2",
            "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\ntest3",
            "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\ntest4",
            "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\ntest5",
            "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\ntest6",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
        ));
        
        $client = new Client($this->getServer()->getUrl());
        
        $requests = array(
            $client->get(),
            $client->get(),
            $client->get()
        );
        
        $callback = function($subject, $event) use ($client) {
            if ($event == 'request.complete') {
                $client->getConfig()->set('called', $client->getConfig('called') + 1);
                $request = $client->get();
                if ($client->getConfig('called') <= 2) {
                    $request->getEventManager()->attach(function($s, $e) use ($client) {
                        if ($e == 'request.complete') {
                            $client->head()->send();
                        }
                    });
                }
                $request->send();
            }
        };
        
        $requests[0]->getEventManager()->attach($callback);
        $requests[1]->getEventManager()->attach($callback);
        $requests[2]->getEventManager()->attach($callback);
        
        $client->send($requests);
        
        $this->assertEquals(8, count($this->getServer()->getReceivedRequests(false)));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::removeQueuedRequest
     * @expectedException Guzzle\Http\Message\BadResponseException
     */
    public function testCatchesExceptionsWhenRemovingQueuedRequests()
    {
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $client = new Client($this->getServer()->getUrl());
        $r = $client->get();
        $r->getEventManager()->attach(function() use ($client) {
            // Create a request using a queued response
            $client->get()->setResponse(new Response(404), true)->send();
        });
        $r->send();
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::send
     * @expectedException Guzzle\Common\ExceptionCollection
     * @expectedExceptionMessage test
     */
    public function testCatchesRandomExceptionsThrownDuringPerform()
    {
        $client = new Client($this->getServer()->getUrl());
        $multi = $this->getMock('Guzzle\\Http\\Curl\\CurlMulti', array('perform'));
        $multi->expects($this->once())
              ->method('perform')
              ->will($this->throwException(new \Exception('test')));
        $multi->add($client->get());
        $multi->send();
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::send
     */
    public function testDoesNotSendRequestsDecliningToBeSent()
    {
        $this->getServer()->flush();
        $client = new Client($this->getServer()->getUrl());
        $request = $client->get();
        $request->getEventManager()->attach(function($subject, $event) {
            if ($event == 'request.before_send') {
                $subject->setResponse(new Response(200));
            }
        });

        $multi = new CurlMulti();
        $multi->add($request);
        $multi->send();
        $this->assertEquals(0, count($this->getServer()->getReceivedRequests(false)));
    }

    /**
     * Logs updates from the multi object
     */
    public function update(SubjectInterface $subject, $event, $context = null)
    {
        $this->updates->add($event, array($event, $context));
    }
}