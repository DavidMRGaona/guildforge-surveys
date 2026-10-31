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

En los ajustes del módulo, en el panel: mínimo de respuestas de las encuestas anónimas (3 por defecto; 0 lo quita), días hasta el cierre por defecto (7) y si las encuestas nuevas son anónimas.
