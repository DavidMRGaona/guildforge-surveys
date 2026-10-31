/**
 * Colours for the results charts. Series take the categorical slots in a fixed order, each with
 * its own step for the dark theme (checked for colour-blind separation on both surfaces); text,
 * grid and gaps take the site's own variables, so they follow the theme.
 */
const SERIES_LIGHT = [
    '#2a78d6',
    '#eb6834',
    '#1baf7a',
    '#eda100',
    '#e87ba4',
    '#008300',
    '#6250d6',
    '#e34948',
] as const;

const SERIES_DARK = [
    '#3987e5',
    '#d95926',
    '#199e70',
    '#c98500',
    '#d55181',
    '#008300',
    '#9085e9',
    '#e66767',
] as const;

export interface ChartTheme {
    series: readonly string[];
    text: string;
    grid: string;
    surface: string;
}

export function isDarkTheme(): boolean {
    return document.documentElement.classList.contains('dark');
}

/** The slot for the nth series; past the eighth they repeat, which the charts here never reach in practice. */
export function seriesColor(theme: ChartTheme, index: number): string {
    return theme.series[index % theme.series.length] ?? theme.series[0] ?? '#2a78d6';
}

export function chartTheme(): ChartTheme {
    const dark = isDarkTheme();
    const styles = getComputedStyle(document.documentElement);
    const variable = (name: string, fallback: string): string =>
        styles.getPropertyValue(name).trim() || fallback;

    return {
        series: dark ? SERIES_DARK : SERIES_LIGHT,
        text: variable('--color-text-secondary', dark ? '#d6d3d1' : '#57534e'),
        grid: variable('--color-border', dark ? '#44403c' : '#e7e5e4'),
        surface: variable('--color-bg-surface', dark ? '#302d2a' : '#ffffff'),
    };
}
