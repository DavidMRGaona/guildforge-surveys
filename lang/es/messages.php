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
];
