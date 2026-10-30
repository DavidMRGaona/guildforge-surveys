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
];
