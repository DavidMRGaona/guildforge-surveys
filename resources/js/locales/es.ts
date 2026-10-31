export default {
    surveys: {
        title: 'Encuestas',
        profile: {
            tab_label: 'Encuestas',
            title: 'Encuestas pendientes',
            empty_title: 'No tienes encuestas pendientes',
            empty_description:
                'Cuando te envíen una encuesta, aparecerá aquí hasta que la respondas.',
            answer: 'Responder',
        },
        manage: {
            profile_title: 'De lo que organizas',
            subject_title: 'Encuestas de {title}',
            back_to_subject: 'Volver a la ficha',
            empty_title: 'Aún no hay encuestas',
            empty_description: 'Cuando termine, su encuesta aparecerá aquí.',
            statuses: {
                to_send: 'Pendiente de enviar',
                scheduled: 'Programada',
                pending_review: 'En revisión',
                rejected: 'Rechazada',
                open: 'Abierta',
                closed: 'Cerrada',
            },
            to_send_hint: 'Puedes enviarla tal cual o no enviarla.',
            scheduled_at: 'Se enviará el {date}',
            pending_review_hint: 'Saldrá cuando alguien la apruebe.',
            rejection_reason: 'Motivo del rechazo: {reason}',
            response_rate: '{responses} de {audience} han respondido',
            send: 'Enviar',
            send_now: 'Enviar ahora',
            dismiss: 'No enviar',
            dismiss_title: '¿No enviar esta encuesta?',
            dismiss_message:
                'Nadie la recibirá y dejará de aparecer aquí. La organización la seguirá viendo en el panel.',
            cancel: 'Cancelar',
        },
        respond: {
            required: 'Obligatoria',
            required_hint: 'Las preguntas marcadas con * son obligatorias.',
            named_notice: 'Esta encuesta no es anónima: tus respuestas se guardan con tu nombre.',
            anonymous_notice:
                'Esta encuesta es anónima: tus respuestas no se guardan con tu nombre.',
            closes_at: 'Abierta hasta el {date}',
            other: 'Otra',
            other_placeholder: 'Escribe tu respuesta',
            choose: 'Elige una opción',
            stars: '{count} de {total} estrellas',
            submit: 'Enviar respuestas',
            submit_poll: 'Votar',
            thanks_title: '¡Gracias por responder!',
            thanks_description: 'Tus respuestas se han guardado.',
            states: {
                answered: 'Ya has respondido esta encuesta.',
                closed: 'Esta encuesta está cerrada.',
                not_invited:
                    'No puedes responder esta encuesta. Si te han enviado un enlace, comprueba que lo has abierto entero y que no ha caducado.',
            },
            back_home: 'Volver al inicio',
        },
        results: {
            title: 'Resultados',
            responses: 'Sin respuestas | {count} respuesta | {count} respuestas',
            answered:
                'Nadie ha respondido | {count} persona ha respondido | {count} personas han respondido',
            below_minimum: 'Aún no hay respuestas suficientes a esta pregunta para mostrarlas.',
            mean_median: 'Media: {mean} · Mediana: {median}',
            percent: '{percent} %',
            voters: 'Han votado: {names}',
            other: 'Otra',
        },
    },
};
