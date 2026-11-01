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

    'access_link' => [
        'subject' => 'Tu enlace para responder: :title',
        'intro' => 'Has pedido responder la encuesta «:title» de :site.',
        'personal' => 'El enlace es personal y solo sirve para una respuesta: no lo compartas.',
        'not_you' => 'Si no lo has pedido, ignora este correo.',
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

    'withdrawn_with_subject' => [
        'subject' => 'La encuesta de :title no se enviará',
        'intro' => '«:title» se ha cancelado antes de terminar, así que la encuesta «:survey» de :site no se enviará.',
        'kept' => 'La encuesta se conserva sin enviar por si la necesitas más adelante.',
    ],

    'closing_summary' => [
        'subject' => 'Resultados de la encuesta «:title»',
        'intro' => 'La encuesta «:title» de :site se ha cerrado.',
        'intro_subject' => 'La encuesta «:title» de :site, sobre «:subject», se ha cerrado.',
        'below_minimum' => 'Es anónima y no ha llegado al mínimo de respuestas, así que no se muestra ningún dato: con tan pocas, se podría saber qué respondió cada persona.',
        'no_responses' => 'Nadie ha respondido.',
        'responses' => 'Ha recibido :count respuesta.|Ha recibido :count respuestas.',
        'rate' => 'Ha respondido :responses de :audience persona (:rate %).|Han respondido :responses de :audience personas (:rate %).',
        'question_top' => '**:title**: la más votada es :options (:count de :answered).|**:title**: las más votadas son :options (:count de :answered cada una).',
        'question_mean' => '**:title**: media de :mean, de :min a :max (:answered respuesta).|**:title**: media de :mean, de :min a :max (:answered respuestas).',
        'question_unanswered' => '**:title**: sin respuestas.',
        'question_below_minimum' => '**:title**: tiene pocas respuestas para mostrarla.',
        'other_questions' => 'Hay :count pregunta más en los resultados completos.|Hay :count preguntas más en los resultados completos.',
        'other' => 'Otra',
        'and' => 'y',
        'action' => 'Ver los resultados',
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
