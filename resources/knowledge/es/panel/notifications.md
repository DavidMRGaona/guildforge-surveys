---
id: surveys.panel.notifications
title: Avisos y correos de las encuestas
summary: Qué correos envían las encuestas, a quién y cuándo, desde la invitación hasta el resumen al cerrar.
category: content
audience: panel
order: 68
keywords: [correo, correos, email, aviso, avisos, notificación, invitación, recordatorio, resumen, resumen al cerrar, rechazo, aprobada, fecha elegida, anuncio, canales, no me llega]
related: [surveys.panel.open-share-close, surveys.panel.review, surveys.panel.settings]
conditions:
  permissions:
    any: ['surveys:surveys.manage', 'surveys:surveys.moderate']
---
Las encuestas avisan por correo en cada paso importante. Esta tabla resume todos; ninguno lleva respuestas de nadie.

| Correo | Lo recibe | Cuándo |
|---|---|---|
| **Nueva encuesta** | Su audiencia (y los invitados sin cuenta de una actividad, con su enlace personal) | Al abrirse |
| **Tu enlace para responder** | Quien pide responder con un enlace público de **Una respuesta por correo** | Al pedirlo |
| **Recordatorio** | Quien aún no ha respondido | Las horas antes del cierre que marque **Recordatorio** |
| **¿Quieres enviar la encuesta de…?** | Quien organiza la actividad | Al terminar la actividad, si su encuesta sale con un clic |
| **La encuesta de… se enviará pronto** | Quien organiza la actividad | Al terminar la actividad, si su encuesta sale sola, con la fecha |
| **La encuesta de… no se enviará** | Quien organiza la actividad | Si la actividad se cancela antes de terminar |
| **Encuesta pendiente de revisión** | Quien puede revisar encuestas | Cuando una encuesta cambiada en la web espera revisión |
| **Encuesta aprobada** o **rechazada** | Quien la organiza | Al revisarla (el rechazo lleva el motivo) |
| **Resultados de la encuesta** | Quienes organizan y quien la creó (y, si los ajustes lo piden, quien puede ver resultados) | Al cerrarse, si tiene **Resumen por correo al cerrar** |
| **Fecha elegida** | Quienes recibieron el sondeo de disponibilidad o lo respondieron con su cuenta | Al fijar la fecha |

## Qué correos puede dejar de recibir cada persona

Cada persona elige en la pestaña **Correos** de su perfil (sección **Encuestas**) qué correos optativos recibe, y cada correo trae un enlace para darse de baja de su categoría. Los ajustes de la encuesta y del módulo siguen mandando: si algo está apagado ahí, no sale aunque la persona lo tenga activado.

| Categoría | Correos | ¿Se puede desactivar? |
|---|---|---|
| **Invitaciones y recordatorios** | Nueva encuesta y recordatorio | Sí |
| **Resultados al cerrar** | Resumen de resultados | Sí |
| **Fechas acordadas** | Fecha elegida | No: cambia los planes |
| **Encuestas que organizo: acciones pendientes** | Enviar la encuesta, rechazo de la revisión | No: piden una acción |
| **Encuestas que organizo: avisos** | Programada, aprobada y retirada | Sí |
| **Encuestas pendientes de revisión** | Aviso a quien revisa (solo la ve quien puede revisar) | Sí |
| **Enlace para responder** | Tu enlace personal | No: lo ha pedido la persona |

Quien responde sin cuenta, con su enlace personal, sigue recibiéndolo siempre: no hay perfil donde elegir. Estas elecciones no se relacionan con ninguna respuesta, tampoco en las encuestas anónimas.

## El resumen al cerrar

Lleva la tasa de respuesta, la opción más votada de cada pregunta de opciones, la media de cada escala y de cada valoración con estrellas, y cuántas preguntas más hay en los resultados completos, con el enlace para verlos. Nunca lleva texto libre. Respeta las preguntas ocultas y el mínimo de respuestas: si una encuesta anónima no llega al mínimo, el correo lo dice sin dar ninguna cifra.

## Anuncio en canales

Si tu web tiene el módulo de notificaciones a canales, una encuesta general con **Anunciar en los canales** se publica al abrirse en los canales configurados (Telegram, Discord, Slack o WhatsApp), con el título, el cierre y el enlace. No es un correo y solo se anuncia la primera apertura.

## Si algo no sale bien

- **A alguien no le llega la invitación**: comprueba que estaba en la audiencia cuando se abrió la encuesta; quien entra después en un rol no la recibe. Revisa también la carpeta de correo no deseado.
- **No llegan los recordatorios**: la encuesta necesita un valor en **Recordatorio** y una fecha de **Cierre**.
- **No ha llegado el resumen**: se envía al cerrarse, solo si la encuesta tenía **Resumen por correo al cerrar** marcado en ese momento.
