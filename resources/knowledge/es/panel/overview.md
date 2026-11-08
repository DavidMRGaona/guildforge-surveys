---
id: surveys.panel.overview
title: 'Encuestas: cómo funcionan'
summary: Qué son las encuestas, los sondeos y las plantillas, por qué estados pasa una encuesta y quién puede hacer cada cosa.
category: content
audience: panel
order: 60
featured: true
keywords: [encuestas, encuesta, sondeo, sondeos, cómo funciona, empezar, introducción, conceptos, qué es, estados, borrador, abierta, cerrada, plantilla, audiencia, permisos, mesa, partida, campaña, torneo, evento, sujeto, opinión, valoración]
related: [surveys.panel.create-survey, surveys.panel.question-types, surveys.panel.open-share-close, surveys.panel.anonymity, surveys.panel.end-survey-field]
conditions:
  permissions:
    any: ['surveys:surveys.manage', 'surveys:surveys.view_results', 'surveys:surveys.moderate']
---
Las encuestas sirven para preguntar a la gente de {{site_name}}: valorar una partida o un torneo, elegir la fecha de una quedada, conocer la opinión de los socios o hacer un cuestionario con nota. Esta guía explica las piezas y cómo encajan. Las demás guías de encuestas entran en el detalle de cada una.

## Tres formatos

| Formato | Para qué sirve | Cómo es |
|---|---|---|
| **Encuesta** (formulario) | Preguntar varias cosas a la vez | Tantas preguntas como quieras, de trece tipos, repartidas en secciones. Puede saltar secciones según las respuestas y puede ser un cuestionario con nota. |
| **Sondeo rápido** | Una votación al momento | Una sola pregunta de opción única o de casillas. Por defecto, quien vota ve los resultados en directo. |
| **Sondeo de disponibilidad** | Cuadrar una fecha | Propones días o franjas y cada persona marca **Sí**, **Si hace falta** o **No** en cada una. Al cerrar, fijas la fecha y se avisa a todos. |

## Generales o de una actividad

- **General**: va a toda la asociación o a una parte. Eliges la audiencia: todas las personas con cuenta, solo socios o algunos roles. Se crea en el panel.
- **De una actividad**: va a quienes participaron en una mesa, una sesión de campaña, una campaña, un torneo o un evento. Las pantallas llaman «sujeto» a esa actividad. Cada módulo instalado ofrece sus actividades, y quien las organiza gestiona sus encuestas desde la web, sin entrar en el panel.

Lo habitual en una actividad es la **encuesta al terminar**: cuando la actividad acaba, se prepara sola con una plantilla y se envía, o se ofrece a quien la organiza para que la envíe.

## Plantillas

Una plantilla es un juego de preguntas para reutilizar. Al crear una encuesta desde una plantilla, se copian sus preguntas y puedes cambiarlas. Las preguntas de una plantilla llevan una **clave estable**, que permite comparar sus respuestas a lo largo del tiempo en las **Tendencias**. El módulo trae doce plantillas recomendadas para rol, wargames y la junta.

## Por qué estados pasa una encuesta

| Estado | Qué significa |
|---|---|
| **Borrador** | Se está preparando. Nadie la recibe todavía. |
| **Pendiente de revisión** | La ha cambiado alguien desde la web y espera a que un moderador la apruebe. Solo pasa si los ajustes piden revisión. |
| **Abierta** | Acepta respuestas. Al abrirla, su audiencia queda fijada y se le avisa por correo. |
| **Cerrada** | Ya no acepta respuestas, por fecha o a mano. No se puede volver a abrir. |

Quien organiza una actividad ve, además, estados de la web: **Preparada** (lista para salir cuando termine), **Pendiente de enviar**, **Programada** y **Rechazada**.

## Anónimas o con nombre

Una encuesta **anónima** guarda las respuestas sin ningún vínculo con la persona, y sus resultados solo salen al cerrarse y con un mínimo de respuestas. Una encuesta **con nombre** deja ver quién respondió qué. Se decide al crearla: una anónima nunca deja de serlo. La guía de anonimato lo explica en detalle.

## Quién hace qué

| Quién | Qué puede hacer |
|---|---|
| Con **Gestionar encuestas, plantillas y ajustes** | Crear, editar, abrir, cerrar y borrar encuestas y plantillas, y cambiar los ajustes |
| Con **Ver los resultados de las encuestas** | Ver los resultados, exportarlos y ver las tendencias |
| Con **Revisar encuestas creadas fuera del panel** | Aprobar o rechazar lo que los organizadores cambian en la web |
| Quien organiza una actividad | Desde la web: enviar, editar, programar y cerrar las encuestas de su actividad, crear sondeos y ver sus resultados |
| Quien recibe una encuesta | Responderla una vez y, si la encuesta lo permite, ver sus resultados |

Ninguno de los tres permisos se concede solo: se dan a cada rol en **Roles**.

## Dónde está cada cosa

- En el menú lateral, el grupo **Comunicación** tiene **Encuestas**; **Plantillas** y **Tendencias** aparecen debajo de **Encuestas**. Los ajustes están en **Sistema** → **Configuración** → **Encuestas**.
- En el escritorio, el widget **Encuestas pendientes de revisión** avisa de lo que espera un moderador.
- En el formulario de cada mesa, campaña, torneo o evento, el campo **Encuesta al terminar** elige qué encuesta sale cuando acaba.

## Para seguir

- **Crear una encuesta o un sondeo**, **Tipos de pregunta**, **Secciones y saltos** y **Cuestionarios con nota**, para prepararla.
- **Abrir, compartir y cerrar una encuesta**, **Avisos y correos** y **Anonimato y privacidad**, para enviarla.
- **Ver y exportar los resultados** y **Tendencias de las encuestas**, para leer lo que ha salido.
- **Plantillas de encuestas**, **Ajustes de encuestas** y **La encuesta al terminar de mesas, torneos y eventos**, para dejarlo todo preparado.

[[action:filament.admin.resources.surveys.index|Ver las encuestas|if=surveys:surveys.manage]]
