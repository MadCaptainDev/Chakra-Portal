@props(['src', 'title' => 'Document preview'])

{{-- Fits the A4 (794x1123 @ 96dpi) preview inside a box that is itself
     capped in both width and height, scaling by whichever dimension is
     tighter so the whole page is always visible without the container's
     own height blowing past the viewport.

     Measured with a ResizeObserver, not a one-shot clientWidth/clientHeight
     read at x-init: this box sits inside a tab panel toggled by an
     ancestor's x-show, and when x-init ran before that ancestor's layout
     had actually resolved (display:none collapses width/height to 0 for
     every descendant), scale computed to 0 and the whole preview rendered
     as an invisible 0x0 box -- permanently, since nothing ever re-measured
     it. A ResizeObserver's callback fires as soon as the observed element
     actually has a size, whenever that turns out to be, so it self-heals
     regardless of how the surrounding tabs/visibility timing shakes out. --}}
<div
    class="w-full"
    style="height: min(70vh, 900px);"
    x-data="{
        scale: 1,
        resize() {
            const w = this.$el.clientWidth;
            const h = this.$el.clientHeight;
            if (w > 0 && h > 0) {
                this.scale = Math.min(w / 794, h / 1123, 1);
            }
        }
    }"
    x-init="
        resize();
        new ResizeObserver(() => resize()).observe($el);
    "
>
    <div class="w-full h-full flex items-center justify-center overflow-hidden">
        <div
            class="overflow-hidden rounded-md ring-1 ring-white/10 shadow-sm bg-white shrink-0"
            :style="{ width: (794 * scale) + 'px', height: (1123 * scale) + 'px' }"
        >
            <iframe
                src="{{ $src }}"
                title="{{ $title }}"
                style="width: 794px; height: 1123px; border: 0; display: block;"
                :style="{ transform: 'scale(' + scale + ')', transformOrigin: 'top left' }"
            ></iframe>
        </div>
    </div>
</div>
