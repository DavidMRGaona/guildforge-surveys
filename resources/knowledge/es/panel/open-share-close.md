---
id: surveys.panel.open-share-close
title: Abrir, compartir y cerrar una encuesta
summary: Elige a quién va una encuesta y cuánto dura, ábrela, comparte su enlace público, ciérrala, repítela o bórrala.
category: content
audience: panel
order: 66
keywords: [abrir encuesta, enviar encuesta, lanzar, publicar encuesta, audiencia, a quién va, socios, roles, participantes, enlace público, compartir, copiar enlace, cambiar enlace, sin cuenta, recordatorio, cierre, cerrar encuesta, duplicar, repetir, borrar encuesta, anunciar, canales, telegram, discord]
related: [surveys.panel.create-survey, surveys.panel.notifications, surveys.panel.anonymity, surveys.panel.results]
conditions:
  permissions:
    all: ['surveys:surveys.manage']
---
Una encuesta en borrador no la recibe nadie. Al abrirla empieza a admitir respuestas, su audiencia queda fijada y se le avisa por correo. Todo se prepara en la ficha de la encuesta, en estos bloques.

## A quién va dirigida

En **Audiencia** eliges quién la recibe y puede responderla con su cuenta:

- **Todas las personas con cuenta**.
- **Solo socios**: quienes tienen el rol de {{association_role}}.
- **Algunos roles**: eliges los **Roles** en la lista. Recibe la encuesta quien tenga cualquiera de ellos.
- **Participantes**, con el tipo entre paréntesis (por ejemplo, «Participantes (Evento)»): en las encuestas de una mesa, un torneo o un evento, quienes participaron. Lo decide el módulo de la actividad (por ejemplo, las personas inscritas y confirmadas). Si entre ellas hay invitados sin cuenta, les llega un enlace personal por correo. Esta audiencia es la única posible en esas encuestas, así que se muestra bloqueada, y las encuestas generales no pueden elegirla.

La audiencia se fija al abrir: quien entre después en un rol no la recibe. Si necesitas llegar a más gente, usa el enlace público.

## Plazo

- **Cierre**: cuándo deja de admitir respuestas. Por defecto, los días que marquen los ajustes.
- **Recordatorio**: cuántas horas antes del cierre se recuerda a quien aún no ha respondido. Vacío, no hay recordatorio.

## Resultados

- **Resultados en directo**: los resultados se ven mientras está abierta. En una encuesta anónima, sin esto los resultados esperan al cierre.
- **Quien responde ve los resultados**: además de quien la gestiona, los ve quien la ha respondido.
- **Resumen por correo al cerrar**: al cerrarse, quienes la organizan y quien la creó reciben un resumen (mira la guía de avisos y correos).

## Enlace público

Con **Enlace público** activado, se puede responder sin cuenta desde un enlace que compartes donde quieras (un grupo de mensajería, un cartel con código QR…). En **Quién responde con el enlace** eliges:

- **Una respuesta por correo** (recomendado): pide un correo y envía a esa dirección un enlace de un solo uso, así cada dirección responde una vez. Las direcciones se guardan cifradas y se borran al cerrar la encuesta.
- **Abierto**: cualquiera con el enlace responde, sin dar nada y tantas veces como quiera. Cómodo, pero no impide votar varias veces.

Mientras la encuesta admite respuestas, su ficha muestra el **Enlace para compartir** con **Copiar enlace**. Si el enlace ha llegado donde no debía, pulsa **Cambiar el enlace**, marca la casilla y confirma: el anterior deja de funcionar al momento, y las respuestas y los enlaces personales ya enviados se conservan.

## Abrir

1. Revisa la encuesta y pulsa **Abrir**.
2. Confirma. La encuesta pasa a **Abiertas** y se envía el correo a su audiencia.

Una encuesta general (sin mesa, torneo ni evento) también puede anunciarse en los canales de mensajería de la asociación con **Anunciar en los canales**, si tu web tiene instalado el módulo de notificaciones a canales. Se marca mientras es un borrador y solo se anuncia la primera apertura.

## Cerrar

La encuesta se cierra sola en su fecha de **Cierre**. Para cerrarla antes, pulsa **Cerrar** y confirma. Una encuesta cerrada no se puede volver a abrir; si hace falta, duplícala.

## Encontrar, repetir y borrar

- La lista se agrupa en **Abiertas**, **Pendientes de revisión**, **Borradores**, **Cerradas** y **Todas**. La columna **De** dice de qué mesa, torneo o evento es cada una, o **General**.
- **Duplicar** crea un borrador con las mismas preguntas y ajustes, sin respuestas, sin audiencia fijada y con un enlace público nuevo. Una encuesta de una actividad se copia para la misma actividad.
- **Guardar como plantilla** crea una plantilla con sus preguntas: escribe el nombre y pulsa **Crear plantilla**.
- **Borrar** elimina la encuesta, todas sus respuestas y sus archivos: marca la casilla para confirmarlo.

> [!WARNING]
> Borrar una encuesta no se puede deshacer. Si solo quieres que deje de admitir respuestas, ciérrala.

[[action:filament.admin.resources.surveys.index|Ver las encuestas]]
