<?php

declare(strict_types=1);

/*
 * Texts of the recommended templates. They are copied into the database when the templates are
 * seeded, so changing them here only reaches new installations.
 */
return [
    'shared' => [
        'pacing' => [
            'too_slow' => 'Demasiado lento',
            'bit_slow' => 'Algo lento',
            'right' => 'Adecuado',
            'bit_fast' => 'Algo rápido',
            'too_fast' => 'Demasiado rápido',
        ],
        'repeat' => [
            'yes' => 'Sí, seguro',
            'maybe' => 'Puede ser',
            'no' => 'No',
        ],
        'aspects' => [
            'improvable' => 'Mejorable',
            'good' => 'Bien',
            'very_good' => 'Muy bien',
        ],
        'comments' => 'Comentarios',
    ],

    'game_table' => [
        'name' => 'Partida de rol',
        'description' => 'Para mesas sueltas: cómo fue la partida, el director y la mesa.',
        'sections' => ['main' => 'La partida'],
        'questions' => [
            'overall' => ['title' => '¿Qué tal fue la partida?'],
            'game_master' => ['title' => 'El director de juego', 'min' => 'Mejorable', 'max' => 'Excelente'],
            'pacing' => ['title' => 'El ritmo de la partida'],
            'aspects' => [
                'title' => 'Valora estos aspectos',
                'rows' => [
                    'story' => 'Historia',
                    'setting' => 'Ambientación',
                    'roleplay' => 'Interpretación',
                    'combat' => 'Combate',
                    'exploration' => 'Exploración',
                ],
            ],
            'safety_tools' => [
                'title' => 'Las herramientas de seguridad (líneas y velos, tarjeta X…)',
                'options' => [
                    'worked' => 'Se usaron y funcionaron bien',
                    'improvable' => 'Se usaron, pero se pueden mejorar',
                    'not_needed' => 'No se usaron y no hicieron falta',
                    'missed' => 'No se usaron y habrían hecho falta',
                    'unknown' => 'No sé qué son',
                ],
            ],
            'would_repeat' => ['title' => '¿Repetirías con esta mesa?'],
            'comments' => ['title' => '¿Algo que quieras contar al director o a la asociación?'],
        ],
    ],

    'campaign_session' => [
        'name' => 'Sesión de campaña',
        'description' => 'Corta, para responder tras cada sesión sin cansar.',
        'sections' => ['main' => 'La sesión'],
        'questions' => [
            'overall' => ['title' => '¿Qué tal la sesión?'],
            'spotlight' => ['title' => '¿Tu personaje tuvo su momento?', 'min' => 'Nada', 'max' => 'Mucho'],
            'pacing' => ['title' => 'El ritmo de la sesión'],
            'next_session' => ['title' => '¿Qué te gustaría para la próxima sesión?'],
        ],
    ],

    'campaign' => [
        'name' => 'Campaña completa',
        'description' => 'Al cerrar una campaña: el arco, el final y lo que viene.',
        'sections' => ['main' => 'La campaña', 'next' => 'Lo siguiente'],
        'questions' => [
            'overall' => ['title' => 'La campaña en conjunto'],
            'story_arc' => ['title' => 'La historia y su arco', 'min' => 'Floja', 'max' => 'Memorable'],
            'ending' => [
                'title' => 'El final',
                'help' => 'Si la campaña quedó sin terminar, déjala en blanco.',
                'min' => 'Decepcionante',
                'max' => 'Redondo',
            ],
            'game_master' => ['title' => 'El director de juego', 'min' => 'Mejorable', 'max' => 'Excelente'],
            'aspects' => [
                'title' => 'Valora estos aspectos',
                'rows' => [
                    'characters' => 'Personajes',
                    'setting' => 'Ambientación',
                    'intrigue' => 'Intriga',
                    'combat' => 'Combate',
                    'exploration' => 'Exploración',
                ],
            ],
            'sequel' => ['title' => '¿Te apuntarías a una continuación?'],
            'next_tone' => [
                'title' => '¿Qué tono te apetece para la siguiente?',
                'options' => [
                    'epic' => 'Épico',
                    'dark' => 'Oscuro',
                    'horror' => 'Terror',
                    'mystery' => 'Misterio e investigación',
                    'humour' => 'Humor',
                    'science_fiction' => 'Ciencia ficción',
                    'adventure' => 'Aventura y exploración',
                ],
            ],
            'next_focus' => [
                'title' => 'Ordena lo que más te apetece en la siguiente',
                'help' => 'Lo primero es lo que más te apetece.',
                'options' => [
                    'story' => 'Historia y trama',
                    'roleplay' => 'Interpretar a los personajes',
                    'combat' => 'Combate',
                    'exploration' => 'Exploración',
                    'intrigue' => 'Intriga y misterio',
                ],
            ],
            'next_system' => ['title' => '¿Qué sistema te gustaría jugar?'],
        ],
    ],

    'association_event' => [
        'name' => 'Evento de la asociación',
        'description' => 'Jornadas, puertas abiertas y otros eventos.',
        'sections' => ['main' => 'El evento'],
        'questions' => [
            'overall' => ['title' => '¿Qué tal el evento?'],
            'activities_joined' => [
                'title' => '¿En qué participaste?',
                'options' => [
                    'roleplaying' => 'Partidas de rol',
                    'wargames' => 'Wargames',
                    'board_games' => 'Juegos de mesa',
                    'tournament' => 'Torneo',
                    'painting' => 'Pintura de miniaturas',
                    'talks' => 'Talleres o charlas',
                ],
            ],
            'activities' => ['title' => 'Las actividades', 'min' => 'Pocas o flojas', 'max' => 'Muy variadas'],
            'schedule' => ['title' => 'Los horarios', 'min' => 'Muy incómodos', 'max' => 'Muy cómodos'],
            'venue' => ['title' => 'El local', 'min' => 'Mejorable', 'max' => 'Excelente'],
            'prior_info' => [
                'title' => 'La información previa',
                'options' => [
                    'complete' => 'Completa y a tiempo',
                    'enough' => 'Suficiente',
                    'scarce' => 'Escasa o tardía',
                ],
            ],
            'recommend' => ['title' => '¿Traerías a alguien la próxima vez?', 'min' => 'Seguro que no', 'max' => 'Seguro que sí'],
            'how_heard' => [
                'title' => '¿Cómo te enteraste?',
                'options' => [
                    'website' => 'Web de la asociación',
                    'social' => 'Redes sociales',
                    'friend' => 'Por un amigo o una amiga',
                    'poster' => 'Cartel o folleto',
                ],
            ],
        ],
    ],

    'tournament' => [
        'name' => 'Torneo de wargames',
        'description' => 'Para torneos de cualquier wargame. Las variantes por juego añaden una sección propia.',
        'sections' => ['main' => 'El torneo'],
        'questions' => [
            'overall' => ['title' => '¿Qué tal el torneo?'],
            'organization' => ['title' => 'La organización', 'min' => 'Mejorable', 'max' => 'Excelente'],
            'schedule' => [
                'title' => 'Los horarios',
                'options' => [
                    'very_tight' => 'Muy apretados',
                    'bit_tight' => 'Algo apretados',
                    'right' => 'Adecuados',
                    'too_much_waiting' => 'Con demasiadas esperas',
                ],
            ],
            'aspects' => [
                'title' => 'Valora estos aspectos',
                'rows' => [
                    'missions' => 'Misiones',
                    'terrain' => 'Escenografía',
                    'venue' => 'Sede',
                ],
            ],
            'sportsmanship' => ['title' => 'La deportividad de tus rivales', 'min' => 'Mala', 'max' => 'Excelente'],
            'price' => [
                'title' => 'El precio de la inscripción',
                'options' => [
                    'cheap' => 'Barato',
                    'fair' => 'Justo',
                    'expensive' => 'Caro',
                ],
            ],
            'would_return' => ['title' => '¿Volverías?'],
            'format' => [
                'title' => '¿Qué formato prefieres?',
                'options' => [
                    'one_day' => 'Un día',
                    'two_days' => 'Dos días',
                    'league' => 'Liga de varias semanas',
                ],
            ],
        ],
    ],

    // Questions shared by the variants of 40K, Age of Sigmar and Kill Team
    'wargame' => [
        'questions' => [
            'preferred_points' => [
                'title' => 'Tamaño de partida que prefieres',
                'options' => [
                    'points_1000' => '1000 puntos',
                    'points_1500' => '1500 puntos',
                    'points_2000' => '2000 puntos',
                    'any' => 'Me da igual',
                ],
            ],
            'round_time' => [
                'title' => 'El tiempo por ronda',
                'options' => [
                    'short' => 'Corto',
                    'right' => 'Justo',
                    'plenty' => 'Sobrado',
                ],
            ],
            'chess_clock' => [
                'title' => 'El reloj de ajedrez',
                'options' => [
                    'required' => 'Obligatorio',
                    'optional' => 'Opcional',
                    'none' => 'Sin reloj',
                ],
            ],
            'painting_rule' => [
                'title' => 'Requisito de pintado',
                'options' => [
                    'fully_painted' => 'Todo pintado',
                    'three_colours' => 'Mínimo de tres colores',
                    'none' => 'Sin requisito',
                ],
            ],
            'terrain_density' => [
                'title' => 'La escenografía de las mesas',
                'options' => [
                    'sparse' => 'Escasa',
                    'right' => 'Adecuada',
                    'excessive' => 'Excesiva',
                ],
            ],
        ],
    ],

    'tournament_40k' => [
        'name' => 'Torneo de Warhammer 40.000',
        'description' => 'El torneo de wargames con una sección propia de Warhammer 40.000.',
        'sections' => ['game' => 'Warhammer 40.000'],
        'questions' => [],
    ],

    'tournament_aos' => [
        'name' => 'Torneo de Age of Sigmar',
        'description' => 'El torneo de wargames con una sección propia de Age of Sigmar.',
        'sections' => ['game' => 'Age of Sigmar'],
        'questions' => [
            'battleplans' => ['title' => 'La selección de planes de batalla', 'min' => 'Poco variada', 'max' => 'Muy acertada'],
        ],
    ],

    'tournament_kill_team' => [
        'name' => 'Torneo de Kill Team',
        'description' => 'El torneo de wargames con una sección propia de Kill Team.',
        'sections' => ['game' => 'Kill Team'],
        'questions' => [
            'killzones' => ['title' => 'Las killzones y su escenografía', 'min' => 'Mejorables', 'max' => 'Excelentes'],
            'rounds' => [
                'title' => 'Número de rondas que prefieres',
                'options' => [
                    'three' => '3',
                    'four' => '4',
                    'five' => '5',
                ],
            ],
            'team_balance' => ['title' => 'El equilibrio entre equipos', 'min' => 'Muy desequilibrado', 'max' => 'Muy equilibrado'],
        ],
    ],

    'tournament_blood_bowl' => [
        'name' => 'Torneo de Blood Bowl',
        'description' => 'El torneo de wargames con una sección propia de Blood Bowl.',
        'sections' => ['game' => 'Blood Bowl'],
        'questions' => [
            'team_rules' => ['title' => 'Las reglas de creación de equipos', 'min' => 'Mejorables', 'max' => 'Muy acertadas'],
            'team_tiers' => ['title' => 'El equilibrio entre razas', 'min' => 'Muy desequilibrado', 'max' => 'Muy equilibrado'],
            'inducements' => [
                'title' => 'Los incentivos (inducements)',
                'options' => [
                    'scarce' => 'Escasos',
                    'balanced' => 'Equilibrados',
                    'too_generous' => 'Demasiado generosos',
                ],
            ],
            'match_length' => [
                'title' => 'La duración de los partidos',
                'options' => [
                    'short' => 'Corta',
                    'right' => 'Adecuada',
                    'long' => 'Larga',
                ],
            ],
        ],
    ],

    'member_satisfaction' => [
        'name' => 'Satisfacción de los socios',
        'description' => 'Para pasarla cada año o cada temporada y ver cómo evoluciona.',
        'sections' => ['main' => 'La asociación'],
        'questions' => [
            'satisfaction' => ['title' => 'Tu satisfacción con la asociación'],
            'recommend' => ['title' => '¿Recomendarías la asociación a alguien?', 'min' => 'Seguro que no', 'max' => 'Seguro que sí'],
            'areas' => [
                'title' => 'Valora estos aspectos',
                'rows' => [
                    'activities' => 'Actividades',
                    'venue' => 'Local',
                    'communication' => 'Comunicación',
                    'fee' => 'Cuota',
                    'atmosphere' => 'Ambiente',
                ],
            ],
            'attendance' => [
                'title' => '¿Con qué frecuencia vienes?',
                'options' => [
                    'weekly' => 'Cada semana',
                    'twice_monthly' => 'Dos o tres veces al mes',
                    'monthly' => 'Una vez al mes',
                    'less' => 'Menos',
                ],
            ],
            'help_out' => [
                'title' => '¿Te animarías a echar una mano?',
                'options' => [
                    'yes' => 'Sí, cuenta conmigo',
                    'sometimes' => 'De vez en cuando',
                    'not_now' => 'Ahora no',
                ],
            ],
            'improve' => ['title' => '¿Qué mejorarías?'],
        ],
    ],

    'activity_interest' => [
        'name' => 'Interés por actividades y sistemas',
        'description' => 'Para decidir qué organizar la próxima temporada.',
        'sections' => ['main' => 'Lo que te interesa'],
        'questions' => [
            'activities' => [
                'title' => '¿Qué te gustaría que hubiera?',
                'options' => [
                    'introductory' => 'Partidas de iniciación',
                    'one_shots' => 'Mesas sueltas',
                    'campaigns' => 'Campañas largas',
                    'tournaments' => 'Torneos',
                    'leagues' => 'Ligas',
                    'board_games' => 'Juegos de mesa',
                    'painting' => 'Talleres de pintura',
                    'talks' => 'Charlas',
                ],
            ],
            'rpg_systems' => [
                'title' => 'Juegos de rol que te gustaría jugar',
                'options' => [
                    'dnd' => 'Dungeons & Dragons',
                    'pathfinder' => 'Pathfinder',
                    'cthulhu' => 'La llamada de Cthulhu',
                    'vampire' => 'Vampiro: la mascarada',
                    'warhammer_fantasy' => 'Warhammer Fantasy',
                    'narrative' => 'Juegos narrativos (PbtA, Forged in the Dark)',
                ],
            ],
            'wargames' => [
                'title' => 'Wargames que te gustaría jugar',
                'options' => [
                    'warhammer_40k' => 'Warhammer 40.000',
                    'age_of_sigmar' => 'Age of Sigmar',
                    'kill_team' => 'Kill Team',
                    'blood_bowl' => 'Blood Bowl',
                    'necromunda' => 'Necromunda',
                    'old_world' => 'The Old World',
                ],
            ],
            'run_games' => [
                'title' => '¿Te animarías a dirigir u organizar?',
                'options' => [
                    'yes' => 'Sí',
                    'with_help' => 'Con ayuda',
                    'no' => 'No',
                ],
            ],
            'comments' => ['title' => '¿Algo más que te gustaría ver?'],
        ],
    ],

    'availability' => [
        'name' => 'Disponibilidad de días y franjas',
        'description' => 'Para saber qué días y franjas de la semana suelen ir bien. Mejor nominal: así sabes quién puede cada día. Para elegir entre fechas concretas, usa un sondeo de disponibilidad.',
        'sections' => ['main' => 'Tu disponibilidad'],
        'questions' => [
            'days' => [
                'title' => '¿Qué días sueles poder venir?',
                'rows' => [
                    'monday' => 'Lunes',
                    'tuesday' => 'Martes',
                    'wednesday' => 'Miércoles',
                    'thursday' => 'Jueves',
                    'friday' => 'Viernes',
                    'saturday' => 'Sábado',
                    'sunday' => 'Domingo',
                ],
                'columns' => [
                    'rarely' => 'No suelo poder',
                    'sometimes' => 'A veces',
                    'usually' => 'Casi siempre',
                ],
            ],
            'time_slots' => [
                'title' => '¿En qué franjas?',
                'options' => [
                    'morning' => 'Mañana',
                    'midday' => 'Mediodía',
                    'afternoon' => 'Tarde',
                    'night' => 'Noche',
                ],
            ],
            'start_time' => ['title' => '¿A qué hora te viene bien empezar?'],
            'proposed_date' => ['title' => 'Si tuvieras que proponer una fecha para la próxima quedada'],
        ],
    ],
];
