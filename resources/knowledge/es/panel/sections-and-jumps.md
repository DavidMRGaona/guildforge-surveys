---
id: surveys.panel.sections-and-jumps
title: Secciones y saltos (lógica condicional)
summary: Reparte una encuesta en secciones y haz que cada persona vea solo las que le tocan según lo que responde.
category: content
audience: panel
order: 63
keywords: [secciones, saltos, lógica, lógica condicional, condicional, ramificación, ir a sección, según la respuesta, saltar preguntas, preguntas condicionales, flujo, enviar el formulario, páginas]
related: [surveys.panel.question-types, surveys.panel.create-survey, surveys.panel.results]
conditions:
  permissions:
    any: ['surveys:surveys.manage', 'surveys:surveys.view_results']
---
Una encuesta puede repartirse en **secciones**. Quien responde ve una sección por página y pasa de una a otra con **Siguiente** y **Atrás**. Con los **saltos**, lo que responde decide a qué sección va después: así cada persona solo ve las preguntas que le tocan.

## Un ejemplo

Una encuesta tras una jornada de puertas abiertas:

- **Sección 1**: «¿Habías jugado antes a rol?» (opción única: «Sí» / «No»).
- **Sección 2**: preguntas para quien ya jugaba («¿Qué sistemas conoces?»).
- **Sección 3**: preguntas para quien empieza («¿Qué te ha parecido la primera partida?»).
- **Sección 4**: preguntas para todos («¿Volverías?»).

Con saltos: en la sección 1, «Sí» lleva a la sección 2 y «No» a la 3. La sección 2 dice, en **Después de esta sección**, que se va a la 4, para no pasar por la 3. Cada persona responde tres secciones y nunca ve las preguntas que no van con ella.

## Crear secciones

1. Dentro de la encuesta, en **Preguntas**, pulsa **Añadir sección**.
2. Dale un **Título de la sección** y, si quieres, una **Descripción de la sección**. Quien responde los ve arriba de la página.
3. Añade sus preguntas con **Añadir pregunta**.

Sin saltos, las secciones se responden en orden, una detrás de otra.

## Decidir adónde ir después de una sección

Cada sección tiene **Después de esta sección**:

- **Seguir con la sección siguiente** (lo normal).
- Una sección posterior, para saltarse las de en medio.
- **Enviar el formulario**, para terminar ahí.

## Saltar según la respuesta

1. En una pregunta de **Opción única** o **Desplegable**, activa **Ir a una sección según la respuesta**.
2. Para cada opción, elige en **Después de esta opción** adónde lleva: una sección posterior, **Enviar el formulario** o **Lo que diga la sección** (lo que marque **Después de esta sección**).
3. Si la pregunta permite «Otra», elige también adónde lleva en **Si elige «Otra»**.

## Reglas

- Solo se salta **hacia delante**: a una sección posterior o al final. Así nunca se entra en un bucle.
- Solo una pregunta por sección puede decidir el salto, y tiene que ser de **Opción única** o **Desplegable** (las casillas permiten varias respuestas, así que no hay un solo camino).
- Si la pregunta que decide el salto no se responde, manda lo que diga la sección.
- Los sondeos rápidos y de disponibilidad no tienen secciones ni saltos.

## Qué ve quien responde

Una sección por página, con **Paso X de Y**. Si vuelve **Atrás** y cambia una respuesta que decide un salto, el camino se recalcula. Las preguntas de las secciones saltadas no se le piden, aunque sean obligatorias, y el servidor rechaza respuestas a preguntas que no le tocaban.

## Cómo afecta a los resultados

- Una pregunta que no vio todo el mundo dice **La vieron M de T**: M personas llegaron a ella de las T que respondieron la encuesta.
- En una encuesta anónima, **La vieron M de T** solo sale si M llega al mínimo de respuestas y si se pueden ver las preguntas que deciden los saltos, porque contar quién llegó a cada sección deja ver qué eligió.
- En la exportación, la celda de quien no llegó a una pregunta dice **No llegó**, para distinguirla de quien la dejó en blanco.

## Si algo no sale bien

- **«Solo se puede ir a una sección posterior o enviar el formulario.»**: el salto apunta hacia atrás. Reordena las secciones o elige otra.
- **«Solo una pregunta de cada sección puede decidir la sección siguiente.»**: quita los saltos de las demás preguntas de la sección, o pásalas a otra.
- **«La sección de destino ya no existe: elige otra.»**: has borrado la sección a la que llevaba el salto.
- **«Solo las preguntas de opción única y los desplegables pueden decidir la sección siguiente.»**: cambia el tipo de la pregunta o quita su salto.
