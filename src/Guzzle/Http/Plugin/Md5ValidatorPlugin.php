<?php

namespace Guzzle\Http\Plugin;

use Guzzle\Common\Event;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ensures that an the MD5 hash of an entity body matches the Content-MD5
 * header (if set) of an HTTP response.  An exception is thrown if the
 * calculated MD5 does not match the expected MD5.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class Md5ValidatorPlugin implements EventSubscriberInterface
{
    /**
     * @var int Maximum Content-Length in bytes to validate
     */
    protected $contentLengthCutoff;

    /**
     * @var bool Whether or not to compare when a Content-Encoding is present
     */
    protected $contentEncoded;
    
    /**
     * {@inheritdoc} 
     */
    public static function getSubscribedEvents()
    {
        return array('request.complete' => 'onRequestComplete');
    }

    /**
     * Constructor
     *
     * @param bool $calcContentEncoded (optional) Calculating the MD5 hash of an
     *      entity body where a Content-Encoding was applied is a more expensive
     *      comparison because the entity body will need to be compressed in
     *      order to get the correct hash.  Set to FALSE to not validate
     *      the MD5 hash of an entity body with an applied Content-Encoding.
     * @param int $contentLengthCutoff (optional) Maximum Content-Length (bytes)
     *      in which a MD5 hash will be validated.  Any response with a
     *      Content-Length greater than this value will not be validated
     *      because it will be deemed too memory intensive
     */
    public function __construct($contentEncoded = true, $contentLengthCutoff = 2097152)
    {
        $this->contentLengthCutoff = $contentLengthCutoff;
        $this->contentEncoded = $contentEncoded;
    }

    /**
     * {@inheritdoc}
     * @throws UnexpectedValueException
     */
    public function onRequestComplete(Event $event)
    {
        $response = $event['response'];
        
        $contentMd5 = $response->getContentMd5();
        if (!$contentMd5) {
            return;
        }

        $contentEncoding = $response->getContentEncoding();
        if ($contentEncoding && !$this->contentEncoded) {
            return false;
        }

        // Make sure that the request's size is under the cutoff size
        $size = $response->getContentLength() ?: $response->getBody()->getSize();
        if (!$size || $size > $this->contentLengthCutoff) {
            return;
        }

        switch ($contentEncoding) {
            case 'gzip':
                $response->getBody()->compress('zlib.deflate');
                $hash = $response->getBody()->getContentMd5();
                $response->getBody()->uncompress();
                break;
            case 'compress':
                $response->getBody()->compress('bzip2.compress');
                $hash = $response->getBody()->getContentMd5();
                $response->getBody()->uncompress();
                break;
            default:
                if ($contentEncoding) {
                    return;
                }
                $hash = $response->getBody()->getContentMd5();
                break;
        }

        if ($contentMd5 !== $hash) {
            throw new \UnexpectedValueException(sprintf(
                'The response entity body may have been '
                . 'modified over the wire.  The Content-MD5 '
                . 'received (%s) did not match the calculated '
                . 'MD5 hash (%s).',
                $contentMd5, $hash
            ));
        }
    }
}