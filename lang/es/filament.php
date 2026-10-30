<?php

declare(strict_types=1);

return [
    'navigation_group' => 'Encuestas',

    'fields' => [
        'kind' => 'Tipo',
        'questions_count' => 'Preguntas',
    ],

    'kinds' => [
        'form' => 'Formulario',
        'poll' => 'Sondeo rápido',
    ],

    'statuses' => [
        'draft' => 'Borrador',
        'open' => 'Abierta',
        'closed' => 'Cerrada',
    ],

    'audiences' => [
        'all_users' => 'Todas las personas con cuenta',
        'association_members' => 'Solo socios',
        'roles' => 'Algunos roles',
        'subject' => 'Participantes del sujeto',
    ],

    'question_types' => [
        'short_text' => 'Respuesta corta',
        'paragraph' => 'Párrafo',
        'single_choice' => 'Opción única',
        'multiple_choice' => 'Casillas',
        'dropdown' => 'Desplegable',
        'linear_scale' => 'Escala lineal',
        'star_rating' => 'Valoración con estrellas',
        'date' => 'Fecha',
        'time' => 'Hora',
        'grid' => 'Cuadrícula de opción única',
    ],

    'definition' => [
        'title' => 'Preguntas',
        'sections' => 'Secciones',
        'section_title' => 'Título de la sección',
        'section_description' => 'Descripción de la sección',
        'questions' => 'Preguntas',
        'add_section' => 'Añadir sección',
        'add_question' => 'Añadir pregunta',
        'question_title' => 'Pregunta',
        'key' => 'Clave estable',
        'key_help' => 'Identifica la pregunta en todas las encuestas hechas con esta plantilla, para comparar resultados en el tiempo. En minúsculas, sin espacios (p. ej. «diversion»). Una pregunta con clave no puede cambiar de tipo.',
        'help' => 'Texto de ayuda',
        'required' => 'Obligatoria',
        'options' => 'Opciones',
        'add_option' => 'Añadir opción',
        'allow_other' => 'Permitir «Otra» con texto libre',
        'scale_min' => 'Desde',
        'scale_max' => 'Hasta',
        'scale_min_label' => 'Etiqueta del mínimo',
        'scale_max_label' => 'Etiqueta del máximo',
        'stars' => 'Número de estrellas',
        'grid_rows' => 'Filas',
        'grid_columns' => 'Columnas',
    ],

    'survey_template' => [
        'label' => 'plantilla',
        'plural_label' => 'Plantillas',
        'sections' => [
            'general' => 'Plantilla',
        ],
        'definition_help' => 'Las encuestas creadas con esta plantilla copian sus preguntas y conservan sus claves.',
        'fields' => [
            'name' => 'Nombre',
            'description' => 'Descripción',
            'created_at' => 'Creada',
            'updated_at' => 'Actualizada',
        ],
    ],

    'survey' => [
        'label' => 'encuesta',
        'plural_label' => 'Encuestas',
        'sections' => [
            'general' => 'Encuesta',
            'audience' => 'A quién va dirigida',
            'schedule' => 'Plazo',
            'results' => 'Resultados',
        ],
        'locked_by_responses' => 'La encuesta ya tiene respuestas: solo se pueden cambiar los textos de ayuda y la fecha de cierre.',
        'fields' => [
            'template' => 'Partir de una plantilla',
            'template_help' => 'Copia las preguntas de la plantilla. Después puedes cambiarlas.',
            'title' => 'Título',
            'description' => 'Descripción',
            'status' => 'Estado',
            'anonymous' => 'Anónima',
            'anonymous_help' => 'No se guarda quién respondió qué. Una encuesta anónima nunca deja de serlo.',
            'audience' => 'Audiencia',
            'audience_roles' => 'Roles',
            'public_link' => 'Enlace público abierto',
            'public_link_help' => 'Cualquiera con el enlace puede responder sin cuenta. No garantiza que cada persona responda una sola vez.',
            'closes_at' => 'Cierre',
            'reminder' => 'Recordatorio',
            'reminder_help' => 'Horas antes del cierre en que se recuerda la encuesta a quien no ha respondido. Vacío: sin recordatorio.',
            'hours' => 'horas',
            'live_results' => 'Resultados en directo',
            'live_results_help' => 'Los resultados se ven mientras la encuesta está abierta.',
            'live_results_anonymous_help' => 'Los resultados se ven mientras la encuesta está abierta. En una encuesta anónima esto debilita el anonimato: comparar los resultados antes y después de una respuesta puede delatarla. Se sigue aplicando el mínimo de respuestas.',
            'visible_to_respondents' => 'Quien responde ve los resultados',
            'responses' => 'Respuestas',
            'created_at' => 'Creada',
            'updated_at' => 'Actualizada',
        ],
    ],

    'actions' => [
        'open' => 'Abrir',
        'open_description' => 'La encuesta empieza a aceptar respuestas y su audiencia queda fijada.',
        'open_done' => 'Encuesta abierta',
        'open_failed' => 'No se puede abrir: su fecha de cierre ya ha pasado o ya no es un borrador.',
        'close' => 'Cerrar',
        'close_description' => 'La encuesta deja de aceptar respuestas. No se puede volver a abrir.',
        'close_done' => 'Encuesta cerrada',
        'close_failed' => 'No se puede cerrar: la encuesta no está abierta.',
        'delete_description' => 'Se borran la encuesta y todas sus respuestas. No se puede deshacer.',
        'delete_confirmation' => 'Entiendo que se borrarán todas las respuestas para siempre',
    ],

    'quick_poll' => [
        'action' => 'Sondeo rápido',
        'heading' => 'Nuevo sondeo rápido',
        'question' => 'Pregunta',
        'multiple' => 'Permitir elegir varias opciones',
        'invalid' => 'El sondeo no es válido: revisa la pregunta y las opciones.',
    ],
];
