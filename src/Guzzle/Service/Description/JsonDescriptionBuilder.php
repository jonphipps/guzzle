<?php

namespace Guzzle\Service\Description;

/**
 * Build service descriptions using a JSON document
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class JsonDescriptionBuilder implements DescriptionBuilderInterface
{
    /**
     * @var string
     */
    private $json;

    /**
     * @param string $filename File to open
     * @throws RuntimeException
     */
    public function __construct($filename)
    {
        if (false === $this->json = file_get_contents($filename)) {
            throw new \RuntimeException('Error loading data from ' . $filename);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function build()
    {
        return ServiceDescription::factory(json_decode($this->json, true));
    }
}