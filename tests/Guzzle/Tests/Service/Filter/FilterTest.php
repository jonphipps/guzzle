<?php

namespace Guzzle\Tests\Common\Filter;

use Guzzle\Service\Filter\AbstractFilter;
use Guzzle\Tests\Mock\MockFilter;
use Guzzle\Tests\Mock\MockFilterCommand;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class FilterTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers \Guzzle\Service\Filter\AbstractFilter::__construct
     */
    public function testConstructorNoParams()
    {
        $filter = new MockFilter();
        $this->assertEquals(array(), $filter->getAll());
    }

    /**
     * @covers \Guzzle\Service\Filter\FilterInterface
     * @covers \Guzzle\Service\Filter\AbstractFilter::__construct
     * @covers \Guzzle\Service\Filter\AbstractFilter::init
     */
    public function testConstructorWithParams()
    {
        $filter = new MockFilter(array(
            'test' => 'value'
        ));
        $this->assertEquals(array('test' => 'value'), $filter->getAll());
        $filter = new MockFilter(new \Guzzle\Common\Collection(array(
            'test' => 'value'
        )));
        $this->assertEquals(array('test' => 'value'), $filter->getAll());
    }

    /**
     * @covers  \Guzzle\Service\Filter\AbstractFilter
     * @covers  \Guzzle\Service\Filter\AbstractFilter::process
     */
    public function testProcess()
    {
        $filter = new MockFilter();
        $command = new MockFilterCommand();
        $this->assertTrue($filter->process($command));
        $this->assertTrue($filter->called);
        $this->assertEquals('modified', $command->value);
        $filter->set('type_hint', 'Blah');
        $this->assertFalse($filter->process($command));
        $filter->set('type_hint', 'Guzzle\Tests\Mock\MockFilterCommand');
        $this->assertTrue($filter->process($command));
    }

    /**
     * @covers Guzzle\Service\Filter\AbstractFilter::process
     */
    public function testCannotProcessInvalidTypeHint()
    {
        $filter = new MockFilter(array(
            'type_hint' => 'Guzzle\Common\NullObject'
        ));
        $command = new MockFilterCommand();
        $this->assertFalse($filter->process($command));

        $filter = new MockFilter(array(
            'type_hint' => 'Guzzle\Common\NullObject'
        ));
        $command = new \Guzzle\Common\NullObject();
        $this->assertTrue($filter->process($command));
    }

    /**
     * @covers Guzzle\Service\Filter\ClosureFilter
     */
    public function testClosureFilter()
    {
        $filter = new \Guzzle\Service\Filter\ClosureFilter(function($command) {
            return 'closure';
        });

        $command = new MockFilterCommand();
        $this->assertEquals('closure', $filter->process($command));
    }
}