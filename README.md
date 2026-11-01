# GuildForge - Módulo de encuestas

Encuestas para la plataforma GuildForge: formularios al estilo de un formulario web, sondeos rápidos de una pregunta y encuestas al terminar una mesa, una campaña, un torneo o un evento.

Requiere el core de GuildForge `^3.29`.

## Qué hace

- **Plantillas y encuestas** desde el panel, con secciones y diez tipos de pregunta: texto corto, párrafo, opción única, casillas, desplegable, escala, estrellas, fecha, hora y cuadrícula. Las preguntas de opciones admiten «Otra».
- **Sondeo rápido**: una pregunta de opción única o múltiple. Por defecto quien vota ve los resultados en directo.
- **Audiencia**: todas las cuentas, las personas socias, unos roles o las personas de un sujeto (una mesa, un torneo…) que ofrezca otro módulo. Se fija al abrir la encuesta. Enlace público opcional, en uno de dos modos: «Una respuesta por correo» (por defecto: pide un correo y envía a esa dirección un enlace de un solo uso, así cada dirección responde una vez) o «Abierto» (cualquiera con el enlace responde, tantas veces como quiera). Mientras la encuesta acepta respuestas, su ficha en el panel muestra el enlace con un botón para copiarlo y el modo, a quien puede ver la encuesta; con `surveys.manage` se puede cambiar el enlace si ha llegado donde no debía: el anterior deja de funcionar al momento (modal y casilla de confirmación), y las respuestas y los enlaces personales ya enviados se conservan.
- **Respuesta** con la cuenta, con el enlace de invitado o con el enlace público. Cada cuenta, invitado o dirección verificada responde una vez. Quien pide el enlace con un correo de la audiencia recibe su propio enlace, no uno nuevo. La dirección se compara en minúsculas y sin «+etiqueta», se guarda cifrada y se borra al cerrar la encuesta; cada dirección recibe como mucho 3 enlaces al día, y la página nunca dice si una dirección ya respondió.
- **Avisos**: correo al abrir, recordatorio opcional antes del cierre a quien no ha respondido y pestaña «Encuestas» en el perfil con las pendientes.
- **Resultados** en el panel, con un gráfico por pregunta (tipo de gráfico y preguntas ocultas configurables), la tasa de respuesta y la exportación a CSV y Excel. Si la encuesta lo permite, quien responde también ve los resultados.
- **Cierre** por fecha o a mano. Las encuestas solo se borran a mano, con doble confirmación.

## Encuestas de lo que organizas

Cuando termina algo que otro módulo ofrece como sujeto (una mesa, una campaña, un torneo, un evento), se prepara su encuesta con la plantilla configurada para ese tipo y se avisa por correo a quien lo organiza. Una sola encuesta por final: si el aviso de que ha terminado llega dos veces, no se duplica.

Desde la web, en la ficha del sujeto o en la pestaña «Encuestas» del perfil, quien organiza puede:

- **Enviarla** tal cual, **no enviarla** (el panel la sigue viendo) o **editarla** antes: textos, preguntas, opciones, fechas y ajustes. Las preguntas que vienen de la plantilla no cambian de tipo, para que se puedan comparar. Con la primera respuesta solo se pueden cambiar los textos de ayuda, la fecha de cierre y los ajustes.
- **Programarla**: si el tipo de sujeto tiene el envío automático, sale sola pasado el retraso configurado (12 horas por defecto, 0 para enviarla al terminar, sin máximo). El comando `surveys:send-scheduled` corre cada cinco minutos.
- **Prepararla antes de que termine**: si el tipo de sujeto tiene plantilla, la página de encuestas del sujeto ofrece «Preparar encuesta» en cualquier momento. Se parte de la plantilla, se edita igual y se elige cómo sale al terminar: sola, con su propio retraso en horas (por defecto, el del tipo), o con el aviso de un clic. Antes del final no se puede enviar a mano. Al terminar se usa esa encuesta, con los datos del sujeto de ese momento, en lugar de crear otra. Si el sujeto se cancela antes de terminar, las encuestas que aún no han salido se descartan y se avisa a quien lo organiza.
- **Preguntar a su gente cuando quiera**: la página de encuestas del sujeto ofrece «Nuevo sondeo» (una pregunta de opción única o de casillas, como en una aplicación de mensajería) y «Nueva encuesta» (un formulario completo, desde cero), mientras el sujeto no se haya cancelado. Puede haber tantas como quiera por sujeto. Se guardan como borrador y van a la audiencia del sujeto en el momento de enviarlas, con los datos del sujeto de ese momento. El anonimato y los días abierta parten del ajuste del tipo de sujeto; sin días, la encuesta queda abierta hasta que se cierra a mano.
- **Cerrarla a mano** mientras está abierta: deja de admitir respuestas y lo ya respondido se conserva.
- **Ver sus resultados** cuando ya ha salido, con la misma regla que el panel (una anónima espera a cerrar y al mínimo de respuestas): participación, una gráfica por pregunta con su tabla de datos, y el texto libre. Elige la gráfica de cada pregunta y cuáles se ocultan.

Cada acción comprueba en el momento que la persona sigue organizando el sujeto: si deja de hacerlo, o el módulo del sujeto se desactiva, pierde el acceso.

La ficha del sujeto, en su propio módulo, enlaza con sus encuestas a través del contrato del core `SurveySubjectLinksInterface`, que este módulo implementa: a quien lo organiza, con la página de encuestas del sujeto; a cada participante, con su encuesta mientras puede responderla y con sus resultados cuando la encuesta los comparte con quien responde (con las mismas reglas que la página de respuesta). Sin este módulo, la ficha no muestra nada.

## Revisión

Con el ajuste «Las encuestas cambiadas fuera del panel requieren revisión», una encuesta cuyo contenido se ha cambiado o escrito desde la web (los sondeos y encuestas propios de quien organiza, siempre) queda «En revisión» al enviarla y no sale hasta que alguien con `surveys.moderate` la apruebe en el panel (o la rechace con un motivo, que vuelve a quien la organiza). Una encuesta ya abierta que se cambia vuelve a revisión y deja de aceptar respuestas mientras tanto; mientras espera, su contenido no se puede tocar. Las fechas y los ajustes no son contenido y nunca pasan por revisión. Apagar el ajuste no aprueba las que ya esperan.

## Anonimato

Una encuesta anónima guarda sus respuestas sin ningún vínculo con la persona: la participación («ya ha respondido») va en otra tabla, y ninguna columna, orden ni hora permite unirlas. La respuesta solo guarda el día.

Sus resultados se muestran al cerrarla (o antes, si tiene los resultados en directo, que debilitan el anonimato) y solo con un mínimo de respuestas, que se aplica también a cada pregunta, al texto libre y a la exportación. El mínimo solo cuenta las respuestas con participación (cuentas, invitados y direcciones verificadas): las del enlace público abierto salen en los resultados, pero no ayudan a alcanzarlo, porque alguien podría enviar respuestas de relleno y deducir otra restándolas. La exportación de una encuesta anónima no lleva ni persona ni fecha. Nunca se muestra quién ha respondido.

La garantía es frente a quien usa la aplicación, panel incluido; quien tenga acceso directo a la base de datos queda fuera.

## Tendencias

Cómo evoluciona cada pregunta de una plantilla en las encuestas hechas con ella. Las preguntas se siguen por su clave estable, aunque cambie su texto. Las opciones, las filas y las columnas de la cuadrícula se siguen por su identificador, que se conserva al copiar la plantilla: las opciones y columnas que una encuesta añade o vuelve a crear cuentan en «Opciones anteriores», y sus filas nuevas no cuentan. Las preguntas añadidas a mano, sin clave, y las encuestas propias del organizador, sin plantilla, no entran.

- **Qué se dibuja**: la media por periodo en escalas y estrellas; el reparto por opción en opción única, desplegable y casillas (en casillas, el porcentaje de quienes respondieron); un gráfico por fila en la cuadrícula. El texto libre, la fecha y la hora no tienen tendencia.
- **Filtros**: tipo de sujeto, organizador, sistema de juego (el atributo `game_system` de la instantánea) y fechas. Se agrupa por mes, trimestre o año, según la fecha del sujeto (su inicio; en las encuestas generales, la apertura), en la zona horaria del sitio. Se puede comparar por organizador, sistema de juego o tipo de sujeto, con 8 series como mucho: el resto se junta en «Resto».
- **Anonimato**: solo cuentan las encuestas cuyos resultados ya se pueden ver por separado (cerradas o con resultados en directo y, en las anónimas, con el mínimo; cada pregunta, con el suyo). Así, combinar filtros nunca revela una encuesta que sola no se ve. Además, cada punto necesita el mínimo de respuestas verificadas.
- **Quién lo ve**: la organización, en el panel (*Encuestas → Tendencias*, o desde la tabla de plantillas), con `surveys.view_results`. Cada organizador, en la web (`/encuestas/tendencias`, enlazada desde la pestaña *Encuestas* del perfil), solo las encuestas en las que figura como organizador en la instantánea, sin filtrar ni comparar por organizador y sin las preguntas que cada encuesta oculta en sus resultados.

## Permisos

Ninguno se concede solo; se dan en el editor de roles.

| Permiso | Para qué |
|---|---|
| `surveys.manage` | Crear y editar plantillas y encuestas, abrirlas, cerrarlas y borrarlas |
| `surveys.view_results` | Ver los resultados, exportarlos y ver las tendencias |
| `surveys.moderate` | Revisar las encuestas creadas fuera del panel (llega con la creación desde la web) |

## Ajustes

En «Ajustes de encuestas», en el panel (con `surveys.manage`): mínimo de respuestas de las encuestas anónimas (3 por defecto; 0 lo quita), días hasta el cierre por defecto (7), si las encuestas nuevas son anónimas y si los cambios hechos fuera del panel se revisan.

Por cada tipo de sujeto que ofrezcan los módulos activos: la plantilla de su encuesta (sin plantilla no se prepara ninguna), si se envía sola y con cuántas horas de retraso, los días que queda abierta y si es anónima.
