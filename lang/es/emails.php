<?php

declare(strict_types=1);

return [
    'greeting' => '¡Hola!',
    'answer' => 'Responder la encuesta',
    'closes_at' => 'Puedes responder hasta el :date.',
    'anonymous' => 'La encuesta es anónima: tus respuestas no se guardan con tu nombre.',
    'named' => 'La encuesta no es anónima: tus respuestas se guardan con tu nombre.',

    'invitation' => [
        'subject' => 'Nueva encuesta: :title',
        'intro' => ':site te invita a responder la encuesta «:title».',
    ],

    'reminder' => [
        'subject' => 'Recordatorio: :title',
        'intro' => 'Todavía no has respondido la encuesta «:title» de :site. Se cierra pronto.',
    ],

    'ready_to_send' => [
        'subject' => '¿Quieres enviar la encuesta de :title?',
        'intro' => '«:title» ha terminado. Su encuesta de :site está lista para enviarse a quienes participaron.',
        'edit' => 'Puedes enviarla tal cual con un clic, cambiarla antes o no enviarla.',
        'action' => 'Ver la encuesta',
    ],

    'scheduled' => [
        'subject' => 'La encuesta de :title se enviará pronto',
        'intro' => '«:title» ha terminado. Su encuesta de :site se enviará sola el :date a quienes participaron.',
        'edit' => 'Hasta entonces puedes revisarla, enviarla ya o no enviarla.',
        'action' => 'Ver la encuesta',
    ],

    'submitted_for_review' => [
        'subject' => 'Encuesta pendiente de revisión: :title',
        'intro' => 'La encuesta «:title» de :site ha cambiado fuera del panel y espera tu revisión antes de enviarse.',
        'subject_line' => 'Es la encuesta de «:subject».',
        'action' => 'Revisar la encuesta',
    ],

    'review_approved' => [
        'subject' => 'Encuesta aprobada: :title',
        'intro' => 'Han aprobado tu encuesta «:title» de :site y ya se ha enviado.',
        'action' => 'Ver la encuesta',
    ],

    'review_rejected' => [
        'subject' => 'Encuesta rechazada: :title',
        'intro' => 'Han rechazado tu encuesta «:title» de :site. Puedes corregirla y volver a enviarla.',
        'reason' => 'Motivo: :reason',
        'action' => 'Corregir la encuesta',
    ],
];
