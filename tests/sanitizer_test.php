<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Tests for the text sanitiser of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp;

use message_whatsapp\local\sanitizer;

/**
 * Tests for the text sanitiser of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \message_whatsapp\local\sanitizer
 */
final class sanitizer_test extends \advanced_testcase {
    /**
     * HTML that has to end up as flat text, with no markup and no leftover entity.
     *
     * @return array[] Cases of [html, expected].
     */
    public static function html_provider(): array {
        return [
            'list' => ['<ul><li>Primero</li><li>Segundo</li></ul>', '* Primero * Segundo'],
            'nested list' => ['<ol><li>Uno<ul><li>Uno a</li></ul></li></ol>', '* Uno * Uno a'],
            'link' => [
                '<p>Ver <a href="https://ejemplo.org/mod/assign/view.php?id=7">la tarea</a> ahora.</p>',
                'Ver la tarea ahora.',
            ],
            'link with no text' => ['<p>Ir <a href="https://ejemplo.org/x"></a>ya</p>', 'Ir ya'],
            'entities' => ['<p>Tom&#39;s &amp; Jerry&nbsp;caf&eacute; &lt;b&gt;</p>', 'Tom\'s & Jerry café <b>'],
            'several paragraphs' => ['<p>Uno</p><p>Dos</p><p>Tres</p>', 'Uno Dos Tres'],
            'preformatted' => ["<pre>a\t\tb\n\n\n   c</pre>", 'a b c'],
            'table' => ['<table><tr><td>A</td><td>B</td></tr></table>', 'A B'],
            'emphasis' => ['<p>Foro: <em>Novedades</em></p>', 'Foro: _Novedades_'],
            'bold is upper cased by core' => ['<p>Hola <strong>mundo</strong></p>', 'Hola MUNDO'],
            'heading is upper cased by core' => ['<h2>Aviso</h2><p>texto</p>', 'AVISO texto'],
            'emoji survives' => ['<p>Listo 👍 ya</p>', 'Listo 👍 ya'],
            'broken utf8 does not blow up' => ["<p>hola \xF0\x28\x8C\x28 mundo</p>", 'hola (( mundo'],
        ];
    }

    /**
     * The HTML of a message becomes flat text with the markup and the entities resolved.
     *
     * @dataProvider html_provider
     * @param string $html Input HTML.
     * @param string $expected Expected sanitised text.
     */
    public function test_for_param_converts_html(string $html, string $expected): void {
        $this->assertSame($expected, sanitizer::for_param($html, 200));
    }

    /**
     * Input that has to end up as an empty string.
     *
     * @return array[] Cases of [html].
     */
    public static function empty_provider(): array {
        return [
            'empty string' => [''],
            'empty paragraph' => ['<p></p>'],
            'only a line break' => ['<br>'],
            'only whitespace' => ["\n\t   "],
            'only a non breaking space' => ['<p>&nbsp;</p>'],
            'only markup' => ['<div><span></span></div>'],
        ];
    }

    /**
     * Empty or blank content gives an empty parameter, never a string made of spaces.
     *
     * @dataProvider empty_provider
     * @param string $html Input HTML.
     */
    public function test_for_param_with_empty_content(string $html): void {
        $this->assertSame('', sanitizer::for_param($html, 60));
    }

    /**
     * Texts that are longer than the limit and have to be cut.
     *
     * @return array[] Cases of [html, max, expected].
     */
    public static function truncation_provider(): array {
        return [
            'cut on a word boundary' => [
                '<p>La entrega de la tarea final vence el viernes a las 23:59 horas</p>',
                30,
                'La entrega de la tarea final…',
            ],
            'boundary falls exactly between words' => ['hola mundo cruel', 11, 'hola mundo…'],
            'one character short of a word' => ['hola mundo', 9, 'hola…'],
            'exact fit is not cut' => ['hola mundo', 10, 'hola mundo'],
            'one character to spare' => ['hola mundo', 11, 'hola mundo'],
            'single word longer than the limit is cut mid word' => [
                '<p>Supercalifragilisticoespialidoso</p>',
                10,
                'Supercali…',
            ],
            'no room for any text' => ['hola mundo', 1, '…'],
            'trailing space before the cut is removed' => ['<p>aaaa&nbsp;bbbb cccc dddd</p>', 12, 'aaaa bbbb…'],
            'multibyte is counted in characters' => [str_repeat('ñ', 40), 10, 'ñññññññññ…'],
        ];
    }

    /**
     * Long content is cut at a word boundary and the marker is counted inside the limit.
     *
     * @dataProvider truncation_provider
     * @param string $html Input HTML.
     * @param int $max Maximum length in characters.
     * @param string $expected Expected sanitised text.
     */
    public function test_for_param_truncates(string $html, int $max, string $expected): void {
        $result = sanitizer::for_param($html, $max);
        $this->assertSame($expected, $result);
        $this->assertLessThanOrEqual($max, \core_text::strlen($result));
    }

    /**
     * A limit of zero or less gives an empty string instead of a lone marker.
     */
    public function test_for_param_with_no_room_at_all(): void {
        $this->assertSame('', sanitizer::for_param('hola mundo', 0));
        $this->assertSame('', sanitizer::for_param('hola mundo', -5));
    }

    /**
     * Plain text is not run through the HTML converter, which would eat a literal lower than sign.
     */
    public function test_for_param_respects_the_plain_format(): void {
        $this->assertSame('5 < 10 & más', sanitizer::for_param('5 < 10 & más', 60, FORMAT_PLAIN));
        $this->assertSame('Hola', sanitizer::for_param('<p>Hola</p>', 60, FORMAT_MOODLE));
    }

    /**
     * Whatever the input, the result never carries a character that Meta rejects in a template parameter.
     */
    public function test_for_param_never_returns_forbidden_characters(): void {
        $html = "  <h1>Aviso&nbsp;importante</h1>\r\n<ul>\n\t<li>Entrega\t\tel    viernes</li>\n" .
            "\t<li>Ver <a href=\"https://ejemplo.org/x\">la\u{00a0}\u{00a0}\u{00a0}tarea</a></li>\n</ul>\n" .
            "<pre>   fin\n\n\n   </pre>  ";

        $result = sanitizer::for_param($html, 160);

        $this->assertDoesNotMatchRegularExpression('/[\n\r\t]/', $result);
        $this->assertDoesNotMatchRegularExpression('/ {2,}/', $result);
        $this->assertDoesNotMatchRegularExpression('/\x{00a0}/u', $result);
        $this->assertSame(trim($result), $result);
        $this->assertLessThanOrEqual(160, \core_text::strlen($result));
        $this->assertStringContainsString('AVISO IMPORTANTE', $result);
    }
}
