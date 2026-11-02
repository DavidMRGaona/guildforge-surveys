---
id: surveys.panel.results
title: Ver y exportar los resultados
summary: Consulta las gráficas y respuestas de una encuesta, elige qué se muestra y descárgalas en CSV o Excel.
category: content
audience: panel
order: 64
keywords: [resultados, respuestas, gráfico, gráficos, exportar, descargar, csv, excel, tasa de respuesta, anónima, mínimo de respuestas, ocultar preguntas, archivos, notas, cuestionario, fijar fecha]
related: [surveys.panel.trends, surveys.panel.open-share-close]
conditions:
  permissions:
    all: ['surveys:surveys.view_results']
---
Los resultados muestran cuántas personas han respondido y un gráfico por pregunta. En las encuestas anónimas nunca se ve quién respondió qué.

## Ver los resultados

1. En el menú lateral, abre **Encuestas** y elige **Encuestas**.
2. Pulsa **Resultados** en la encuesta.
3. Arriba verás las respuestas y la **Tasa de respuesta**. Sin audiencia fija, como con el enlace público abierto, no hay tasa.
4. Cada pregunta tiene su gráfico. Las de texto muestran las respuestas en una lista, y lo escrito en «Otra» sale aparte.

Si una pregunta no la vio todo el mundo por los saltos entre secciones, dice **La vieron M de T**. Los cuestionarios muestran las **Notas del cuestionario**, y los sondeos de disponibilidad, la **Mejor opción**.

## Encuestas anónimas

- Sus resultados se muestran al cerrarla, salvo que tenga **Resultados en directo**.
- Necesitan el **Mínimo de respuestas** de los ajustes, también en cada pregunta, en el texto libre y en la exportación.
- Las respuestas del enlace público abierto salen en los resultados, pero no cuentan para el mínimo.

## Elegir qué se muestra

1. Pulsa **Configurar resultados**.
2. Elige el tipo de cada **Gráfico de «…»**: **Barras**, **Sectores**, **Barras apiladas** o **Lista**.
3. En **Preguntas ocultas**, marca las que no quieres mostrar. No salen en esta página ni a quien responde, pero la exportación las incluye.
4. Guarda.

## Exportar

Pulsa **Exportar CSV** o **Exportar Excel**. La exportación de una encuesta anónima no lleva ni la persona ni la fecha. Mientras los resultados no se pueden ver, tampoco se pueden exportar.

> [!IMPORTANT]
> Los archivos que se envían como respuesta solo los ve quien gestiona la encuesta. Sus enlaces caducan a los 30 minutos: recarga la página para renovarlos.

## Fijar la fecha de un sondeo de disponibilidad

Cuando el sondeo ha cerrado, pulsa **Fijar fecha**, elige la **Fecha**, marca la casilla y pulsa **Fijar y avisar**. Se avisa por correo a quienes recibieron la encuesta o la respondieron con su cuenta, y la fecha ya no se puede cambiar.

[[action:filament.admin.resources.surveys.index|Ver las encuestas]]
