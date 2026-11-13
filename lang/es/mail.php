<?php

declare(strict_types=1);

return [
    'categories' => [
        'section' => 'Encuestas',

        'invitations' => [
            'label' => 'Invitaciones y recordatorios',
            'description' => 'Cuando se abre una encuesta para ti y el recordatorio antes de que cierre.',
        ],
        'results' => [
            'label' => 'Resultados al cerrar',
            'description' => 'El resumen de resultados cuando se cierra una encuesta que organizas o puedes ver.',
        ],
        'chosen_date' => [
            'label' => 'Fechas acordadas',
            'description' => 'La fecha elegida en un sondeo de disponibilidad que recibiste o respondiste.',
            'reason' => 'Cambia tus planes: te avisamos de la fecha elegida en un sondeo en el que participaste.',
        ],
        'organizer_actions' => [
            'label' => 'Encuestas que organizo: acciones pendientes',
            'description' => 'Cuando tienes que enviar la encuesta de una actividad o corregir una que se ha rechazado.',
            'reason' => 'Te pide una acción: enviar la encuesta o corregirla tras una revisión.',
        ],
        'organizer_notices' => [
            'label' => 'Encuestas que organizo: avisos',
            'description' => 'Cuando la encuesta de tu actividad se programa, se aprueba o se retira.',
        ],
        'moderation' => [
            'label' => 'Encuestas pendientes de revisión',
            'description' => 'Cuando una encuesta espera que alguien la revise.',
        ],
        'access_link' => [
            'label' => 'Enlace para responder',
            'description' => 'El enlace personal que pides para responder una encuesta.',
            'reason' => 'Lo has pedido tú.',
        ],
    ],

    'subjects' => [
        'survey' => 'Encuesta',
    ],
];
