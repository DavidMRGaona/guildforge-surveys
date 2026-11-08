---
id: surveys.panel.settings
title: Ajustes de encuestas
summary: Elige la plantilla que sale al terminar cada actividad, el mínimo de respuestas de las anónimas, la revisión y los valores por defecto.
category: content
audience: panel
order: 73
keywords: [ajustes, configuración, encuesta al terminar, plantilla por tipo, sistema de juego, envío automático, enviar sola, retraso, mínimo de respuestas, anonimato, revisión, archivos, valores por defecto, resumen al cerrar]
related: [surveys.panel.end-survey-field, surveys.panel.templates, surveys.panel.anonymity, surveys.panel.review]
conditions:
  permissions:
    all: ['surveys:surveys.manage']
---
Los ajustes deciden cómo se comportan todas las encuestas: qué sale cuando termina una mesa, un torneo o un evento, y qué valores tienen las encuestas nuevas.

## Abrir los ajustes

En el menú lateral, abre **Sistema**, elige **Configuración** y después **Encuestas**. La página se llama **Ajustes de encuestas**. Al terminar, pulsa **Guardar**.

## Encuestas al terminar

En **Encuestas al terminar** hay una parte por cada tipo de actividad que ofrecen los módulos instalados (mesa, campaña, torneo, evento…). En cada una:

1. Elige la **Plantilla** con la que se crea la encuesta. Sin plantilla, no sale ninguna.
2. En **Plantilla por sistema de juego**, pulsa **Añadir sistema de juego** para dar a un juego su propia plantilla. Si el sistema de juego está en la lista, se usa la suya; si no, la de arriba.
3. Activa **Enviar sola al terminar** para que salga sin esperar a nadie, y elige en **Enviar a las … horas de terminar** cuándo (con 0, en cuanto termina). Si no, se avisa a quien la organiza para que la envíe con un clic.
4. Elige los **Días abierta** de sus encuestas y marca **Anónima** si no deben guardar quién respondió qué.

Lo que se elija en el campo **Encuesta al terminar** del formulario de cada actividad manda sobre estos ajustes (mira la guía de la encuesta al terminar).

## Resultados y archivos

- **Mínimo de respuestas**: las que necesita una encuesta anónima para mostrar sus resultados, el texto libre y la exportación. Con 0 no hay mínimo.
- **Tamaño máximo de un archivo**: lo más que admite cualquier pregunta de archivo, de 1 a 10 MB.

## Valores por defecto

En **Valores por defecto de las encuestas nuevas** eliges los **Días abierta**, si son **Anónima por defecto** y si se marca **Anunciar en los canales por defecto**. En **Resumen al cerrar**, si se envía el resumen por defecto y si lo recibe también quien puede ver resultados.

## Revisión

Con **Las encuestas cambiadas fuera del panel requieren revisión**, lo que los organizadores escriben o cambian en la web espera a que alguien con permiso de revisión lo apruebe.

> [!IMPORTANT]
> Desactivar la revisión no aprueba lo que ya está pendiente: hay que revisarlo igualmente.

[[action:filament.admin.configuracion.pages.surveys-settings|Abrir los ajustes de encuestas]]
