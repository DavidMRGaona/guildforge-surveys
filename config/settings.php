<?php

declare(strict_types=1);

return [
    // Responses an anonymous survey needs before its results show (0 = no minimum)
    'min_responses' => 3,
    // Days a new survey stays open by default
    'default_close_days' => 7,
    'anonymous_by_default' => true,
    // Whether a new general survey ticks «Anunciar en los canales» (needs a channel module)
    'announce_in_channels_by_default' => false,
    // Content changed outside the panel waits for a moderator (surveys.moderate)
    'review_required' => false,
    // Per kind of subject: template_id, auto_send, send_delay_hours (12), close_days, anonymous
    'subject_types' => [],
];
