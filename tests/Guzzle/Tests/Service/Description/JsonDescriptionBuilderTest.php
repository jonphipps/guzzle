<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Service\Description\JsonDescriptionBuilder;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 * @covers Guzzle\Service\Description\JsonDescriptionBuilder
 */
class JsonDescriptionBuilderTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @expectedException RuntimeException
     */
    public function testThrowsErrorsOnOpenFailure()
    {
        $b = @new JsonDescriptionBuilder('/foo.does.not.exist');
    }

    public function testBuildsServiceDescriptions()
    {
        $b = new JsonDescriptionBuilder(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'TestData' . DIRECTORY_SEPARATOR . 'test_service.json');
        $description = $b->build();
        $this->assertTrue($description->hasCommand('test'));
        $test = $description->getCommand('test');
        $this->assertEquals('/path', $test->getPath());
    }
}