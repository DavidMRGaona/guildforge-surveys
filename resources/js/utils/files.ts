/**
 * A file's size as people read it: megabytes from one up, otherwise kilobytes rounded up, so a
 * file never reads as weighing nothing.
 */
export function fileSize(bytes: number, locale: string): string {
    const megabytes = bytes / (1024 * 1024);

    return megabytes >= 1
        ? `${new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(megabytes)} MB`
        : `${new Intl.NumberFormat(locale).format(Math.max(1, Math.ceil(bytes / 1024)))} KB`;
}
