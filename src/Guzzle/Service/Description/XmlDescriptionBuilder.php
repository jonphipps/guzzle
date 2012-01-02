<?php

namespace Guzzle\Service\Description;

/**
 * Build service descriptions using an XML document
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class XmlDescriptionBuilder implements DescriptionBuilderInterface
{
    /**
     * @var SimpleXMLElement
     */
    private $xml;

    /**
     * @param string $xml XML string or the full path of an XML file
     *
     * @throws InvalidArgumentException if the file cannot be opened
     */
    public function __construct($xml)
    {
        $isFile = strpos($xml, '<?xml') === false;
        if ($isFile && !file_exists($xml)) {
            throw new \InvalidArgumentException('Unable to open ' . $xml . ' for reading');
        }
        $this->xml = new \SimpleXMLElement($xml, 0, $isFile);
    }

    /**
     * {@inheritdoc}
     */
    public function build()
    {
        $data = array(
            'types' => array(),
            'commands' => array()
        );

        // Register any custom type definitions
        if ($this->xml->types) {
            foreach ($this->xml->types->type as $type) {
                $attr = $type->attributes();
                $name = (string) $attr->name;
                $data['types'][$name] = array();
                foreach ($attr as $key => $value) {
                    $data['types'][$name][(string) $key] = (string) $value;
                }
            }
        }

        // Parse the commands in the XML doc
        foreach ($this->xml->commands->command as $command) {
            $attr = $command->attributes();
            $name = (string) $attr->name;
            $data['commands'][$name] = array(
                'params' => array()
            );
            foreach ($attr as $key => $value) {
                $data['commands'][$name][(string) $key] = (string) $value;
            }
            $data['commands'][$name]['doc'] = (string) $command->doc;
            foreach ($command->param as $param) {
                $attr = $param->attributes();
                $paramName = (string) $attr['name'];
                $data['commands'][$name]['params'][$paramName] = array();
                foreach ($attr as $pk => $pv) {
                    $pv = (string) $pk == 'required' ? (string) $pv === 'true' : (string) $pv;
                    $data['commands'][$name]['params'][$paramName][(string) $pk] = (string) $pv;
                }
            }
        }

        return ServiceDescription::factory($data);
    }
}