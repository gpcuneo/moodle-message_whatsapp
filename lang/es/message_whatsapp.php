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

$string['dailycap'] = 'Tope diario por usuario';
$string['dailycap_desc'] = 'Cantidad máxima de mensajes de WhatsApp que puede recibir un mismo usuario en un día, contado en la zona horaria del sitio. Lo que pasa del tope queda registrado como omitido y no se envía nunca, así que el tope cambia notificaciones por costo: dejalo en 0 para no poner límite.';
$string['defaultcountry'] = 'País por defecto';
$string['defaultcountry_desc'] = 'País cuyas reglas de discado se aplican a un número escrito sin prefijo internacional. Un número que ya empieza con + no se ve afectado por esta opción.';
$string['deliverysettings'] = 'Envío';
$string['deliverysettings_desc'] = 'Cuándo puede enviar la cola y cuánto puede enviar. Nada de esto cambia qué se envía, solo cuándo.';
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
$string['privacy:metadata:message_whatsapp_click'] = 'Una fila por cada vez que un usuario siguió el enlace del botón de un mensaje de WhatsApp que se le había enviado.';
$string['privacy:metadata:message_whatsapp_click:queueid'] = 'El mensaje de la cola cuyo botón se tocó. Un clic no tiene usuario propio: es de la persona a la que se le envió ese mensaje.';
$string['privacy:metadata:message_whatsapp_click:timeclicked'] = 'Momento en que se tocó el botón.';
$string['privacy:metadata:message_whatsapp_click:useragent'] = 'Navegador y dispositivo que siguieron el enlace, tal como se identificaron.';
$string['privacy:metadata:message_whatsapp_queue'] = 'Una fila por cada notificación encolada para WhatsApp, incluidas las que nunca se enviaron y el motivo por el que no se enviaron.';
$string['privacy:metadata:message_whatsapp_queue:attempts'] = 'Cuántos intentos de envío se hicieron.';
$string['privacy:metadata:message_whatsapp_queue:component'] = 'Parte de Moodle de la que salió la notificación, por ejemplo mod_assign.';
$string['privacy:metadata:message_whatsapp_queue:courseid'] = 'Curso del que salió la notificación, o 0 si fue de todo el sitio. Registra de dónde salió el mensaje; el mensaje en sí es de la persona a la que se le envió.';
$string['privacy:metadata:message_whatsapp_queue:error'] = 'Último error de envío, si hubo alguno.';
$string['privacy:metadata:message_whatsapp_queue:lang'] = 'Versión de idioma de la plantilla que se usó.';
$string['privacy:metadata:message_whatsapp_queue:name'] = 'Tipo de notificación dentro de esa parte de Moodle, por ejemplo assign_notification.';
$string['privacy:metadata:message_whatsapp_queue:nextattempt'] = 'Momento a partir del cual se puede volver a intentar el envío, que usan los reintentos y el horario de silencio.';
$string['privacy:metadata:message_whatsapp_queue:params'] = 'Texto que se puso en la plantilla: el nombre corto del sitio, el asunto de la notificación y su resumen.';
$string['privacy:metadata:message_whatsapp_queue:phone'] = 'Número al que se dirigió el mensaje, copiado al encolarlo para que un cambio posterior de número no reescriba lo que ya se envió.';
$string['privacy:metadata:message_whatsapp_queue:pricingcategory'] = 'Categoría de facturación que informó WhatsApp para el mensaje.';
$string['privacy:metadata:message_whatsapp_queue:providermsgid'] = 'Identificador que le dio WhatsApp al mensaje, que sirve para casar los informes de entrega que vuelven.';
$string['privacy:metadata:message_whatsapp_queue:savedmessageid'] = 'Identificador de la notificación tal como la guardó Moodle, cuando Moodle guardó una.';
$string['privacy:metadata:message_whatsapp_queue:status'] = 'Si el mensaje está esperando, se envió, se entregó, se leyó, falló o se omitió.';
$string['privacy:metadata:message_whatsapp_queue:templatekey'] = 'Nombre de la plantilla aprobada de WhatsApp con la que se envió el mensaje.';
$string['privacy:metadata:message_whatsapp_queue:timecreated'] = 'Momento en que el mensaje entró en la cola.';
$string['privacy:metadata:message_whatsapp_queue:timesent'] = 'Momento en que WhatsApp aceptó el mensaje.';
$string['privacy:metadata:message_whatsapp_queue:timestatus'] = 'Momento del último cambio de estado de entrega.';
$string['privacy:metadata:message_whatsapp_queue:url'] = 'Dirección a la que apunta el botón del mensaje, normalmente la página del sitio de la que habla la notificación.';
$string['privacy:metadata:message_whatsapp_queue:userid'] = 'Usuario al que se dirigió la notificación.';
$string['privacy:metadata:message_whatsapp_user'] = 'El número de WhatsApp de cada usuario y su consentimiento para recibir notificaciones ahí. Sin una fila que dé ese consentimiento no se le envía nunca nada a ese usuario.';
$string['privacy:metadata:message_whatsapp_user:optin'] = 'Si el usuario dio su consentimiento para recibir sus notificaciones por WhatsApp.';
$string['privacy:metadata:message_whatsapp_user:optintime'] = 'Momento en que el consentimiento se dio o se retiró por última vez, que se guarda como evidencia de ese consentimiento.';
$string['privacy:metadata:message_whatsapp_user:phone'] = 'Número de teléfono en formato internacional, vacío si no se pudo deducir ninguno.';
$string['privacy:metadata:message_whatsapp_user:source'] = 'De dónde salió el número: un campo del perfil, un campo de perfil personalizado, o escrito por el usuario.';
$string['privacy:metadata:message_whatsapp_user:status'] = 'Si el número es usable, si se rechazó por inválido, o si el usuario bloqueó al remitente.';
$string['privacy:metadata:message_whatsapp_user:timecreated'] = 'Momento en que se registró el número por primera vez.';
$string['privacy:metadata:message_whatsapp_user:timemodified'] = 'Momento del último cambio del número o del consentimiento.';
$string['privacy:metadata:message_whatsapp_user:userid'] = 'Usuario al que pertenecen el número y el consentimiento.';
$string['privacy:metadata:message_whatsapp_user:verified'] = 'Si el número pasó la verificación por código.';
$string['privacy:metadata:message_whatsapp_user:verifiedtime'] = 'Momento en que se verificó el número.';
$string['privacy:metadata:meta_cloud_api'] = 'En modo directo este sitio le envía cada notificación a la WhatsApp Cloud API de Meta, que la entrega en el teléfono del usuario. Del sitio solo salen los campos que se listan abajo, y Meta guarda el mensaje y su estado de entrega bajo sus propias condiciones.';
$string['privacy:metadata:meta_cloud_api:lang'] = 'Versión de idioma de esa plantilla.';
$string['privacy:metadata:meta_cloud_api:params'] = 'Texto que se pone en la plantilla: el nombre corto del sitio, el asunto de la notificación y su resumen.';
$string['privacy:metadata:meta_cloud_api:phone'] = 'Número de teléfono al que se entrega el mensaje.';
$string['privacy:metadata:meta_cloud_api:templatekey'] = 'Nombre de la plantilla aprobada con la que se envía el mensaje.';
$string['privacy:metadata:meta_cloud_api:url'] = 'Dirección a la que apunta el botón del mensaje.';
$string['privacy:metadata:wa_gateway'] = 'En modo gateway este sitio le envía cada notificación al servicio gateway de WhatsApp, que la retransmite a Meta en nombre del sitio e informa de vuelta el estado de entrega. Del sitio salen los mismos campos, más el número del mensaje en la cola.';
$string['privacy:metadata:wa_gateway:lang'] = 'Versión de idioma de esa plantilla.';
$string['privacy:metadata:wa_gateway:params'] = 'Texto que se pone en la plantilla: el nombre corto del sitio, el asunto de la notificación y su resumen.';
$string['privacy:metadata:wa_gateway:phone'] = 'Número de teléfono al que se entrega el mensaje.';
$string['privacy:metadata:wa_gateway:queueid'] = 'Número del mensaje en la cola, que se envía como clave de idempotencia para que un pedido repetido tras una falla de red no se entregue dos veces.';
$string['privacy:metadata:wa_gateway:templatekey'] = 'Nombre de la plantilla aprobada con la que se envía el mensaje.';
$string['privacy:metadata:wa_gateway:url'] = 'Dirección a la que apunta el botón del mensaje.';
$string['privacy:path'] = 'Notificaciones de WhatsApp';
$string['privacy:path:messages'] = 'Mensajes';
$string['quietend'] = 'Fin del horario de silencio';
$string['quietend_desc'] = 'Hora a partir de la cual se vuelve a enviar, en la zona horaria del sitio.';
$string['quiethours'] = 'Respetar el horario de silencio';
$string['quiethours_desc'] = 'Retiene los mensajes que surgen durante la noche y los envía cuando termina el horario de silencio. No se descarta nada: una notificación alcanzada por la franja sale apenas la franja termina.';
$string['quietstart'] = 'Inicio del horario de silencio';
$string['quietstart_desc'] = 'Hora a partir de la cual se deja de enviar, en la zona horaria del sitio. Un inicio y un fin en la misma hora significan que no hay horario de silencio.';
$string['sitenameshort'] = 'Nombre corto del sitio';
$string['sitenameshort_desc'] = 'Nombre de este sitio tal como aparece en el mensaje de WhatsApp. Dejalo vacío para usar el nombre corto del sitio.';
$string['task:cleanup'] = 'Borrar las entradas viejas de la cola de WhatsApp';
$string['task:sendqueue'] = 'Enviar los mensajes de WhatsApp pendientes';
$string['task:syncstatus'] = 'Traer del gateway los estados de entrega de WhatsApp';
$string['whatsapp:managesettings'] = 'Configurar el canal de notificaciones de WhatsApp';
$string['whatsapp:optinusers'] = 'Dar el consentimiento de WhatsApp en nombre de otros usuarios';
$string['whatsapp:viewlog'] = 'Ver el reporte de envíos de WhatsApp';
