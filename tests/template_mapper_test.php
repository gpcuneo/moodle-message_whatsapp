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
 * Tests for the template mapper of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp;

use message_whatsapp\local\mapped;
use message_whatsapp\local\sanitizer;
use message_whatsapp\local\template_mapper;

/**
 * Tests for the template mapper of the WhatsApp message processor.
 *
 * The event data of the three notifications used here is built after the code of core that produces them, checked
 * against the 4.5 checkout: `mod/assign/locallib.php` (which copies the subject into `smallmessage` and leaves
 * `fullmessagehtml` empty when the recipient reads plain text), `mod/forum/classes/task/send_user_notifications.php`
 * and `lib/badgeslib.php` (which leaves `smallmessage` empty and stores a `moodle_url` object in `contexturl`).
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \message_whatsapp\local\template_mapper
 * @covers     \message_whatsapp\local\mapped
 */
final class template_mapper_test extends \advanced_testcase {
    /**
     * Builds the event data of a notification the way core hands it to a processor: a plain stdClass.
     *
     * @param array $fields Fields of the event data.
     * @return \stdClass Event data.
     */
    protected function eventdata(array $fields): \stdClass {
        return (object) $fields;
    }

    /**
     * Checks the invariants that hold for every mapping, whatever the message was.
     *
     * @param mapped $result Result of the mapping.
     */
    protected function assert_common_invariants(mapped $result): void {
        $this->assertSame(template_mapper::TEMPLATE, $result->templatekey);
        $this->assertContains($result->lang, [template_mapper::LANG_ES, template_mapper::LANG_EN]);
        $this->assertCount(3, $result->params);
        $this->assertSame([0, 1, 2], array_keys($result->params));
        $this->assertNotSame('', $result->url);

        $limits = [template_mapper::MAX_SITE, template_mapper::MAX_SUBJECT, template_mapper::MAX_SUMMARY];

        foreach ($result->params as $index => $param) {
            $this->assertIsString($param);
            $this->assertNotSame('', $param, "Meta rejects an empty parameter (index $index)");
            $this->assertLessThanOrEqual($limits[$index], \core_text::strlen($param));
            $this->assertDoesNotMatchRegularExpression('/[\n\r\t]/', $param);
            $this->assertDoesNotMatchRegularExpression('/ {2,}/', $param);
            $this->assertSame(trim($param), $param);
        }
    }

    /**
     * A grading notification of mod_assign maps onto the base template.
     *
     * mod_assign copies the subject into `smallmessage`, so the summary has to come from the body instead: the two
     * lines of the template would otherwise say the same thing twice.
     */
    public function test_map_of_an_assign_notification(): void {
        $this->resetAfterTest();
        set_config('sitename_short', 'Instituto Demo', 'message_whatsapp');

        $eventdata = $this->eventdata([
            'courseid' => 4,
            'modulename' => 'assign',
            'component' => 'mod_assign',
            'name' => 'assign_notification',
            'userto' => (object) ['id' => 7, 'lang' => 'es'],
            'subject' => 'Ensayo final: comentarios de la entrega',
            'fullmessage' => "Guillermo Cuneo ha dejado comentarios sobre tu entrega de 'Ensayo final'.\n\n" .
                "Puedes verlos como anexo a tu entrega.",
            'fullmessageformat' => FORMAT_PLAIN,
            'fullmessagehtml' => '',
            'smallmessage' => 'Ensayo final: comentarios de la entrega',
            'notification' => 1,
            'contexturl' => 'https://ejemplo.org/mod/assign/view.php?id=7',
            'contexturlname' => 'Ensayo final',
        ]);

        $result = template_mapper::map($eventdata);

        $this->assert_common_invariants($result);
        $this->assertSame(template_mapper::LANG_ES, $result->lang);
        $this->assertSame('Instituto Demo', $result->params[0]);
        $this->assertSame('Ensayo final: comentarios de la entrega', $result->params[1]);
        $this->assertSame(
            "Guillermo Cuneo ha dejado comentarios sobre tu entrega de 'Ensayo final'. " .
                'Puedes verlos como anexo a tu entrega.',
            $result->params[2]
        );
        $this->assertSame('https://ejemplo.org/mod/assign/view.php?id=7', $result->url);
    }

    /**
     * A forum post maps with the summary taken from smallmessage, which is what the provider wrote for one line.
     */
    public function test_map_of_a_forum_post(): void {
        $this->resetAfterTest();
        set_config('sitename_short', 'Instituto Demo', 'message_whatsapp');

        $eventdata = $this->eventdata([
            'courseid' => 4,
            'component' => 'mod_forum',
            'name' => 'posts',
            'userto' => (object) ['id' => 7, 'lang' => 'es'],
            'subject' => 'CS101: Cambio de fecha del parcial',
            'fullmessage' => "CS101 -> Novedades -> Cambio de fecha del parcial\n\n" .
                "Hola, el parcial se pasa al lunes 22.\n\nVer en contexto: https://ejemplo.org/mod/forum/discuss.php?d=12",
            'fullmessageformat' => FORMAT_PLAIN,
            'fullmessagehtml' => '<p>Hola, el parcial se pasa al lunes 22.</p>',
            'smallmessage' => 'Juan Perez posted in CS101: Novedades: Cambio de fecha del parcial',
            'notification' => 1,
            'contexturl' => 'https://ejemplo.org/mod/forum/discuss.php?d=12#p34',
            'contexturlname' => 'Cambio de fecha del parcial',
        ]);

        $result = template_mapper::map($eventdata);

        $this->assert_common_invariants($result);
        $this->assertSame('CS101: Cambio de fecha del parcial', $result->params[1]);
        $this->assertSame('Juan Perez posted in CS101: Novedades: Cambio de fecha del parcial', $result->params[2]);
        $this->assertSame('https://ejemplo.org/mod/forum/discuss.php?d=12#p34', $result->url);
    }

    /**
     * A badge notification maps with the summary taken from the HTML body, and with the moodle_url object resolved.
     *
     * lib/badgeslib.php leaves `smallmessage` empty and assigns the `moodle_url` object itself to `contexturl`, and
     * the setter of core's message class stores it as it is, so the processor really does receive an object here.
     */
    public function test_map_of_a_badge_notification(): void {
        global $CFG;

        $this->resetAfterTest();
        set_config('sitename_short', 'Instituto Demo', 'message_whatsapp');

        $badgeurl = new \moodle_url('/badges/badge.php', ['hash' => 'abc123']);

        $eventdata = $this->eventdata([
            'courseid' => SITEID,
            'component' => 'moodle',
            'name' => 'badgerecipientnotice',
            'userto' => (object) ['id' => 7, 'lang' => ''],
            'subject' => 'Has obtenido una insignia',
            'fullmessage' => 'Hola Ana, ya tienes la insignia Participacion.',
            'fullmessageformat' => FORMAT_HTML,
            'fullmessagehtml' => '<p>Hola Ana,</p><p>Ya tienes la insignia <strong>Participacion</strong>. ' .
                'Podes verla en <a href="https://ejemplo.org/badges/badge.php?hash=abc123">tu perfil</a>.</p>',
            'smallmessage' => '',
            'notification' => 1,
            'contexturl' => $badgeurl,
            'contexturlname' => 'Participacion',
        ]);

        $result = template_mapper::map($eventdata);

        $this->assert_common_invariants($result);
        $this->assertSame('Has obtenido una insignia', $result->params[1]);
        $this->assertSame(
            'Hola Ana, Ya tienes la insignia PARTICIPACION. Podes verla en tu perfil.',
            $result->params[2]
        );
        $this->assertSame($CFG->wwwroot . '/badges/badge.php?hash=abc123', $result->url);
        $this->assertStringNotContainsString('<', $result->params[2]);
    }

    /**
     * A notification with no context gets the front page, because the button of the template is always there.
     */
    public function test_map_without_contexturl_uses_the_site_url(): void {
        global $CFG;

        $this->resetAfterTest();

        $eventdata = $this->eventdata([
            'component' => 'moodle',
            'name' => 'notices',
            'subject' => 'Mantenimiento programado',
            'smallmessage' => 'El sitio estara fuera de servicio el sabado de 2 a 4.',
            'notification' => 1,
        ]);

        $result = template_mapper::map($eventdata);

        $this->assert_common_invariants($result);
        $this->assertSame($CFG->wwwroot, $result->url);
    }

    /**
     * An empty contexturl, or one that is not text at all, is treated as no context.
     */
    public function test_map_with_an_unusable_contexturl(): void {
        global $CFG;

        $this->resetAfterTest();

        foreach (['', '   ', null, 0, ['not', 'a', 'url']] as $contexturl) {
            $result = template_mapper::map($this->eventdata([
                'subject' => 'Aviso',
                'smallmessage' => 'Contenido del aviso',
                'contexturl' => $contexturl,
            ]));

            $this->assertSame($CFG->wwwroot, $result->url);
        }
    }

    /**
     * The short site name comes from the plugin setting, sanitised and cut like any other parameter.
     */
    public function test_site_name_comes_from_the_setting(): void {
        $this->resetAfterTest();

        set_config('sitename_short', "  Instituto\tDemo\n ", 'message_whatsapp');
        $result = template_mapper::map($this->eventdata(['subject' => 'Aviso']));
        $this->assertSame('Instituto Demo', $result->params[0]);

        set_config('sitename_short', str_repeat('Instituto ', 12), 'message_whatsapp');
        $result = template_mapper::map($this->eventdata(['subject' => 'Aviso']));
        $this->assertSame(template_mapper::MAX_SITE, \core_text::strlen($result->params[0]));
        $this->assertStringEndsWith('…', $result->params[0]);
    }

    /**
     * Without the setting, the short name of the site itself is used.
     */
    public function test_site_name_falls_back_to_the_site_shortname(): void {
        global $SITE;

        $this->resetAfterTest();

        $expected = sanitizer::for_param($SITE->shortname, template_mapper::MAX_SITE, FORMAT_PLAIN);

        // Never configured, and configured as an empty string, have to behave the same way.
        $result = template_mapper::map($this->eventdata(['subject' => 'Aviso']));
        $this->assertSame($expected, $result->params[0]);

        set_config('sitename_short', '   ', 'message_whatsapp');
        $result = template_mapper::map($this->eventdata(['subject' => 'Aviso']));
        $this->assertSame($expected, $result->params[0]);
    }

    /**
     * Languages of the recipient and of the site, and the template version each one has to get.
     *
     * @return array[] Cases of [user language, site language, expected template language].
     */
    public static function language_provider(): array {
        return [
            'recipient in spanish' => ['es', 'en', template_mapper::LANG_ES],
            'recipient in argentinian spanish' => ['es_ar', 'en', template_mapper::LANG_ES],
            'recipient in mexican spanish' => ['es_mx', 'en', template_mapper::LANG_ES],
            'recipient in english' => ['en', 'es', template_mapper::LANG_EN],
            'recipient in american english' => ['en_us', 'es', template_mapper::LANG_EN],
            'recipient in a language with no template' => ['de', 'es', template_mapper::LANG_EN],
            'no recipient language, spanish site' => ['', 'es', template_mapper::LANG_ES],
            'no recipient language, english site' => ['', 'en', template_mapper::LANG_EN],
            'no language anywhere' => ['', '', template_mapper::LANG_EN],
        ];
    }

    /**
     * The template version follows the language of the recipient, and only then that of the site.
     *
     * @dataProvider language_provider
     * @param string $userlang Language of the recipient.
     * @param string $sitelang Language of the site.
     * @param string $expected Expected language of the template.
     */
    public function test_language(string $userlang, string $sitelang, string $expected): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->lang = $sitelang;

        $result = template_mapper::map($this->eventdata([
            'subject' => 'Aviso',
            'userto' => (object) ['id' => 7, 'lang' => $userlang],
        ]));

        $this->assertSame($expected, $result->lang);
    }

    /**
     * A recipient that is missing, or is not an object, does not break the mapping.
     */
    public function test_language_without_a_usable_recipient(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->lang = 'es';

        $this->assertSame(template_mapper::LANG_ES, template_mapper::map($this->eventdata([]))->lang);
        $this->assertSame(
            template_mapper::LANG_ES,
            template_mapper::map($this->eventdata(['userto' => 7]))->lang
        );
    }

    /**
     * An event data with nothing in it still produces a mapping that Meta would accept.
     *
     * Another plugin can strip the event data through `pre_processor_message_send`, and this runs inside the web
     * request of whoever triggered the event, so an exception here would break an unrelated page.
     */
    public function test_map_of_an_empty_eventdata(): void {
        global $CFG;

        $this->resetAfterTest();

        $result = template_mapper::map(new \stdClass());

        $this->assert_common_invariants($result);
        $this->assertSame(template_mapper::FALLBACK, $result->params[1]);
        $this->assertSame(template_mapper::FALLBACK, $result->params[2]);
        $this->assertSame($CFG->wwwroot, $result->url);
    }

    /**
     * With no subject, the name of the context names the notification, and only then the body.
     */
    public function test_subject_falls_back_to_the_context_name(): void {
        $this->resetAfterTest();

        $result = template_mapper::map($this->eventdata([
            'subject' => '',
            'contexturlname' => 'Ensayo final',
            'fullmessage' => 'Se ha calificado tu entrega.',
            'fullmessageformat' => FORMAT_PLAIN,
        ]));

        $this->assertSame('Ensayo final', $result->params[1]);
        $this->assertSame('Se ha calificado tu entrega.', $result->params[2]);
    }

    /**
     * When the only text of the message is already the subject, the summary is not a copy of it.
     */
    public function test_summary_never_repeats_the_subject(): void {
        $this->resetAfterTest();

        $result = template_mapper::map($this->eventdata([
            'subject' => 'Ensayo final: comentarios de la entrega',
            'smallmessage' => 'ENSAYO FINAL: COMENTARIOS DE LA ENTREGA',
            'fullmessage' => '',
            'fullmessagehtml' => '',
        ]));

        $this->assertSame('Ensayo final: comentarios de la entrega', $result->params[1]);
        $this->assertSame(template_mapper::FALLBACK, $result->params[2]);
    }

    /**
     * A long subject and a long summary of the same text are compared before being cut, not after.
     *
     * Both are the same content, so the summary has to move on to the next source even though the subject was cut
     * at 60 characters and the summary would have been cut at 160.
     */
    public function test_summary_detects_a_duplicate_that_was_cut_at_a_different_length(): void {
        $this->resetAfterTest();

        $long = 'Ensayo final de la materia Introduccion a la programacion: se han publicado los comentarios ' .
            'de la entrega y la calificacion provisional';

        $result = template_mapper::map($this->eventdata([
            'subject' => $long,
            'smallmessage' => $long,
            'fullmessage' => 'Revisa la entrega en el sitio.',
            'fullmessageformat' => FORMAT_PLAIN,
        ]));

        $this->assertStringEndsWith('…', $result->params[1]);
        $this->assertSame('Revisa la entrega en el sitio.', $result->params[2]);
    }

    /**
     * Markup that a provider left inside smallmessage is stripped, not sent as it is.
     *
     * Core declares no format for `smallmessage` and several providers build it out of a language string that
     * interpolates content of the activity, so it is read as HTML.
     */
    public function test_markup_inside_smallmessage_is_stripped(): void {
        $this->resetAfterTest();

        $result = template_mapper::map($this->eventdata([
            'subject' => 'Novedades',
            'smallmessage' => '<p>Juan escribio: <strong>ya esta</strong> la nota &amp; el detalle</p>',
        ]));

        $this->assertSame('Juan escribio: YA ESTA la nota & el detalle', $result->params[2]);
    }

    /**
     * The HTML body is preferred over the plain one, which providers leave as a degraded copy.
     */
    public function test_body_prefers_the_html_version(): void {
        $this->resetAfterTest();

        $result = template_mapper::map($this->eventdata([
            'subject' => 'Novedades',
            'fullmessage' => 'version degradada',
            'fullmessageformat' => FORMAT_PLAIN,
            'fullmessagehtml' => '<p>version completa</p>',
        ]));

        $this->assertSame('version completa', $result->params[2]);
    }

    /**
     * A body with no format declared is read as HTML, so markup never reaches the phone.
     */
    public function test_body_without_a_declared_format_is_read_as_html(): void {
        $this->resetAfterTest();

        $result = template_mapper::map($this->eventdata([
            'subject' => 'Novedades',
            'fullmessage' => '<p>sin formato declarado</p>',
        ]));

        $this->assertSame('sin formato declarado', $result->params[2]);
    }

    /**
     * Long, dirty content is cut and cleaned: no parameter is over the limit or carries a forbidden character.
     */
    public function test_params_respect_the_limits_and_the_forbidden_characters(): void {
        $this->resetAfterTest();

        set_config(
            'sitename_short',
            "Instituto\n\tDemo    de    Prueba de un nombre demasiado largo para caber",
            'message_whatsapp'
        );

        $eventdata = $this->eventdata([
            'component' => 'mod_forum',
            'name' => 'posts',
            'userto' => (object) ['id' => 7, 'lang' => 'es'],
            'subject' => "Curso de verano\t2026: " . str_repeat('novedad muy importante ', 10),
            'fullmessage' => '',
            'fullmessageformat' => FORMAT_HTML,
            'fullmessagehtml' => '<h1>Aviso&nbsp;importante</h1><ul><li>Primero</li><li>Segundo</li></ul>' .
                '<p>' . str_repeat('texto largo que no entra en el parametro. ', 20) . '</p>',
            'smallmessage' => '',
            'contexturl' => 'https://ejemplo.org/mod/forum/discuss.php?d=12',
        ]);

        $result = template_mapper::map($eventdata);

        $this->assert_common_invariants($result);
        foreach ($result->params as $param) {
            // Every one of the three sources was longer than its budget, so every one had to be cut.
            $this->assertStringEndsWith('…', $param);
        }
        $this->assertStringStartsWith('Curso de verano 2026:', $result->params[1]);
        $this->assertStringStartsWith('AVISO IMPORTANTE', $result->params[2]);
    }

    /**
     * The mapping is a value object with the shape the queue and the transports expect.
     */
    public function test_mapped_is_a_value_object_ready_for_the_queue(): void {
        $this->resetAfterTest();
        set_config('sitename_short', 'Instituto Demo', 'message_whatsapp');

        $result = template_mapper::map($this->eventdata([
            'subject' => 'Ensayo final',
            'smallmessage' => 'Se publico la nota: 9/10',
            'userto' => (object) ['id' => 7, 'lang' => 'es'],
            'contexturl' => 'https://ejemplo.org/mod/assign/view.php?id=7',
        ]));

        $this->assertInstanceOf(mapped::class, $result);
        $this->assertSame(
            '["Instituto Demo","Ensayo final","Se publico la nota: 9\/10"]',
            json_encode($result->params)
        );
        $this->assertSame(
            '["Instituto Demo","Ensayo final","Se publico la nota: 9/10"]',
            $result->params_json()
        );
        $this->assertSame($result->params, json_decode($result->params_json(), true));
    }

    /**
     * Accented content survives the round trip to JSON without being escaped into unreadable rows.
     */
    public function test_params_json_keeps_unicode_readable(): void {
        $this->resetAfterTest();
        set_config('sitename_short', 'Instituto Ñandú', 'message_whatsapp');

        $result = template_mapper::map($this->eventdata(['subject' => 'Calificación']));

        $this->assertStringContainsString('Instituto Ñandú', $result->params_json());
        $this->assertStringContainsString('Calificación', $result->params_json());
    }
}
