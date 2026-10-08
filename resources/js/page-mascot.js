/*
 * The page mascot (npm page-mascot): a drawn character that follows the
 * reader's cursor and reacts when poked. It is a React component and the
 * site is Blade + Alpine, so it is mounted as a small island into each
 * [data-page-mascot] element, and this file is only fetched on pages that
 * have one (see app.js).
 *
 *   <div data-page-mascot
 *        data-directions="/images/mascot/chakra-directions.webp"
 *        data-reactions="/images/mascot/chakra-reactions.webp"
 *        data-size="140" data-label="Chakra mascot"></div>
 *
 * Plain createElement rather than JSX, so the build needs no React plugin.
 */
import { createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { Mascot } from 'page-mascot';

export function mountMascots(root = document) {
    root.querySelectorAll('[data-page-mascot]').forEach((el) => {
        if (el.dataset.mounted) return;
        el.dataset.mounted = '1';

        createRoot(el).render(createElement(Mascot, {
            directions: el.dataset.directions,
            reactions: el.dataset.reactions,
            size: Number(el.dataset.size) || 140,
            label: el.dataset.label || 'mascot',
        }));
    });
}
