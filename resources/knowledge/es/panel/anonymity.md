---
id: surveys.panel.anonymity
title: Anonimato y privacidad
summary: Qué garantiza una encuesta anónima, qué la debilita, el mínimo de respuestas y qué se guarda de cada persona.
category: content
audience: panel
order: 67
keywords: [anónima, anonimato, anónimo, privacidad, confidencial, secreto, quién respondió, mínimo de respuestas, resultados en directo, protección de datos, rgpd, datos personales, con nombre, nominal]
related: [surveys.panel.open-share-close, surveys.panel.results, surveys.panel.settings]
conditions:
  permissions:
    any: ['surveys:surveys.manage', 'surveys:surveys.view_results']
---
Una encuesta puede ser **anónima** o **con nombre**. La diferencia no es solo que no se muestre el nombre: en una anónima la aplicación no guarda ningún vínculo entre la persona y lo que respondió.

## Qué cambia

| | Anónima | Con nombre |
|---|---|---|
| Quién respondió qué | Nadie lo puede saber desde la aplicación | Se ve en los resultados y en la exportación |
| Cuándo salen los resultados | Al cerrar (o en directo, si se activa) y con un mínimo de respuestas | En cuanto hay respuestas |
| Texto libre | Solo con el mínimo de respuestas | Siempre |
| Exportación | Sin persona ni fecha, con el mínimo | Con persona y fecha |
| Preguntas de archivo | Solo imágenes | Imágenes y PDF |
| Cuestionario | Nota media, sin clasificación | Con clasificación |

Una encuesta anónima nunca puede dejar de serlo, y una con nombre que ya tiene respuestas no puede pasar a anónima.

## Cómo se protege

- **Respuesta y participación van por separado**: se apunta que alguien ha respondido (para no enviarle recordatorios ni dejarle responder dos veces) en un sitio distinto de sus respuestas, sin nada que permita unirlos: ni columnas, ni orden, ni hora. De la respuesta solo se guarda el día.
- **Mínimo de respuestas**: los resultados, cada pregunta, el texto libre y la exportación solo se muestran cuando llegan al **Mínimo de respuestas** de los ajustes. Con dos respuestas, cualquiera de las dos personas podría deducir la de la otra.
- **Resultados al cerrar**: mientras está abierta, comparar los resultados antes y después de una respuesta delataría esa respuesta. Por eso esperan al cierre.

## Lo que lo debilita

- **Resultados en directo**: permiten la comparación de antes y después. Úsalos solo si importa más ver el avance que el anonimato. El mínimo se sigue aplicando.
- **Enlace público abierto**: sus respuestas salen en los resultados, pero no cuentan para el mínimo, porque alguien podría enviar respuestas de relleno y restarlas para despejar las demás.
- **Pocas respuestas en una sección**: por eso **La vieron M de T** también respeta el mínimo.
- **Lo que escribe la persona**: si en un texto libre firma o cuenta algo que solo ella sabe, el anonimato no la protege.

## Qué se guarda de cada persona

- Con cuenta: que ha respondido (y, si la encuesta es con nombre, sus respuestas).
- Con el enlace público **Una respuesta por correo**: su dirección, cifrada, solo hasta que la encuesta se cierra. Cada dirección recibe como mucho tres enlaces al día, y la página nunca dice si una dirección ya respondió.
- Archivos: se guardan en un almacenamiento privado, las fotos sin sus datos ocultos (lugar, cámara), y solo los ve quien gestiona la encuesta.

> [!IMPORTANT]
> La garantía es frente a quien usa la aplicación, panel incluido. Quien tenga acceso directo a la base de datos queda fuera de ella.
