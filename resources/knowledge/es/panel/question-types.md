---
id: surveys.panel.question-types
title: Tipos de pregunta
summary: Los trece tipos de pregunta, para qué sirve cada uno, cómo se configura, cómo lo ve quien responde y cómo salen sus resultados.
category: content
audience: panel
order: 62
keywords: [tipos de pregunta, pregunta, respuesta corta, párrafo, texto libre, opción única, casillas, varias opciones, desplegable, escala, escala lineal, estrellas, valoración, fecha, hora, cuadrícula, ordenar, ranking, preferencias, archivo, foto, imagen, pdf, otra, opciones]
related: [surveys.panel.create-survey, surveys.panel.sections-and-jumps, surveys.panel.quizzes, surveys.panel.results]
conditions:
  permissions:
    any: ['surveys:surveys.manage', 'surveys:surveys.view_results']
---
Elegir bien el tipo de pregunta hace que responder sea fácil y que los resultados se puedan leer de un vistazo. Esta tabla resume todos; debajo está el detalle de cada uno.

| Tipo | Para preguntar… | Resultados | Tendencias |
|---|---|---|---|
| **Respuesta corta** | Un dato o una frase | Lista de respuestas | No |
| **Párrafo** | Una opinión con detalle | Lista de respuestas | No |
| **Opción única** | Una elección entre pocas opciones | Barras o sectores | Sí |
| **Casillas** | Varias elecciones a la vez | Barras | Sí |
| **Desplegable** | Una elección entre muchas opciones | Barras o sectores | Sí |
| **Escala lineal** | Un grado, de 1 a 5 o de 0 a 10 | Barras, media y mediana | Sí |
| **Valoración con estrellas** | Una nota con estrellas | Barras, media y mediana | Sí |
| **Fecha** | Un día | Barras | No |
| **Hora** | Una hora | Barras | No |
| **Cuadrícula de opción única** | El mismo grado para varias cosas | Barras apiladas | Sí, una por fila |
| **Ordenar** | Preferencias de más a menos | Puntos o puestos | Sí |
| **Archivo** | Una foto o un PDF | Lista con vista previa | No |
| **Disponibilidad** | Qué fechas le van bien a cada uno | Barras apiladas y mejor opción | No |

## Texto libre

- **Respuesta corta**: una línea, hasta 500 caracteres. Para un nombre, un dato o una frase.
- **Párrafo**: varias líneas, hasta 5000 caracteres. Para opiniones y sugerencias.

En los resultados salen en una lista. En las encuestas anónimas, la lista solo aparece con el mínimo de respuestas, porque una frase puede delatar a quien la escribió.

## Elegir entre opciones

- **Opción única**: se elige una. Con pocas opciones (hasta seis o siete), porque se ven todas a la vez.
- **Casillas**: se marcan todas las que se quiera. Los porcentajes se cuentan sobre quienes respondieron, así que pueden sumar más de 100 %.
- **Desplegable**: como la opción única, pero en una lista que se abre. Para listas largas, como los sistemas de juego.

Las tres necesitan al menos dos **Opciones**. Con **Permitir «Otra» con texto libre**, quien responde puede escribir su propia opción; en los resultados, lo escrito sale aparte, en **Lo escrito en «Otra»**.

La **Opción única** y el **Desplegable** pueden decidir a qué sección se va después (mira la guía de secciones y saltos), y las tres pueden puntuar en un cuestionario.

## Grados y notas

- **Escala lineal**: un número entre **Desde** (0 o 1) y **Hasta** (de 2 a 10). Pon una **Etiqueta del mínimo** y una **Etiqueta del máximo** (por ejemplo, «Nada» y «Muchísimo») para que se entienda qué significa cada extremo.
- **Valoración con estrellas**: de 3 a 10 estrellas, elegidas en **Número de estrellas**.

En los resultados se ve cuántos eligieron cada valor, con la **Media** y la **Mediana**. Son las preguntas que mejor funcionan en las tendencias.

## Cuadrícula de opción única

Varias **Filas** (lo que se valora) con las mismas **Columnas** (la escala). Por ejemplo, filas «Ambientación», «Reglas» y «Ritmo», y columnas «Mal», «Bien» y «Muy bien». En cada fila se elige una columna. Necesita una fila y dos columnas como mínimo, y si es obligatoria hay que responder todas las filas.

## Ordenar

Quien responde coloca todas las opciones de la que más le gusta a la que menos, tocándolas en orden y ajustando con flechas o arrastrando. Admite de 2 a 10 opciones. En los resultados, cada primer puesto suma tantos puntos como opciones hay, el segundo uno menos y así hasta el último, que suma 1; también se ve la posición media, donde 1 es la mejor.

## Fecha y hora

Un día o una hora, con su selector. Útil para «¿Cuándo empezaste a jugar?» o «¿A qué hora te viene bien?». Para elegir entre varias fechas propuestas, usa mejor un sondeo de disponibilidad.

## Archivo

Quien responde sube un archivo: elige en **Archivos que admite** si son **Imágenes (JPEG, PNG o WebP)**, **PDF** o ambos, y en **Tamaño máximo** hasta cuántos MB (como mucho, el límite de los ajustes, de 1 a 10 MB).

- Se comprueba el contenido del archivo, no su nombre, y las fotos se guardan sin sus datos ocultos (lugar, cámara).
- Una encuesta anónima solo admite imágenes, porque un PDF puede llevar el nombre de quien lo escribió.
- Con preguntas de archivo, el enlace público tiene que ser **Una respuesta por correo**.
- Los archivos solo los ve quien gestiona la encuesta, nunca quien responde. Se borran al borrar la encuesta.

## Disponibilidad

Solo existe dentro de un sondeo de disponibilidad: es su única pregunta. Lo explica la guía de sondeos rápidos y de disponibilidad.

> [!TIP]
> Si quieres comparar una pregunta a lo largo del tiempo, usa escalas, estrellas u opciones y ponla en una plantilla: así conserva su clave estable y entra en las tendencias.
