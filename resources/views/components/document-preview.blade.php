@props(['src', 'title' => 'Document preview'])

{{-- Fits the A4 (794x1123 @ 96dpi) preview inside a box that is itself
     capped in both width and height, scaling by whichever dimension is
     tighter so the whole page is always visible without the container's
     own height blowing past the viewport -- the old version only scaled
     by width, so a narrow-but-tall window could still push the bottom of
     the page off-screen. Outer div is the fixed-size measuring reference
     and is never itself resized, so scaling never feeds back into the
     measurement. --}}
<div
    class="w-full"
    style="height: min(70vh, 900px);"
    x-data="{
        scale: 1,
        resize() {
            this.scale = Math.min(this.$el.clientWidth / 794, this.$el.clientHeight / 1123, 1);
        }
    }"
    x-init="resize(); window.addEventListener('resize', () => resize())"
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
