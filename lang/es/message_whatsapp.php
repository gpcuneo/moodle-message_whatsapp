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
 * Strings for component 'message_whatsapp', language 'es'.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['defaultcountry'] = 'País por defecto';
$string['defaultcountry_desc'] = 'País cuyas reglas de discado se aplican a un número escrito sin prefijo internacional. Un número que ya empieza con + no se ve afectado por esta opción.';
$string['generalsettings'] = 'Notificaciones por WhatsApp';
$string['generalsettings_desc'] = 'Envía las notificaciones de Moodle a WhatsApp como plantillas aprobadas, con una cola.';
$string['mode'] = 'Modo de envío';
$string['mode_desc'] = 'Cómo llega este sitio a WhatsApp. No se envía nada hasta elegir y configurar un modo.';
$string['modedirect'] = 'Directo (credenciales propias de Meta Cloud API)';
$string['modegateway'] = 'Gateway (servicio gateway de WhatsApp)';
$string['phonesource'] = 'Campo del teléfono';
$string['phonesource_desc'] = 'Campo del perfil del que se toma el teléfono de un usuario la primera vez que el sitio lo consulta. El usuario siempre puede corregirlo en sus preferencias de notificación.';
$string['phonesourcenone'] = 'Ninguno, lo escribe el usuario';
$string['phonesourcephone1'] = 'Teléfono (phone1)';
$string['phonesourcephone2'] = 'Teléfono móvil (phone2)';
$string['pluginname'] = 'WhatsApp';
$string['prefnophone'] = 'No se encontró ningún teléfono en tu perfil. Escribí acá tu celular para recibir notificaciones por WhatsApp.';
$string['prefoptin'] = 'Enviar mis notificaciones a WhatsApp';
$string['prefoptin_desc'] = 'No se envía nada a WhatsApp hasta que marques esta casilla. Desmarcala cuando quieras para dejar de recibir notificaciones ahí.';
$string['prefphone'] = 'Teléfono de WhatsApp';
$string['prefphone_desc'] = 'Celular en formato internacional, por ejemplo +54 9 11 1234-5678. Dejalo vacío para borrarlo.';
$string['prefphoneinvalid'] = 'El último número que escribiste no se entendió y no se guardó. Escribilo con el código de área, por ejemplo 011 15 1234-5678.';
$string['prefphonelandline'] = 'Este número parece un teléfono fijo, y WhatsApp no puede entregar a un fijo. Si es un celular, escribilo de nuevo con el 15 después del código de área, como 011 15 1234-5678, o en formato internacional con el 9, como +54 9 11 1234-5678.';
$string['privacy:metadata'] = 'El procesador de mensajes de WhatsApp no almacena ningún dato personal.';
$string['sitenameshort'] = 'Nombre corto del sitio';
$string['sitenameshort_desc'] = 'Nombre de este sitio tal como aparece en el mensaje de WhatsApp. Dejalo vacío para usar el nombre corto del sitio.';
$string['task:cleanup'] = 'Borrar las entradas viejas de la cola de WhatsApp';
$string['task:sendqueue'] = 'Enviar los mensajes de WhatsApp pendientes';
$string['task:syncstatus'] = 'Traer del gateway los estados de entrega de WhatsApp';
$string['whatsapp:managesettings'] = 'Configurar el canal de notificaciones de WhatsApp';
$string['whatsapp:optinusers'] = 'Dar el consentimiento de WhatsApp en nombre de otros usuarios';
$string['whatsapp:viewlog'] = 'Ver el reporte de envíos de WhatsApp';
