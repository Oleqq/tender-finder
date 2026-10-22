/** Keep browser chrome in sync with the palette actually rendered by CSS. */
export function syncThemeColor(): string {
    const background = getComputedStyle(document.documentElement)
        .getPropertyValue('--app-bg')
        .trim();

    document
        .querySelector('meta[name="theme-color"]')
        ?.setAttribute('content', background);

    return background;
}

export function applySystemTheme(
    dark = window.matchMedia('(prefers-color-scheme: dark)').matches,
): void {
    const root = document.documentElement;
    delete root.dataset.telegram;
    root.dataset.theme = dark ? 'dark' : 'light';
    syncThemeColor();
}
