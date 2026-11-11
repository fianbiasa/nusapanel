/**
 * Single sign-on into the tools the panel opens in a new tab (phpMyAdmin,
 * webmail). Browsers only allow opening a tab during the click, so the tab
 * opens first and waits; once the panel returns a ticket, the tab posts it
 * to the tool itself, after its link back to the panel is cut.
 */

/** Open an empty tab that shows `waiting` until the ticket is posted. */
export function openSignOnTab(title: string, waiting: string): Window | null {
    const tab = window.open('', '_blank');

    if (tab) {
        tab.document.title = title;
        tab.document.body.textContent = waiting;
    }

    return tab;
}

/**
 * Post `ticket` as the form field `field` to `url` in `tab`, or in this
 * page when pop-ups were blocked.
 */
export function postSignOnTicket(
    tab: Window | null,
    url: string,
    field: string,
    ticket: string,
): void {
    const target = tab ?? window;
    const form = target.document.createElement('form');
    const input = target.document.createElement('input');

    form.method = 'post';
    form.action = url;
    input.type = 'hidden';
    input.name = field;
    input.value = ticket;
    form.append(input);
    target.document.body.append(form);

    if (tab) {
        tab.opener = null;
    }

    form.submit();
}
