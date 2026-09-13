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

$string['batchsize'] = 'Mensajes por corrida';
$string['batchsize_desc'] = 'Cuántos mensajes de la cola toma la tarea de envío en cada corrida. La tarea corre cada minuto, así que 100 son 6000 mensajes por hora. Subilo sólo si la cola se atrasa: un lote más grande hace la corrida más larga, y una corrida que no termina dentro de su minuto es peor que una cola atrasada un minuto.';
$string['dailycap'] = 'Tope diario por usuario';
$string['dailycap_desc'] = 'Cantidad máxima de mensajes de WhatsApp que puede recibir un mismo usuario en un día, contado en la zona horaria del sitio. Lo que pasa del tope queda registrado como omitido y no se envía nunca, así que el tope cambia notificaciones por costo: dejalo en 0 para no poner límite.';
$string['datasettings'] = 'Retención de datos';
$string['datasettings_desc'] = 'Cuánto tiempo se guarda en este sitio el registro de lo que se envió. No cambia nada de lo que guarda WhatsApp.';
$string['defaultcountry'] = 'País por defecto';
$string['defaultcountry_desc'] = 'País cuyas reglas de discado se aplican a un número escrito sin prefijo internacional. Un número que ya empieza con + no se ve afectado por esta opción.';
$string['deliverysettings'] = 'Envío';
$string['deliverysettings_desc'] = 'Cuándo puede enviar la cola y cuánto puede enviar. Nada de esto cambia qué se envía, solo cuándo.';
$string['generalsettings'] = 'Notificaciones por WhatsApp';
$string['generalsettings_desc'] = 'Envía las notificaciones de Moodle a WhatsApp como plantillas aprobadas, con una cola.';
$string['graphversion'] = 'Versión de la Graph API';
$string['graphversion_desc'] = 'Versión de la Graph API de Meta contra la que se hacen los pedidos, escrita como v25.0. Dejala vacía para usar la versión con la que se escribió este plugin. Subila sólo después de leer el changelog de Meta: una versión vieja sigue funcionando hasta que Meta la da de baja.';
$string['logattempts'] = 'Intentos';
$string['logcomponent'] = 'Componente';
$string['logentity'] = 'Mensaje de WhatsApp';
$string['logerror'] = 'Último error';
$string['logintro'] = 'Todas las notificaciones que este sitio puso en la cola de WhatsApp y qué pasó con cada una. Un mensaje que falló se puede volver a encolar desde el menú del final de su fila; nada más en esta página modifica nada.';
$string['lognoresults'] = 'Todavía no se encoló nada para WhatsApp en este sitio.';
$string['logpage'] = 'Reporte de envíos de WhatsApp';
$string['logpage_desc'] = 'Mirá qué se encoló para WhatsApp y qué pasó con cada mensaje, y volvé a encolar los que fallaron, en el <a href="{$a}">reporte de envíos de WhatsApp</a>.';
$string['logqueued'] = 'Encolado';
$string['logreasondailycap'] = 'No se envió: cuando surgió la notificación ya se había alcanzado el tope diario de este usuario.';
$string['logreasoninvalidphone'] = 'No se envió: el proveedor no entrega al número registrado para este usuario, así que no se le manda nada más hasta que guarde otro.';
$string['logreasonnooptin'] = 'No se envió: este usuario no consintió recibir notificaciones por WhatsApp.';
$string['logreasonorphaned'] = 'La corrida de envío se cortó después de que el mensaje salió de este sitio, así que no se sabe qué hizo WhatsApp con él.';
$string['logrecipient'] = 'Destinatario';
$string['logstatus'] = 'Estado';
$string['logstatusdelivered'] = 'Entregado';
$string['logstatusfailed'] = 'Falló';
$string['logstatuspending'] = 'En espera';
$string['logstatusread'] = 'Leído';
$string['logstatussending'] = 'Enviando';
$string['logstatussent'] = 'Enviado';
$string['logstatusskipped'] = 'Omitido';
$string['metaappsecret'] = 'App secret';
$string['metaappsecret_desc'] = 'App secret de la app de Meta. No se usa para enviar: es con lo que se verifican los reportes de entrega que llegan al webhook, y un reporte sin firma válida se rechaza.';
$string['metaphoneid'] = 'ID del número de teléfono';
$string['metaphoneid_desc'] = 'Identificador del número de WhatsApp Business desde el que se envía, tal como lo muestra WhatsApp Manager. Es un identificador de dígitos, no el número de teléfono.';
$string['metasettings'] = 'Credenciales de Meta Cloud API';
$string['metasettings_desc'] = 'Credenciales de la app de Meta, que se usan en modo directo. En modo gateway no se usan y el gateway tiene las suyas. Una vez guardados, los secretos no se muestran de nuevo.';
$string['metatoken'] = 'Token de acceso';
$string['metatoken_desc'] = 'Token de acceso permanente del usuario de sistema de la app de Meta, con el permiso whatsapp_business_messaging. Cualquiera que lo tenga puede enviar mensajes facturados a tu cuenta, así que tratalo como una contraseña.';
$string['metaverifytoken'] = 'Token de verificación del webhook';
$string['metaverifytoken_desc'] = 'Una cadena que inventás vos. Meta la manda una sola vez, cuando se crea la suscripción del webhook, y este sitio contesta el desafío sólo si coincide. Sirve cualquier cadena larga y aleatoria.';
$string['metawabaid'] = 'ID de la cuenta de WhatsApp Business';
$string['metawabaid_desc'] = 'Identificador de la cuenta de WhatsApp Business a la que pertenece el número. No se usa para enviar mensajes; nombra a la cuenta dueña de las plantillas aprobadas.';
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
$string['prefphoneundeliverable'] = 'WhatsApp no pudo entregar a este número, así que no se le está enviando nada más. Fijate que sea un celular con WhatsApp instalado y que esté escrito con el 15 después del código de área, como 011 15 1234-5678. Volver a guardarlo reactiva el canal.';
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
$string['recipientsettings'] = 'Destinatarios';
$string['recipientsettings_desc'] = 'De dónde sale el teléfono de un usuario y cómo se lee un número escrito sin prefijo internacional.';
$string['retention'] = 'Conservar los mensajes terminados (días)';
$string['retention_desc'] = 'Días que se conserva una entrada terminada de la cola (enviada, entregada, leída, fallada u omitida) antes de que la tarea de limpieza la borre junto con el registro de sus clics. Las entradas que todavía esperan para salir no las borra nunca. Poné 0 para conservar todo para siempre. El reporte de envíos sólo puede mostrar lo que todavía no se borró.';
$string['retry'] = 'Reintentar';
$string['retryconfirm'] = '¿Volver a encolar la entrada {$a->id} ({$a->component})? Sale en la próxima corrida de la tarea de envío, como un intento nuevo. Si el intento anterior llegó igual a WhatsApp, la persona recibe la notificación dos veces y al sitio se la facturan dos veces.';
$string['retrydeferred'] = 'La entrada {$a->id} quedó encolada otra vez. Las horas de silencio están activas ahora, así que no se enviará antes de {$a->time}.';
$string['retrynotfailed'] = 'La entrada {$a} no se volvió a encolar: sólo se puede reintentar un mensaje fallido, y este ya no lo está. Recargá el reporte para ver cómo quedó.';
$string['retrynotfound'] = 'Esa entrada ya no existe. Puede haberla borrado la tarea de limpieza.';
$string['retryqueued'] = 'La entrada {$a} quedó encolada otra vez. La tarea programada que envía los mensajes pendientes de WhatsApp corre cada minuto.';
$string['sitenameshort'] = 'Nombre corto del sitio';
$string['sitenameshort_desc'] = 'Nombre de este sitio tal como aparece en el mensaje de WhatsApp. Dejalo vacío para usar el nombre corto del sitio.';
$string['statushowmany'] = 'Cuántos';
$string['statusinflight'] = 'Saliendo en este momento';
$string['statusintro'] = 'Lo que hizo el canal de WhatsApp hoy, contado en la zona horaria del sitio, y lo que todavía espera para salir. Nada de esta página cambia nada.';
$string['statuslastrun'] = 'La tarea de envío corrió por última vez';
$string['statusneverrun'] = 'Nunca';
$string['statusnothingwaiting'] = 'No hay nada esperando';
$string['statusoldest'] = 'La entrada más vieja lleva esperando';
$string['statuspage'] = 'Estado del canal de WhatsApp';
$string['statusqueue'] = 'Esperando para salir';
$string['statusstalled'] = 'Hay entradas esperando y la tarea de envío no corrió en los últimos diez minutos. Está programada para correr cada minuto, así que o el cron no está corriendo en este sitio o no está llegando a esta tarea. Hasta que eso se arregle no sale nada.';
$string['statustoday'] = 'Hoy';
$string['statustoreport'] = 'Abrir el reporte de envíos';
$string['statuswaiting'] = 'Esperando para salir';
$string['statuswhat'] = 'Qué';
$string['task:cleanup'] = 'Borrar las entradas viejas de la cola de WhatsApp';
$string['task:sendqueue'] = 'Enviar los mensajes de WhatsApp pendientes';
$string['task:syncstatus'] = 'Traer del gateway los estados de entrega de WhatsApp';
$string['templatename'] = 'Plantilla';
$string['templatename_desc'] = 'Este sitio envía la plantilla <code>{$a->template}</code>, en los idiomas <code>{$a->spanish}</code> e <code>{$a->english}</code>. Creala en WhatsApp Manager con exactamente ese nombre y en los dos idiomas antes de encender el canal: una plantilla que no existe se rechaza una vez por notificación.';
$string['templatesettings'] = 'Plantilla del mensaje';
$string['templatesettings_desc'] = 'Qué se envía. La versión 1 de este plugin manda todas las notificaciones como la misma plantilla aprobada, así que todavía no hay nada para elegir acá.';
$string['testcode_fake_failure'] = 'El transporte en memoria está configurado para rechazar todo, con message_whatsapp_fake_transport en "fail" en config.php. No se le preguntó nada a ningún proveedor.';
$string['testcode_http_error'] = 'El proveedor contestó con un error HTTP y sin diagnóstico propio.';
$string['testcode_internal_error'] = 'El plugin falló antes de poder hacer el pedido. Eso es una falla del plugin y no de la configuración; el detalle de abajo es lo que devolvió.';
$string['testcode_invalid_response'] = 'Algo contestó, pero no como contesta la API del proveedor. Un proxy, un portal cautivo o un firewall que intercepta HTTPS se ven exactamente así.';
$string['testcode_network_error'] = 'El pedido nunca llegó al proveedor: la conexión fue rechazada, venció el tiempo de espera o no se pudo resolver el nombre. Revisá que este servidor pueda hacer pedidos HTTPS salientes y que, si hay un proxy, Moodle lo tenga configurado.';
$string['testcode_not_available'] = 'El modo de envío elegido es uno que este plugin conoce, pero el código que lo implementa no está instalado en este sitio. Instalá el plugin de nuevo, completo.';
$string['testcode_not_configured'] = 'Todavía no hay nada para probar: o no hay modo de envío elegido, o las credenciales del modo elegido están vacías.';
$string['testcode_unknown'] = 'El transporte informó una falla sin decir cuál, que es algo que no debería pasar.';
$string['testcode_unknown_mode'] = 'El modo de envío guardado en la configuración no es ninguno de los que conoce este plugin. Elegí uno en los ajustes y guardá.';
$string['testcodeother'] = 'El proveedor rechazó el pedido con el código {$a}. Ese código, y no el texto que lo rodea, es lo que hay que buscar en la documentación de errores del proveedor.';
$string['testconnection'] = 'Probar conexión';
$string['testconnection_desc'] = 'Le pregunta al proveedor si estas credenciales podrían enviar en este momento. No se envía nada y no se cobra nada. En modo directo esto hace un pedido real a Meta, así que puede tardar unos segundos.';
$string['testconnectionfailed'] = 'Falló la conexión';
$string['testconnectionok'] = 'Conexión OK';
$string['testconnectionokdetail'] = 'El transporte {$a} contestó y las credenciales que usa son válidas. Esto no dice nada sobre la plantilla, que recién se verifica cuando se envía un mensaje de verdad.';
$string['testcurrentconfig'] = 'Configuración actual';
$string['testerrorcode'] = 'Código de error: {$a}';
$string['testfakewarning'] = 'Este sitio tiene message_whatsapp_fake_transport en config.php, así que el transporte es uno en memoria: nada llega a WhatsApp y nadie recibe nada. Una conexión que acá figura como funcionando no dice nada sobre las credenciales reales.';
$string['testmessagebody'] = 'Notificación de prueba de {$a}. Si te llegó, el canal de WhatsApp funciona. No hay nada que tengas que hacer con ella.';
$string['testmessagesubject'] = 'Prueba de WhatsApp';
$string['testmodenone'] = 'Sin configurar';
$string['testpage'] = 'Probar WhatsApp';
$string['testpage_desc'] = 'Revisá las credenciales y mandate una notificación de prueba desde la <a href="{$a}">página de prueba de WhatsApp</a>.';
$string['testproviderdetail'] = 'El proveedor contestó: {$a}';
$string['testsend'] = 'Enviar una prueba a mi número';
$string['testsend_desc'] = 'Encola una notificación de prueba dirigida a vos. No se manda desde acá: la manda la tarea programada que vacía la cola, dentro del minuto de su próxima corrida, igual que manda una notificación real. Es un mensaje de WhatsApp de verdad y se factura como tal.';
$string['testsendcapped'] = 'La prueba se encoló como la entrada {$a} y el tope diario por usuario la omitió: hoy ya se te encolaron tantos mensajes como permite el tope. No se va a enviar nada.';
$string['testsenddeferred'] = 'La prueba se encoló como la entrada {$a->id}. Las horas de silencio están activas en este momento, así que no va a salir antes de {$a->time}.';
$string['testsendnooptin'] = 'No diste tu consentimiento para recibir notificaciones por WhatsApp, y sin ese consentimiento no se envía nada nunca. Marcá la casilla en tus <a href="{$a}">preferencias de notificación</a> y probá de nuevo.';
$string['testsendnophone'] = 'No hay ningún número de WhatsApp registrado para vos. Escribí el tuyo en tus <a href="{$a}">preferencias de notificación</a> y probá de nuevo.';
$string['testsendnorecipient'] = 'Nunca configuraste las notificaciones de WhatsApp para vos. Abrí tus <a href="{$a}">preferencias de notificación</a>, revisá el número y marcá la casilla de consentimiento, y después probá de nuevo.';
$string['testsendnotactive'] = 'El número registrado para vos figura como inutilizable: o WhatsApp lo rechazó, o se bloquearon los mensajes de este sitio. Corregilo en tus <a href="{$a}">preferencias de notificación</a>.';
$string['testsendnotqueued'] = 'No se pudo encolar la notificación de prueba.';
$string['testsendqueued'] = 'La prueba se encoló como la entrada {$a}. La tarea programada que envía los mensajes pendientes de WhatsApp la va a mandar en la próxima corrida de cron, normalmente dentro del minuto.';
$string['testsendskipped'] = 'La prueba se encoló como la entrada {$a} y la cola la omitió de entrada. No se va a enviar nada.';
$string['testtransport'] = 'Transporte en uso';
$string['webhookurl'] = 'URL del webhook';
$string['webhookurl_desc'] = 'Dirección para pegar en la configuración del webhook de la app de Meta, suscripta al campo "messages". Es adonde vuelven los reportes de entrega: <code>{$a}</code>';
$string['whatsapp:managesettings'] = 'Configurar el canal de notificaciones de WhatsApp';
$string['whatsapp:optinusers'] = 'Dar el consentimiento de WhatsApp en nombre de otros usuarios';
$string['whatsapp:viewlog'] = 'Ver el reporte de envíos de WhatsApp';
