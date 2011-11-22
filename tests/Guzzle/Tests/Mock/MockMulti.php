<?php

namespace Guzzle\Tests\Mock;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class MockMulti extends \Guzzle\Http\Curl\CurlMulti
{
    public function getHandle()
    {
        return $this->multiHandle;
    }
}