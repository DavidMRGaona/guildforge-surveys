---
id: surveys.panel.trends
title: Tendencias de las encuestas
summary: Mira cómo evoluciona cada pregunta de una plantilla a lo largo del tiempo y compárala por organizador, sistema de juego o tipo.
category: content
audience: panel
order: 70
keywords: [tendencias, evolución, comparar, histórico, media, satisfacción, organizador, sistema de juego, tipo de sujeto, mes, trimestre, año, gráfico, plantilla]
related: [surveys.panel.results, surveys.panel.templates, surveys.panel.question-types]
conditions:
  permissions:
    all: ['surveys:surveys.view_results']
---
Las tendencias juntan las respuestas de todas las encuestas hechas con una plantilla, para ver cómo cambia cada pregunta con el tiempo.

## Ver las tendencias

1. En el menú lateral, abre **Encuestas** y elige **Tendencias**. También puedes pulsar **Tendencias** en el menú **⋯** de una plantilla de la lista de plantillas.
2. Elige la **Plantilla**.
3. Si quieres, acota por **Tipo de sujeto**, **Organizador**, **Sistema de juego** y fechas (**Desde** y **Hasta**).
4. Elige cómo **Agrupar por**: **Mes**, **Trimestre** o **Año**, según la fecha de la actividad (en las encuestas generales, la de apertura).
5. Para comparar, elige en **Comparar por** el organizador, el sistema de juego o el tipo de sujeto. Se dibujan 8 series como mucho: el resto se junta en una.

## Qué se dibuja

- **Escalas y estrellas**: la media de cada periodo.
- **Opción única, desplegable y casillas**: el porcentaje de cada opción.
- **Ordenar**: la posición media de cada opción, donde 1 es la mejor.
- **Cuadrícula**: un gráfico por fila.

El texto libre, las fechas y las horas no tienen tendencia.

## Qué preguntas cuentan

Las preguntas se siguen por su **Clave estable**, aunque cambie su texto. Las preguntas añadidas a mano, sin clave, y las encuestas que los organizadores crean desde cero no entran.

Solo cuentan las encuestas cuyos resultados ya se pueden ver por separado: cerradas o con resultados en directo y, en las anónimas, con el mínimo de respuestas. Cada punto necesita también ese mínimo, así que combinar filtros nunca deja ver una encuesta que sola no se vería.

[[action:filament.admin.pages.survey-trends|Abrir las tendencias]]
