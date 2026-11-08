---
id: surveys.panel.results
title: Ver y exportar los resultados
summary: Lee los resultados de una encuesta pregunta a pregunta, elige qué se muestra y descárgalos en CSV o Excel.
category: content
audience: panel
order: 69
keywords: [resultados, respuestas, ver respuestas, gráfico, gráficos, exportar, descargar, csv, excel, hoja de cálculo, tasa de respuesta, participación, ocultar preguntas, archivos, notas, la vieron, cuántos han respondido]
related: [surveys.panel.anonymity, surveys.panel.trends, surveys.panel.question-types, surveys.panel.quizzes]
conditions:
  permissions:
    all: ['surveys:surveys.view_results']
---
Los resultados muestran cuántas personas han respondido y qué ha contestado la gente a cada pregunta. En las encuestas anónimas nunca se ve quién respondió qué.

## Abrir los resultados

1. En el menú lateral, abre **Encuestas** y elige **Encuestas**.
2. Abre el menú **⋯** de la encuesta y pulsa **Resultados** (también está en su ficha).

## Qué hay en la página

- **Participación**: cuántas respuestas hay y la **Tasa de respuesta** sobre su audiencia. Sin audiencia fija, como con el enlace público abierto, no hay tasa.
- **Una tarjeta por pregunta**, con cuántas personas la respondieron y su gráfico. Cada tipo se muestra a su manera:
  - Opciones: barras o sectores con el número y el porcentaje de cada opción. Lo escrito en «Otra» sale aparte.
  - Escalas y estrellas: barras con la **Media** y la **Mediana**.
  - Cuadrícula y disponibilidad: barras apiladas, una por fila o por fecha.
  - Ordenar: puntos o puestos de cada opción, y su posición media.
  - Texto libre: la lista de respuestas.
  - Archivos: la lista con su vista previa y el botón para descargarlos.
- **La vieron M de T**, en las preguntas que no vio todo el mundo por los saltos entre secciones.
- **Notas del cuestionario**, si es un cuestionario.

## Encuestas anónimas

- Sus resultados se muestran al cerrarla, salvo que tenga **Resultados en directo**.
- Hace falta el **Mínimo de respuestas** de los ajustes, en la encuesta y en cada pregunta. Mientras no se llega, la página lo dice en lugar de mostrar datos.
- Las respuestas del enlace público abierto salen, pero no cuentan para el mínimo.

La guía de anonimato explica por qué.

## Elegir qué se muestra

1. Pulsa **Configurar resultados**.
2. Elige el tipo de cada **Gráfico de «…»**, entre los que encajan con su pregunta: **Barras**, **Sectores**, **Barras apiladas** o **Lista**.
3. En **Preguntas ocultas**, marca las que no quieres mostrar. No salen en esta página ni a quien responde (si ve los resultados), pero la exportación las incluye.
4. Guarda.

## Exportar

Pulsa **Exportar CSV** o **Exportar Excel**: una fila por respuesta y una columna por pregunta. La celda de quien no llegó a una pregunta por los saltos dice **No llegó**. La exportación de una encuesta anónima no lleva ni la persona ni la fecha, y mientras sus resultados no se pueden ver, tampoco se puede exportar.

> [!IMPORTANT]
> Los archivos que se envían como respuesta solo los ve quien gestiona la encuesta. Sus enlaces caducan a los 30 minutos: recarga la página para renovarlos.

## Sondeos de disponibilidad

Cada fecha muestra cuántos dijeron **Sí**, **Si hace falta** y **No**, y se marca la **Mejor opción**. Cuando el sondeo ha cerrado, **Fijar fecha** elige la definitiva y avisa a todos (mira la guía de sondeos).

[[action:filament.admin.resources.surveys.index|Ver las encuestas]]
