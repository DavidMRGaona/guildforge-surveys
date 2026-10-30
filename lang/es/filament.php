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
        'fields' => [
            'name' => 'Título',
            'created_at' => 'Creada',
            'updated_at' => 'Actualizada',
        ],
    ],
];
