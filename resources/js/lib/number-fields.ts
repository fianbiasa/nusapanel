/**
 * Chrome turns the mouse wheel over a focused number field into steps of
 * its value, and the page does not scroll: a limit typed as 100000 is saved
 * as 99995 after scrolling down to the save button (issue #1). This leaves
 * the field as soon as the wheel turns over it, so the page scrolls and the
 * value stays as typed. It covers every number field of the panel, the
 * shared Input and the plain inputs alike.
 */
export function keepNumberFieldsFromTheWheel(): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.addEventListener(
        'wheel',
        (event) => {
            const field = document.activeElement;

            if (
                field instanceof HTMLInputElement &&
                field.type === 'number' &&
                event.target === field
            ) {
                field.blur();
            }
        },
        { capture: true, passive: true },
    );
}
