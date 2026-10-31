# GuildForge - Módulo de encuestas

Encuestas para la plataforma GuildForge: formularios al estilo de un formulario web, sondeos rápidos de una pregunta y encuestas al terminar una mesa, una campaña, un torneo o un evento.

Requiere el core de GuildForge `^3.28`.

## Qué hace

- **Plantillas y encuestas** desde el panel, con secciones y diez tipos de pregunta: texto corto, párrafo, opción única, casillas, desplegable, escala, estrellas, fecha, hora y cuadrícula. Las preguntas de opciones admiten «Otra».
- **Sondeo rápido**: una pregunta de opción única o múltiple. Por defecto quien vota ve los resultados en directo.
- **Audiencia**: todas las cuentas, las personas socias, unos roles o las personas de un sujeto (una mesa, un torneo…) que ofrezca otro módulo. Se fija al abrir la encuesta. Enlace público opcional, que no garantiza una respuesta por persona.
- **Respuesta** con la cuenta, con el enlace de invitado o con el enlace público. Cada persona responde una vez.
- **Avisos**: correo al abrir, recordatorio opcional antes del cierre a quien no ha respondido y pestaña «Encuestas» en el perfil con las pendientes.
- **Resultados** en el panel, con un gráfico por pregunta (tipo de gráfico y preguntas ocultas configurables), la tasa de respuesta y la exportación a CSV y Excel. Si la encuesta lo permite, quien responde también ve los resultados.
- **Cierre** por fecha o a mano. Las encuestas solo se borran a mano, con doble confirmación.

## Encuestas de lo que organizas

Cuando termina algo que otro módulo ofrece como sujeto (una mesa, una campaña, un torneo, un evento), se prepara su encuesta con la plantilla configurada para ese tipo y se avisa por correo a quien lo organiza. Una sola encuesta por final: si el aviso de que ha terminado llega dos veces, no se duplica.

Desde la web, en la ficha del sujeto o en la pestaña «Encuestas» del perfil, quien organiza puede:

- **Enviarla** tal cual, **no enviarla** (el panel la sigue viendo) o **editarla** antes: textos, preguntas, opciones, fechas y ajustes. Las preguntas que vienen de la plantilla no cambian de tipo, para que se puedan comparar. Con la primera respuesta solo se pueden cambiar los textos de ayuda, la fecha de cierre y los ajustes.
- **Programarla**: si el tipo de sujeto tiene el envío automático, sale sola pasado el retraso configurado (12 horas por defecto, 0 para enviarla al terminar, sin máximo). El comando `surveys:send-scheduled` corre cada cinco minutos.
- **Ver sus resultados** cuando ya ha salido, con la misma regla que el panel (una anónima espera a cerrar y al mínimo de respuestas): participación, una gráfica por pregunta con su tabla de datos, y el texto libre. Elige la gráfica de cada pregunta y cuáles se ocultan.

Cada acción comprueba en el momento que la persona sigue organizando el sujeto: si deja de hacerlo, o el módulo del sujeto se desactiva, pierde el acceso.

## Revisión

Con el ajuste «Las encuestas cambiadas fuera del panel requieren revisión», una encuesta cuyo contenido se ha cambiado desde la web queda «En revisión» al enviarla y no sale hasta que alguien con `surveys.moderate` la apruebe en el panel (o la rechace con un motivo, que vuelve a quien la organiza). Una encuesta ya abierta que se cambia vuelve a revisión y deja de aceptar respuestas mientras tanto; mientras espera, su contenido no se puede tocar. Las fechas y los ajustes no son contenido y nunca pasan por revisión. Apagar el ajuste no aprueba las que ya esperan.

## Anonimato

Una encuesta anónima guarda sus respuestas sin ningún vínculo con la persona: la participación («ya ha respondido») va en otra tabla, y ninguna columna, orden ni hora permite unirlas. La respuesta solo guarda el día.

Sus resultados se muestran al cerrarla (o antes, si tiene los resultados en directo, que debilitan el anonimato) y solo con un mínimo de respuestas, que se aplica también a cada pregunta, al texto libre y a la exportación. La exportación de una encuesta anónima no lleva ni persona ni fecha. Nunca se muestra quién ha respondido.

La garantía es frente a quien usa la aplicación, panel incluido; quien tenga acceso directo a la base de datos queda fuera.

## Permisos

Ninguno se concede solo; se dan en el editor de roles.

| Permiso | Para qué |
|---|---|
| `surveys.manage` | Crear y editar plantillas y encuestas, abrirlas, cerrarlas y borrarlas |
| `surveys.view_results` | Ver los resultados y exportarlos |
| `surveys.moderate` | Revisar las encuestas creadas fuera del panel (llega con la creación desde la web) |

## Ajustes

En «Ajustes de encuestas», en el panel (con `surveys.manage`): mínimo de respuestas de las encuestas anónimas (3 por defecto; 0 lo quita), días hasta el cierre por defecto (7), si las encuestas nuevas son anónimas y si los cambios hechos fuera del panel se revisan.

Por cada tipo de sujeto que ofrezcan los módulos activos: la plantilla de su encuesta (sin plantilla no se prepara ninguna), si se envía sola y con cuántas horas de retraso, los días que queda abierta y si es anónima.
