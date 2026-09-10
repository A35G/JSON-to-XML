<?php

declare(strict_types=1);

namespace A35G\JsonToXml\Tests;

use A35G\JsonToXml\JsonToXmlConverter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class JsonToXmlConverterTest extends TestCase
{
    public function testConvertsSimpleScalarValues(): void
    {
        $converter = new JsonToXmlConverter('data');
        $xml = $converter->jsonToXmlString('{"nome": "Mario", "eta": 34}');

        $this->assertStringContainsString('<nome>Mario</nome>', $xml);
        $this->assertStringContainsString('<eta>34</eta>', $xml);
    }

    public function testAttributesAreSetOnTheOwningElement(): void
    {
        $converter = new JsonToXmlConverter('root');
        $xml = $converter->jsonToXmlString('{"request": {"@code": "", "@typeReq": "ABC"}}');

        $this->assertStringContainsString('code=""', $xml);
        $this->assertStringContainsString('typeReq="ABC"', $xml);
    }

    public function testCdataIsAppliedAutomaticallyWhenContentNeedsIt(): void
    {
        $converter = new JsonToXmlConverter('data');
        $xml = $converter->jsonToXmlString('{"testo": "contiene <tag> e \\"virgolette\\""}');

        $this->assertStringContainsString('<![CDATA[', $xml);
    }

    public function testTextKeyUsesAutomaticCdataWhenContentNeedsIt(): void
    {
        $converter = new JsonToXmlConverter('data');
        $xml = $converter->jsonToXmlString('{"numero": {"#text": "contiene <tag>"}}');

        $this->assertStringContainsString('<numero><![CDATA[contiene <tag>]]></numero>', $xml);
    }

    public function testTextKeyStaysPlainWhenContentDoesNotNeedCdata(): void
    {
        $converter = new JsonToXmlConverter('data');
        $xml = $converter->jsonToXmlString('{"numero": {"#text": "XX-000000"}}');

        $this->assertStringContainsString('<numero>XX-000000</numero>', $xml);
        $this->assertStringNotContainsString('CDATA', $xml);
    }

    public function testMixedContentWithAttributeAndAutomaticCdataText(): void
    {
        $converter = new JsonToXmlConverter('data');
        $xml = $converter->jsonToXmlString('{"operatore": {"@codArea": "XXX", "#text": "contiene <tag>"}}');

        $this->assertStringContainsString('<operatore codArea="XXX"><![CDATA[contiene <tag>]]></operatore>', $xml);
    }

    public function testJsonListIsRepeatedWithoutWrapper(): void
    {
        $converter = new JsonToXmlConverter('data');
        $xml = $converter->jsonToXmlString('{"Nota": [{"Principale": "0"}, {"Principale": "1"}]}');

        $this->assertEquals(2, substr_count($xml, '<Nota>'));
        $this->assertStringNotContainsString('<item>', $xml);
    }

    public function testNullValueProducesEmptyElement(): void
    {
        $converter = new JsonToXmlConverter('data');
        $xml = $converter->jsonToXmlString('{"note": null}');

        $this->assertMatchesRegularExpression('/<note\s*\/>|<note><\/note>/', $xml);
    }

    public function testInvalidJsonThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $converter = new JsonToXmlConverter('data');
        $converter->jsonToXmlString('{invalid json}');
    }

    public function testArrayAsAttributeValueThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $converter = new JsonToXmlConverter('data');
        $converter->jsonToXmlString('{"nodo": {"@attr": {"non": "valido"}}}');
    }

    public function testCdataCanBeDisabled(): void
    {
        $converter = new JsonToXmlConverter(rootName: 'data', itemNodeName: 'item', useCdata: false);

        $xml = $converter->jsonToXmlString('{"testo": "ciao <mondo> & amici"}');

        $this->assertStringNotContainsString('<![CDATA[', $xml);
        $this->assertStringContainsString('ciao &lt;mondo&gt; &amp; amici', $xml);
    }

    public function testUnicodeCharactersArePreserved(): void
    {
        $converter = new JsonToXmlConverter('data');

        $xml = $converter->jsonToXmlString('{"testo": "Città € 日本語 🚀"}');

        $this->assertStringContainsString('<testo>Città € 日本語 🚀</testo>', $xml);
    }

}
