import {
    SUBMIT,
    type SurveyDefinition,
    type SurveyQuestion,
} from '../../../resources/js/types/Respond';

function question(
    id: string,
    type: SurveyQuestion['type'],
    extra: Partial<SurveyQuestion> = {}
): SurveyQuestion {
    return {
        id,
        key: null,
        type,
        title: `Pregunta ${id}`,
        help: null,
        required: false,
        options: [],
        allow_other: false,
        scale: null,
        stars: null,
        grid: null,
        next_by_option: [],
        ...extra,
    };
}

const options = [
    { id: 'o-1', label: 'Uno' },
    { id: 'o-2', label: 'Dos' },
];

/** One question of each of the ten types, as SurveyDefinition::toArray() sends them. */
export const everyType: SurveyDefinition = {
    sections: [
        {
            id: 's-1',
            title: 'Sobre la partida',
            description: null,
            next: null,
            questions: [
                question('q-short', 'short_text', {
                    required: true,
                    help: 'Como te llamen en la mesa',
                }),
                question('q-paragraph', 'paragraph'),
                question('q-single', 'single_choice', { options, allow_other: true }),
                question('q-multiple', 'multiple_choice', { options }),
                question('q-dropdown', 'dropdown', { options }),
                question('q-scale', 'linear_scale', {
                    scale: { min: 1, max: 5, min_label: 'Nada', max_label: 'Mucho' },
                }),
                question('q-stars', 'star_rating', { stars: 5 }),
                question('q-date', 'date'),
                question('q-time', 'time'),
                question('q-grid', 'grid', {
                    grid: {
                        rows: [
                            { id: 'r-1', label: 'Ambiente' },
                            { id: 'r-2', label: 'Ritmo' },
                        ],
                        columns: [
                            { id: 'c-1', label: 'Mal' },
                            { id: 'c-2', label: 'Bien' },
                        ],
                    },
                }),
            ],
        },
    ],
};

/**
 * Four sections with jumps: "Part" goes to s-part, "None" ends the survey and s-full goes
 * straight to s-end, skipping s-part. The first two questions are required.
 */
export const branching: SurveyDefinition = {
    sections: [
        {
            id: 's-intro',
            title: null,
            description: null,
            next: null,
            questions: [
                question('q-played', 'single_choice', {
                    required: true,
                    options: [
                        { id: 'o-all', label: 'Entera' },
                        { id: 'o-part', label: 'Una parte' },
                        { id: 'o-none', label: 'Nada' },
                    ],
                    next_by_option: [
                        { option_id: 'o-part', next: 's-part' },
                        { option_id: 'o-none', next: SUBMIT },
                    ],
                }),
            ],
        },
        {
            id: 's-full',
            title: 'El final',
            description: null,
            next: 's-end',
            questions: [question('q-finale', 'paragraph', { required: true })],
        },
        {
            id: 's-part',
            title: 'Por qué lo dejaste',
            description: null,
            next: null,
            questions: [question('q-why', 'short_text')],
        },
        {
            id: 's-end',
            title: null,
            description: null,
            next: null,
            questions: [question('q-comments', 'paragraph')],
        },
    ],
};
