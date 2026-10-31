<?php

declare(strict_types=1);

return [
    'title' => 'Encuestas',

    'permissions' => [
        'manage' => 'Gestionar encuestas, plantillas y ajustes',
        'manage_description' => 'Crear, editar, abrir, cerrar y borrar encuestas y plantillas.',
        'view_results' => 'Ver los resultados de las encuestas',
        'view_results_description' => 'Ver resultados y exportar respuestas. En las anónimas nunca se ve quién respondió.',
        'moderate' => 'Revisar encuestas creadas fuera del panel',
        'moderate_description' => 'Aprobar o rechazar, con motivo, las encuestas que los organizadores crean en la web.',
    ],

    'settings' => [
        'results' => 'Resultados',
        'min_responses' => 'Mínimo de respuestas',
        'min_responses_help' => 'Respuestas que necesita una encuesta anónima para mostrar sus resultados, el texto libre y la exportación. Con 0 no hay mínimo.',
        'defaults' => 'Valores por defecto de las encuestas nuevas',
        'default_close_days' => 'Días abierta',
        'default_close_days_help' => 'Días que pasa abierta una encuesta nueva antes de cerrarse sola.',
        'days' => 'días',
        'anonymous_by_default' => 'Anónima por defecto',
        'anonymous_by_default_help' => 'Una encuesta anónima no guarda quién respondió qué y nunca puede dejar de serlo.',
    ],

    'definition_errors' => [
        'malformed' => 'El contenido no tiene el formato esperado.',
        'unknown_type' => 'Tipo de pregunta desconocido.',
        'no_questions' => 'Añade al menos una pregunta.',
        'required_id' => 'Falta el identificador interno: vuelve a crear este elemento.',
        'duplicate_id' => 'Este elemento está repetido: vuelve a crearlo.',
        'required_title' => 'Escribe la pregunta.',
        'invalid_key' => 'La clave solo admite minúsculas, números y guiones bajos, y empieza por una letra.',
        'duplicate_key' => 'Ya hay otra pregunta con esta clave.',
        'too_few_options' => 'Añade al menos dos opciones.',
        'required_label' => 'Escribe el texto de la opción.',
        'options_not_allowed' => 'Este tipo de pregunta no lleva opciones.',
        'other_not_allowed' => '«Otra» no se admite en este tipo de pregunta.',
        'scale_required' => 'Indica el rango de la escala.',
        'invalid_scale_min' => 'La escala empieza en 0 o en 1.',
        'invalid_scale_max' => 'La escala termina entre 2 y 10.',
        'invalid_stars' => 'Elige entre 3 y 10 estrellas.',
        'grid_required' => 'Indica las filas y columnas de la cuadrícula.',
        'too_few_rows' => 'Añade al menos una fila.',
        'too_few_columns' => 'Añade al menos dos columnas.',
        'poll_single_question' => 'Un sondeo rápido tiene exactamente una pregunta.',
        'poll_type' => 'Un sondeo rápido es de opción única o de casillas.',
        'type_locked' => 'Esta pregunta tiene clave estable: no puede cambiar de tipo. Crea una pregunta nueva con otra clave.',
        'locked_by_responses' => 'La encuesta ya tiene respuestas: solo se pueden cambiar los textos de ayuda y la fecha de cierre.',
    ],

    'state_errors' => [
        'anonymity' => 'Una encuesta anónima nunca deja de serlo, y una nominal con respuestas ya no puede pasar a anónima.',
        'audience_fixed' => 'La audiencia se fija al abrir la encuesta: ya no se puede cambiar.',
        'closing_date' => 'El cierre de una encuesta abierta no puede quedar en el pasado, y una encuesta cerrada no se reprograma.',
        'locked_by_responses' => 'La encuesta ya tiene respuestas: solo se pueden cambiar los textos de ayuda y la fecha de cierre.',
    ],

    'response' => [
        'title' => 'Encuesta',
        'already_answered' => 'Ya has respondido esta encuesta.',
        'closed' => 'La encuesta está cerrada.',
        'not_invited' => 'No puedes responder esta encuesta.',
    ],

    'submission_errors' => [
        'required' => 'Esta pregunta es obligatoria.',
        'invalid_option' => 'Elige una de las opciones.',
        'too_many_options' => 'Elige solo una opción.',
        'duplicate_option' => 'Has marcado la misma opción dos veces.',
        'other_required' => 'Escribe tu respuesta en «Otra».',
        'out_of_range' => 'El valor está fuera de la escala.',
        'invalid_date' => 'Escribe una fecha válida.',
        'invalid_time' => 'Escribe una hora válida.',
        'grid_incomplete' => 'Responde todas las filas.',
        'too_long' => 'La respuesta es demasiado larga.',
    ],

    'export' => [
        'date' => 'Fecha',
        'person' => 'Persona',
        'other' => 'Otra: :text',
    ],
];
