---
id: surveys.panel.create-survey
title: Crear una encuesta o un sondeo
summary: Crea una encuesta con sus secciones y preguntas, o un sondeo rápido de una sola pregunta.
category: content
audience: panel
order: 60
keywords: [encuesta, crear encuesta, nueva encuesta, sondeo, sondeo rápido, votación, disponibilidad, cuadrar fechas, preguntas, secciones, saltos, cuestionario, plantilla, formulario]
related: [surveys.panel.open-share-close, surveys.panel.templates]
conditions:
  permissions:
    all: ['surveys:surveys.manage']
---
Las encuestas se crean como borrador: nadie las recibe hasta que las abres. Puedes hacer un formulario completo, un sondeo rápido de una pregunta o un sondeo de disponibilidad para cuadrar fechas.

## Crear una encuesta

1. En el menú lateral, abre **Encuestas** y elige **Encuestas**.
2. Pulsa **Crear encuesta**.
3. Si quieres, elige una plantilla en **Partir de una plantilla**: copia sus preguntas y después puedes cambiarlas.
4. Escribe el **Título** y, si quieres, la **Descripción**.
5. Decide si es **Anónima**. Una encuesta anónima no guarda quién respondió qué y nunca deja de serlo.
6. En **Preguntas**, pulsa **Añadir pregunta** y elige su tipo: **Respuesta corta**, **Párrafo**, **Opción única**, **Casillas**, **Desplegable**, **Escala lineal**, **Valoración con estrellas**, **Fecha**, **Hora**, **Cuadrícula de opción única**, **Ordenar** o **Archivo**.
7. Rellena la **Pregunta**, el **Texto de ayuda** si hace falta, y marca **Obligatoria** si no se puede dejar en blanco. En las de opciones, añade las **Opciones** y, si quieres, **Permitir «Otra» con texto libre**.
8. Completa a quién va, el plazo y los resultados (lo explica la guía de abrir y compartir) y pulsa **Crear**.

## Secciones y saltos

Con **Añadir sección** divides la encuesta en partes. Quien responde ve una sección por página.

- **Después de esta sección** dice adónde ir: la siguiente, una sección posterior o **Enviar el formulario**.
- En una pregunta de **Opción única** o **Desplegable**, **Ir a una sección según la respuesta** manda cada opción a otra sección. Solo una pregunta por sección puede decidir el salto.

Solo se salta hacia delante. Las preguntas de las secciones que se salten no se piden.

## Cuestionarios y archivos

- Activa **Es un cuestionario** para puntuar: marca la opción **Correcta** de cada pregunta de opción y sus **Puntos**. Con **Mostrar qué acertó al enviar**, cada persona ve qué acertó.
- En una pregunta de **Archivo**, elige los **Archivos que admite** y su **Tamaño máximo**. Una encuesta anónima solo admite imágenes.

## Sondeos rápidos

- **Sondeo rápido**: una **Pregunta** con sus opciones. Activa **Permitir elegir varias opciones** si se puede votar más de una.
- **Sondeo de disponibilidad**: una **Pregunta** y sus **Fechas propuestas**. Cada persona marca **Sí**, **Si hace falta** o **No** en cada fecha.

Los dos botones están arriba, en la lista de encuestas.

> [!IMPORTANT]
> Las preguntas que vienen de una plantilla tienen **Clave estable** y no pueden cambiar de tipo, para que sus resultados se puedan comparar en las tendencias. Cuando una encuesta tiene respuestas, solo se pueden cambiar los textos de ayuda y la fecha de cierre.

[[action:filament.admin.resources.surveys.create|Crear una encuesta]]
