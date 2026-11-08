---
id: surveys.panel.templates
title: Plantillas de encuestas
summary: Usa las plantillas recomendadas o crea las tuyas, para que las encuestas partan de las mismas preguntas y se puedan comparar en el tiempo.
category: content
audience: panel
order: 71
keywords: [plantilla, plantillas, plantillas recomendadas, crear plantilla, clave estable, claves, preguntas, para, tipo de sujeto, reutilizar, comparar encuestas, tendencias, warhammer, age of sigmar, kill team, blood bowl, partida de rol, torneo]
related: [surveys.panel.settings, surveys.panel.trends, surveys.panel.end-survey-field, surveys.panel.create-survey]
conditions:
  permissions:
    all: ['surveys:surveys.manage']
---
Una plantilla es un juego de preguntas para reutilizar. Las encuestas creadas con ella copian sus preguntas: puedes cambiarlas en cada encuesta sin tocar la plantilla. Es también lo que hace que unas encuestas se puedan comparar con otras en las **Tendencias**.

## Las plantillas recomendadas

El módulo trae doce plantillas pensadas para una asociación de rol y wargames, cada una con una sola pregunta obligatoria para que responder sea rápido:

| Para | Plantillas |
|---|---|
| Rol | «Partida de rol» (mesas sueltas), «Sesión de campaña» (corta) y «Campaña completa» |
| Torneos | «Torneo de wargames» y sus variantes de Warhammer 40.000, Age of Sigmar, Kill Team y Blood Bowl, que repiten la parte general y añaden una sección del juego |
| Eventos | «Evento de la asociación» (jornadas, puertas abiertas) |
| Junta | «Satisfacción de los socios», «Interés por actividades y sistemas» y «Disponibilidad de días y franjas» |

Llegan al instalar el módulo o con la actualización que las trae, y pasan a ser las de cada tipo de actividad donde no había ninguna elegida. Las variantes de cada juego se asignan a mano en **Ajustes de encuestas**, en **Plantilla por sistema de juego**.

Puedes cambiarlas o borrarlas: una plantilla que hayas cambiado nunca se pisa, y una que hayas borrado no vuelve. En la lista, el filtro **Origen** separa las **Recomendadas** de las **Propias**.

## Crear una plantilla

1. En el menú lateral, abre **Encuestas** y elige **Plantillas**.
2. Pulsa **Crear plantilla**.
3. Escribe el **Nombre** y, si quieres, la **Descripción**.
4. En **Para**, marca para qué encuestas sirve: encuestas generales o uno o varios tipos de actividad. Sin marcar, sirve para cualquiera. Los selectores de plantilla ofrecen primero las que son para cada caso y el resto debajo, en **Otras**.
5. Añade las preguntas como en una encuesta, con sus secciones y saltos si hacen falta.
6. Da a cada pregunta que quieras comparar una **Clave estable** (mira abajo).
7. Pulsa **Crear**.

## Claves estables

La **Clave estable** es el nombre interno de una pregunta, en minúsculas, sin espacios y empezando por una letra (por ejemplo, «diversion» o «volveria»). Identifica la pregunta en todas las encuestas hechas con la plantilla, aunque cambie su texto, y es lo que permite seguirla en las tendencias.

- Una pregunta con clave no puede cambiar de tipo, ni en la plantilla ni en las encuestas que la copian: si cambiara, sus respuestas ya no se podrían comparar.
- Las preguntas que se añaden a mano en una encuesta no llevan clave: no entran en las tendencias.
- Si cambias mucho el sentido de una pregunta, dale una clave nueva en lugar de reutilizar la antigua.

## Otras formas de crearla

Desde una encuesta, **Guardar como plantilla** crea una plantilla con sus preguntas (las claves, solo donde ya las había) y con **Para** puesto a su tipo de actividad. Los sondeos de disponibilidad no se guardan como plantilla, porque sus fechas solo valen una vez.

## Dónde se usan

- Al crear una encuesta, en **Partir de una plantilla**.
- En **Ajustes de encuestas**, como encuesta al terminar de cada tipo de actividad y de cada sistema de juego.
- En el campo **Encuesta al terminar** del formulario de cada actividad.
- En **Tendencias**: la acción **Tendencias** del menú **⋯** de cada plantilla de la lista abre las de esa plantilla. En una pantalla estrecha la lista muestra solo el **Nombre**: el resto de columnas aparecen según el ancho.

Si borras una plantilla, las encuestas hechas con ella no cambian, y las actividades que la tenían elegida usan la plantilla por defecto.

[[action:filament.admin.resources.survey-templates.index|Ver las plantillas]]
