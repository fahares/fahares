/**
 * The exact figure of a chart's mark in one shared box (backlog item 156, step 14.273): any element with `data-tip`
 * shows its text in the page's `[data-chart-tip]` box on hover and on keyboard focus, in place of the browser's own
 * `title`, which is slow, unstyled and absent on a phone. On a touch screen the first tap on a mark shows the box and
 * the second one follows its link; a tap elsewhere or a scroll hides it. A page without the box is left alone.
 */
export default function chartTip() {
    const box = document.querySelector('[data-chart-tip]');
    if (! box) {
        return;
    }
    let shown = null;

    const hide = () => {
        if (shown) {
            shown.removeAttribute('aria-describedby');
        }
        shown = null;
        box.hidden = true;
    };

    const show = (mark) => {
        const text = mark.getAttribute('data-tip');
        if (! text) {
            return hide();
        }
        if (shown && shown !== mark) {
            shown.removeAttribute('aria-describedby');
        }
        shown = mark;
        // The parts of a figure («سدۀ ۱۳: … · سال معین … · …») each on a line of its own (the box keeps line breaks)
        box.textContent = text.replaceAll(' · ', '\n');
        box.hidden = false;
        mark.setAttribute('aria-describedby', box.id);

        // Above the mark and centred on it, kept inside the window; below it when there is no room above
        const at = mark.getBoundingClientRect();
        const width = box.offsetWidth, height = box.offsetHeight, gap = 8;
        const left = Math.min(Math.max(gap, at.left + at.width / 2 - width / 2), document.documentElement.clientWidth - width - gap);
        const top = at.top - height - gap >= gap ? at.top - height - gap : at.bottom + gap;
        box.style.left = `${Math.round(left)}px`;
        box.style.top = `${Math.round(top)}px`;
    };

    const markOf = (event) => event.target instanceof Element ? event.target.closest('[data-tip]') : null;
    let touch = false;

    document.addEventListener('pointerdown', (event) => { touch = event.pointerType === 'touch'; }, true);
    document.addEventListener('mouseover', (event) => {
        const mark = markOf(event);
        if (! touch && mark && mark !== shown) {
            show(mark);
        }
    });
    document.addEventListener('mouseout', (event) => {
        if (! touch && shown && ! (event.relatedTarget instanceof Element && shown.contains(event.relatedTarget))) {
            hide();
        }
    });
    document.addEventListener('focusin', (event) => {
        const mark = markOf(event);
        if (mark && ! touch) {
            show(mark);
        }
    });
    document.addEventListener('focusout', () => { if (! touch) { hide(); } });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') { hide(); } });
    document.addEventListener('click', (event) => {
        if (! touch) {
            return;
        }
        const mark = markOf(event);
        if (mark && mark !== shown) {
            // The first tap reads the figure; the second one goes where the mark leads
            event.preventDefault();
            show(mark);
        } else if (! mark) {
            hide();
        }
    }, true);
    window.addEventListener('scroll', hide, { passive: true, capture: true });
    window.addEventListener('resize', hide);
}
