<?php

declare(strict_types=1);

namespace Modules\Surveys\Notifications;

/**
 * The bell links only to paths of this site. The mails carry absolute addresses; this keeps
 * their path, query and fragment, and gives nothing for an address of another host, so a
 * notice never leads out of the site.
 */
final class SiteNoticePath
{
    public static function of(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        if (isset($parts['host'])) {
            $own = parse_url((string) config('app.url'), PHP_URL_HOST);
            if (! is_string($own) || strcasecmp($own, $parts['host']) !== 0) {
                return null;
            }
        } elseif (isset($parts['scheme'])) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        return $path
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
