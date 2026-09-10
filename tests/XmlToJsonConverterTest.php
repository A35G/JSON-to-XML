<?php

declare(strict_types=1);

namespace A35G\JsonToXml\Tests;

use A35G\JsonToXml\XmlToJsonConverter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class XmlToJsonConverterTest extends TestCase
{
    public function testConvertsSimpleScalarValues(): void
    {
        $converter = new XmlToJsonConverter();
        $array = $converter->xmlToArray('<data><nome>Mario</nome><eta>34</eta></data>');

        $this->assertSame('Mario', $array['nome']);
        $this->assertSame('34', $array['eta']);
    }

    public function testAttributesBecomeAtPrefixedKeys(): void
    {
        $converter = new XmlToJsonConverter();
        $array = $converter->xmlToArray('<request code="" typeReq="ABC"><cliente>Mario</cliente></request>');

        $this->assertSame('', $array['@code']);
        $this->assertSame('ABC', $array['@typeReq']);
        $this->assertSame('Mario', $array['cliente']);
    }

    public function testCdataAndPlainTextProduceTheSameValue(): void
    {
        $converter = new XmlToJsonConverter();

        $plain = $converter->xmlToArray('<data><nome>Mario</nome></data>');
        $cdata = $converter->xmlToArray('<data><nome><![CDATA[Mario]]></nome></data>');

        $this->assertSame($plain, $cdata);
    }

    public function testSingleChildIsAnObjectNotAnArrayByDefault(): void
    {
        $converter = new XmlToJsonConverter();
        $array = $converter->xmlToArray('<Note><Nota><Principale>0</Principale></Nota></Note>');

        $this->assertIsArray($array['Nota']);
        $this->assertSame('0', $array['Nota']['Principale']);
    }

    public function testRepeatedSiblingsBecomeAnArray(): void
    {
        $converter = new XmlToJsonConverter();
        $array = $converter->xmlToArray(
            '<Note><Nota><Principale>0</Principale></Nota><Nota><Principale>1</Principale></Nota></Note>'
        );

        $this->assertIsList($array['Nota']);
        $this->assertCount(2, $array['Nota']);
        $this->assertSame('0', $array['Nota'][0]['Principale']);
        $this->assertSame('1', $array['Nota'][1]['Principale']);
    }

    public function testForceArrayTagsKeepsSingleElementAsArray(): void
    {
        $converter = new XmlToJsonConverter(forceArrayTags: ['Nota']);
        $array = $converter->xmlToArray('<Note><Nota><Principale>0</Principale></Nota></Note>');

        $this->assertIsList($array['Nota']);
        $this->assertCount(1, $array['Nota']);
        $this->assertSame('0', $array['Nota'][0]['Principale']);
    }

    public function testMixedContentWithAttributeAndText(): void
    {
        $converter = new XmlToJsonConverter();
        $array = $converter->xmlToArray('<operatore codArea="XXX"><![CDATA[XX]]></operatore>');

        $this->assertSame('XXX', $array['@codArea']);
        $this->assertSame('XX', $array['#text']);
    }

    public function testCompletelyEmptyElementBecomesNull(): void
    {
        $converter = new XmlToJsonConverter();
        $array = $converter->xmlToArray('<data><note/></data>');

        $this->assertNull($array['note']);
    }

    public function testEmptyElementWithOnlyAttributesHasNoTextKey(): void
    {
        $converter = new XmlToJsonConverter();
        $array = $converter->xmlToArray('<data><operatore codArea="XXX"/></data>');

        $this->assertSame(['@codArea' => 'XXX'], $array['operatore']);
    }

    public function testInvalidXmlThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $converter = new XmlToJsonConverter();
        $converter->xmlToArray('<data><non-chiuso></data>');
    }

    public function testEmptyStringThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $converter = new XmlToJsonConverter();
        $converter->xmlToArray('   ');
    }

    public function testXmlToJsonStringProducesValidJson(): void
    {
        $converter = new XmlToJsonConverter();
        $json = $converter->xmlToJsonString('<data><nome>Mario</nome></data>');

        $this->assertJson($json);
        $this->assertSame(['nome' => 'Mario'], json_decode($json, true));
    }

    public function testUnicodeCharactersArePreserved(): void
    {
        $converter = new XmlToJsonConverter();

        $result = $converter->xmlToArray('<root><testo>Città € 日本語 🚀</testo></root>');

        $this->assertSame('Città € 日本語 🚀', $result['testo']);
    }

    public function testMixedContentWithChildElementIsPreserved(): void
    {
        $converter = new XmlToJsonConverter();

        $result = $converter->xmlToArray('<root>prima<child>centro</child>dopo</root>');

        $this->assertSame('primadopo', $result['#text']);

        $this->assertSame('centro', $result['child']);
    }

    public function testSetForceArrayTagsUpdatesConfiguration(): void
    {
        $converter = new XmlToJsonConverter();

        $converter->setForceArrayTags(['item']);

        $result = $converter->xmlToArray('<root><item>one</item></root>');

        $this->assertSame(['one'], $result['item']);
    }

}
