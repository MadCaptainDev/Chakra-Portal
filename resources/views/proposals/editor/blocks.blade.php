{{--
    One block, editable -- the markup of proposals/blocks/{type}.blade.php
    with its text bound to the editor's state. Runs inside the editor's
    x-for, so `s`, `b` and `block` are in scope.

    Repeatable things (cards, steps, pills, rows) follow Word's keys: Enter
    in the last field of one adds the next, Backspace in an empty one
    removes it.
--}}

<template x-if="block.type === 'paragraph'">
    <div x-edit.paras="block.text" data-placeholder="Type here…"
         :class="{ 'cp-muted': block.tone === 'muted', 'cp-fine': block.tone === 'fine' }"></div>
</template>

<template x-if="block.type === 'subheading'">
    <h3 x-edit="block.text" data-placeholder="Subheading"></h3>
</template>

<template x-if="block.type === 'list' && block.ordered">
    <ol x-edit.list="block.items" :class="{ 'cp-boxed': block.boxed }"></ol>
</template>
<template x-if="block.type === 'list' && !block.ordered">
    <ul x-edit.list="block.items" :class="{ 'cp-boxed': block.boxed }"></ul>
</template>

<template x-if="block.type === 'table'">
    <div class="cp-table-wrap">
        <table>
            <thead x-show="block.header.length">
                <tr>
                    <template x-for="(_, c) in Array.from({ length: columns(block) })" :key="c">
                        <th x-edit="block.header[c]" data-placeholder="Heading" :data-r="-1" :data-c="c"
                            @focusin.stop="setActive(s, b, { r: -1, c })"
                            :class="{ 'cp-right': block.last_col_right && c === columns(block) - 1 && c > 0 }"
                            :style="c === 0 && block.first_col_width ? 'width: ' + block.first_col_width + '%' : ''"></th>
                    </template>
                </tr>
            </thead>
            <tbody>
                <template x-for="(row, r) in block.rows" :key="r">
                    <tr :class="{ 'cp-strong': block.last_row_bold && r === block.rows.length - 1 }">
                        <template x-for="(_, c) in Array.from({ length: columns(block) })" :key="c">
                            <td x-edit.rich="block.rows[r][c]" :data-r="r" :data-c="c"
                                @focusin.stop="setActive(s, b, { r, c })"
                                @keydown.tab="tableTab($event, s, b, r, c)"
                                :class="{
                                    'cp-strong': block.first_col_bold && c === 0,
                                    'cp-right': block.last_col_right && c === columns(block) - 1 && c > 0,
                                }"></td>
                        </template>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>

<template x-if="block.type === 'callout'">
    <div class="cp-callout" :class="'cp-callout--' + block.tone" x-edit.rich="block.text" data-placeholder="Callout text"></div>
</template>

<template x-if="block.type === 'cards'">
    <div class="cp-grid" :class="['cp-grid--' + block.variant, block.columns === 1 ? 'cp-grid--c1' : '']" :style="'--cols: ' + block.columns">
        <template x-for="(item, i) in block.items" :key="i">
            <div class="cp-card" :data-i="i" @focusin.stop="setActive(s, b, { i })"
                 @edit-empty.prevent="removeIfEmpty(s, b, i)">
                <div x-show="block.variant !== 'plain'"
                     :class="block.variant === 'stat' ? 'cp-stat__value' : 'cp-card__label'"
                     x-edit="item.label" :data-placeholder="block.variant === 'stat' ? '100+' : 'Label'"></div>
                <div :class="block.variant === 'stat' ? 'cp-stat__text' : 'cp-card__text'"
                     x-edit.rich="item.text" data-placeholder="Text"
                     @edit-enter.prevent="addItem(s, b, i)"></div>
            </div>
        </template>
    </div>
</template>

<template x-if="block.type === 'flow' && block.tone === 'line'">
    <div class="cp-flow cp-flow--line">
        <template x-for="(step, i) in block.steps" :key="i">
            <span :data-i="i" @focusin.stop="setActive(s, b, { i })"><span x-show="i > 0"> &rarr; </span><span
                x-edit="block.steps[i]" data-placeholder="Step"
                @edit-enter.prevent="addItem(s, b, i)" @edit-empty.prevent="removeIfEmpty(s, b, i)"></span></span>
        </template>
    </div>
</template>
<template x-if="block.type === 'flow' && block.tone !== 'line'">
    <div class="cp-flow" :class="['cp-flow--' + block.tone, block.numbered && 'cp-flow--numbered']"
         :style="'--cols: ' + (block.columns || Math.max(1, block.steps.length))">
        <template x-for="(step, i) in block.steps" :key="i">
            <div class="cp-flow__step" :data-i="i" @focusin.stop="setActive(s, b, { i })">
                <span class="cp-flow__num" x-show="block.numbered" x-text="String(i + 1).padStart(2, '0')"></span>
                <span x-edit="block.steps[i]" data-placeholder="Step"
                      @edit-enter.prevent="addItem(s, b, i)" @edit-empty.prevent="removeIfEmpty(s, b, i)"></span>
            </div>
        </template>
    </div>
</template>

<template x-if="block.type === 'chips'">
    <div class="cp-chips">
        <template x-for="(chip, i) in block.items" :key="i">
            <span x-edit="block.items[i]" data-placeholder="Pill" :data-i="i"
                  @focusin.stop="setActive(s, b, { i })"
                  @edit-enter.prevent="addItem(s, b, i)" @edit-empty.prevent="removeIfEmpty(s, b, i)"></span>
        </template>
    </div>
</template>

<template x-if="block.type === 'note'">
    <div class="cp-note">
        <span class="cp-tag" :class="'cp-tag--' + block.tag" x-text="tags[block.tag]"></span>
        <span x-edit.rich="block.text" data-placeholder="Note text"></span>
    </div>
</template>

<template x-if="block.type === 'legend'">
    <div class="cp-legend">
        <div class="cp-legend__title" x-edit="block.title" data-placeholder="How to read this proposal"></div>
        <div class="cp-legend__items" :style="'--cols: ' + Math.max(1, Math.min(3, block.items.length))">
            <template x-for="(item, i) in block.items" :key="i">
                <div class="cp-legend__item" :data-i="i" @focusin.stop="setActive(s, b, { i })"
                     @edit-empty.prevent="removeIfEmpty(s, b, i)">
                    <span class="cp-tag" :class="'cp-tag--' + item.tag" x-edit="item.label" :data-placeholder="tags[item.tag]"></span>
                    <span x-edit="item.note" data-placeholder="What this tag means"
                          @edit-enter.prevent="addItem(s, b, i)"></span>
                </div>
            </template>
        </div>
    </div>
</template>

<template x-if="block.type === 'total'">
    <div class="cp-total">
        <div class="cp-total__label" x-edit="block.label" data-placeholder="Total"></div>
        <div class="cp-total__value" x-edit="block.value" data-placeholder="₹0"></div>
    </div>
</template>

<template x-if="block.type === 'stack'">
    <div class="cp-stack">
        <template x-for="(item, i) in block.items" :key="i">
            <div class="cp-stack__item" :data-i="i" @focusin.stop="setActive(s, b, { i })"
                 @edit-empty.prevent="removeIfEmpty(s, b, i)">
                <div class="cp-stack__logo" x-show="item.logo && techLogos[item.logo]"><img :src="techLogos[item.logo]" alt=""></div>
                <div class="cp-stack__logo cp-stack__logo--empty" x-show="!(item.logo && techLogos[item.logo])">Logo</div>
                <div>
                    <div class="cp-stack__cat" x-edit="item.category" data-placeholder="Category"></div>
                    <div class="cp-stack__name" x-edit="item.name" data-placeholder="Name"
                         @edit-enter.prevent="addItem(s, b, i)"></div>
                </div>
            </div>
        </template>
    </div>
</template>

<template x-if="block.type === 'architecture'">
    <div class="cp-arch">
        <template x-for="(layer, i) in block.layers" :key="i">
            <div class="cp-e-layer" :data-i="i" @focusin.stop="setActive(s, b, { i })">
                <div class="cp-arch__label" x-edit="layer.label" data-placeholder="Layer name (optional)"></div>
                <div class="cp-arch__row" :class="'cp-arch__row--' + layer.style" :style="'--cols: ' + layer.columns">
                    <template x-for="(box, j) in layer.items" :key="j">
                        <div :class="{ 'cp-dashed': box.dashed }" :data-i="i" :data-j="j"
                             x-edit="box.text" data-placeholder="Box"
                             @focusin.stop="setActive(s, b, { i, j })"
                             @edit-enter.prevent="setActive(s, b, { i, j }); addBox()"></div>
                    </template>
                </div>
            </div>
        </template>
    </div>
</template>

<template x-if="block.type === 'swimlane'">
    <div>
        <div class="cp-swim-wrap">
            <div class="cp-swim">
                <div></div>
                <template x-for="(lane, l) in block.lanes" :key="l">
                    <div class="cp-swim__lane" :class="'cp-swim__lane--' + l" x-edit="block.lanes[l]" data-placeholder="Lane"
                         @focusin.stop="setActive(s, b, { r: -1, c: l })"></div>
                </template>

                <template x-for="(row, r) in block.rows" :key="r">
                    <div class="contents">
                        <div class="cp-swim__num" x-text="String(r + 1).padStart(2, '0')"></div>
                        <template x-for="(cellData, c) in row" :key="c">
                            <div :class="swimClass(cellData, c)" x-edit.rich="cellData.text" data-placeholder="—"
                                 :data-r="r" :data-c="c"
                                 @focusin.stop="setActive(s, b, { r, c })"
                                 @edit-enter.prevent="c === 2 ? (setActive(s, b, { r, c }), addSwimRow()) : focusNext($el)"></div>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        <div class="cp-swim-legend" x-show="block.legend">
            <span><i class="cp-swim__cell--0"></i><span x-text="(block.lanes[0] || 'Customer') + ' action'"></span></span>
            <span><i class="cp-swim__cell--1"></i><span x-text="'Automated by ' + (block.lanes[1] || 'platform').toLowerCase()"></span></span>
            <span><i class="cp-swim__cell--2"></i>Staff / department</span>
            <span><i class="cp-swim__cell--loop"></i>Review or correction loop</span>
        </div>
    </div>
</template>

{{-- Chart: the title, caption and each label / value are typed in place.
     The drawing itself is shown in Preview -- redrawing it live on every
     keystroke would fight the caret for no benefit. --}}
<template x-if="block.type === 'chart'">
    <div class="cp-chart">
        <div class="cp-chart__title" x-edit="block.title" data-placeholder="Chart title"
             @focusin.stop="setActive(s, b, {})"></div>
        <div class="cp-ui" style="font-size: 11px; color: #6B7280; margin: -8px 0 10px;"
             x-text="({ bar: 'Bar chart', donut: 'Donut chart', funnel: 'Funnel' })[block.kind] + ' — drawn in Preview'"></div>
        <div class="cp-chart__bars">
            <template x-for="(item, i) in block.items" :key="i">
                <div class="cp-chart__row" :data-i="i" @focusin.stop="setActive(s, b, { i })"
                     @edit-empty.prevent="removeIfEmpty(s, b, i)">
                    <span class="cp-chart__label" x-edit="item.label" data-placeholder="Label"></span>
                    <span class="cp-chart__track"></span>
                    <b class="cp-chart__value" x-edit="item.value" data-placeholder="0"
                       @edit-enter.prevent="addItem(s, b, i)"></b>
                </div>
            </template>
        </div>
        <p class="cp-chart__caption" x-edit.rich="block.caption" data-placeholder="Caption (optional)"
           @focusin.stop="setActive(s, b, {})"></p>
    </div>
</template>

{{-- Picture: placed by path; only the caption is edited here. --}}
<template x-if="block.type === 'image'">
    <figure class="cp-picture" :class="'cp-picture--' + block.size">
        <template x-if="block.path">
            <img :src="'/' + block.path" alt="">
        </template>
        <div x-show="!block.path" class="cp-ui"
             style="padding: 28px; border: 1px dashed #D1D5DB; border-radius: 12px; text-align: center; color: #6B7280;">
            No picture chosen
        </div>
        <figcaption x-edit.rich="block.caption" data-placeholder="Caption (optional)"
                    @focusin.stop="setActive(s, b, {})"></figcaption>
    </figure>
</template>
