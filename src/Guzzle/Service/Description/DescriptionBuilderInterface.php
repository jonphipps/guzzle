<?php

namespace Guzzle\Service\Description;

/**
 * Build service descriptions
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
interface DescriptionBuilderInterface
{
    /**
     * Builds a new ServiceDescription object
     *
     * @param string $filename File to build
     *
     * @return ServiceDescription
     */
    static function build($filename);
}