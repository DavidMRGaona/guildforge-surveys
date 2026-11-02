---
id: surveys.panel.quizzes
title: Cuestionarios con nota
summary: Convierte una encuesta en un cuestionario con respuestas correctas y puntos, y consulta las notas.
category: content
audience: panel
order: 64
keywords: [cuestionario, quiz, examen, test, nota, puntos, puntuación, respuesta correcta, acertar, aciertos, clasificación, trivial, preguntas de reglas]
related: [surveys.panel.question-types, surveys.panel.results, surveys.panel.anonymity]
conditions:
  permissions:
    any: ['surveys:surveys.manage', 'surveys:surveys.view_results']
---
Un cuestionario es una encuesta en la que algunas preguntas tienen respuesta correcta y dan puntos. Sirve para un trivial en unas jornadas, para repasar las reglas de un torneo o para comprobar quién se ha leído el reglamento.

## Hacer un cuestionario

1. En la encuesta, activa **Es un cuestionario**.
2. En cada pregunta de **Opción única**, **Casillas** o **Desplegable** que quieras puntuar, marca la opción **Correcta** (en las casillas, todas las correctas).
3. Escribe sus **Puntos**, de 1 a 100.
4. Si quieres que cada persona vea al enviar qué acertó y cuáles eran las respuestas correctas, activa **Mostrar qué acertó al enviar**.

Las demás preguntas (texto, escalas…) pueden seguir en la encuesta: no puntúan.

## Cómo se puntúa

- Una pregunta solo da sus puntos si se eligen **exactamente** sus opciones correctas. En las casillas, marcar una correcta de menos o una incorrecta de más da 0.
- Una pregunta sin ninguna opción marcada como correcta no puntúa.
- Las preguntas puntuadas no admiten «Otra».
- Solo las encuestas pueden ser cuestionarios, no los sondeos.

## Qué ve quien responde

Al enviar ve **Tu nota** (los puntos sobre el máximo) y, con **Mostrar qué acertó al enviar**, cada pregunta como acertada o fallada con la respuesta correcta. En una encuesta anónima la nota solo se ve en ese momento: no queda ligada a la persona.

## Las notas en los resultados

En **Resultados** sale **Notas del cuestionario**:

- La **Nota media**.
- **Cuántas respuestas sacaron cada nota**, en un gráfico.
- El acierto de cada pregunta (**Acierto: X % (A de B)**).
- En las encuestas con nombre, la **Clasificación** con el puesto, la persona y sus puntos.

En una encuesta anónima no hay clasificación. Si alguna pregunta puntuada tiene pocas respuestas, las notas no se muestran, porque la nota de cada respuesta delataría qué contestó.

## Si algo no sale bien

- **«Marca la respuesta correcta de al menos una pregunta.»**: un cuestionario necesita al menos una pregunta puntuada.
- **«Esta pregunta admite una sola respuesta: marca solo una opción correcta.»**: en opción única y desplegable solo hay una correcta.
- **«Otra» no se admite en una pregunta puntuada**: quita **Permitir «Otra» con texto libre** de esa pregunta.
