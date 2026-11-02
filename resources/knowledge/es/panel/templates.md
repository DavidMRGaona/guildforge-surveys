---
id: surveys.panel.templates
title: Plantillas de encuestas
summary: Usa las plantillas recomendadas o crea las tuyas, para que las encuestas partan de las mismas preguntas y se puedan comparar.
category: content
audience: panel
order: 62
keywords: [plantilla, plantillas, plantillas recomendadas, crear plantilla, clave estable, preguntas, para, tipo de sujeto, reutilizar, comparar encuestas, tendencias]
related: [surveys.panel.settings, surveys.panel.trends]
conditions:
  permissions:
    all: ['surveys:surveys.manage']
---
Una plantilla guarda unas preguntas para reutilizarlas. Las encuestas creadas con ella copian sus preguntas y conservan sus claves, así que sus resultados se pueden comparar en las tendencias.

## Las plantillas recomendadas

El módulo trae plantillas pensadas para una asociación de rol y wargames: partidas y campañas de rol, torneos de varios juegos, eventos de la asociación y encuestas de la junta. Puedes cambiarlas o borrarlas: un cambio tuyo nunca se pisa, y una plantilla borrada no vuelve.

En la lista, el filtro **Origen** separa las **Recomendadas** de las **Propias**.

## Crear una plantilla

1. En el menú lateral, abre **Encuestas** y elige **Plantillas**.
2. Pulsa **Crear plantilla**.
3. Escribe el **Nombre** y, si quieres, la **Descripción**.
4. En **Para**, marca para qué encuestas sirve. Sin marcar, sirve para cualquiera. Los selectores de plantilla ofrecen primero las que son para cada caso.
5. Añade las preguntas como en una encuesta. Da a cada una una **Clave estable**, en minúsculas y sin espacios (por ejemplo, «diversion»).
6. Pulsa **Crear**.

> [!IMPORTANT]
> La **Clave estable** identifica la pregunta en todas las encuestas hechas con la plantilla. No la cambies si quieres seguir comparando resultados, y ten en cuenta que una pregunta con clave no puede cambiar de tipo.

## Otras formas de crearla

Desde una encuesta, **Guardar como plantilla** crea una plantilla con sus preguntas. Los sondeos de disponibilidad no se guardan como plantilla, porque sus fechas solo valen una vez.

## Dónde se usan

- Al crear una encuesta, en **Partir de una plantilla**.
- En **Ajustes de encuestas**, como encuesta al terminar de cada tipo de actividad o de cada sistema de juego.
- En **Tendencias**, que muestra cómo evoluciona cada pregunta. La acción **Tendencias** de la lista abre las de esa plantilla.

[[action:filament.admin.resources.survey-templates.index|Ver las plantillas]]
