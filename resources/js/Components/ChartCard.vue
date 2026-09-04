<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import Chart from 'chart.js/auto'

/*
 | Every card looks the same, and there is deliberately no prop that can change
 | that.
 |
 | There used to be one — `snapshot`, which tinted the header and darkened the
 | border on the two charts that ignore the date picker. It was a third way of
 | saying what the title and the note already say, and because it was a prop,
 | the cards could be made to disagree from the outside: two of them ended
 | up reading as a different kind of panel and the grid looked broken. The
 | styling lives here now and takes no arguments, so the cards cannot drift
 | apart again.
 |
 | `note` defaults to a string rather than undefined so that a card with no
 | note still renders the same elements and the same attributes as one with a
 | note. Identical markup, different text.
 */
const props = defineProps({
  title: { type: String, default: '' },
  note: { type: String, default: '' },
  config: { type: Object, required: true },
  height: { type: String, default: 'h-[280px]' },
  // for charts with no axis of their own to fall back on — a doughnut with
  // every slice at zero draws nothing, where a bar chart still shows a scale
  empty: { type: Boolean, default: false },
  emptyText: { type: String, default: 'No data in this range' },
})

const canvas = ref(null)
const box = ref(null)
let chart = null
let observer = null
let timer

/*
 | Two separate paths, and keeping them separate is the point.
 |
 |   the data changed  →  render(), which tears the chart down and builds a
 |                        new one from the new config
 |   the size changed  →  resize(), which only asks the chart to re-measure
 |
 | Rebuilding on a resize is what made the charts flicker and restart their
 | animation mid-drag. It was also pure waste: nothing about the data had
 | changed, only the number of pixels available to draw it in.
 */
const render = () => {
  if (!canvas.value) return

  // destroy before recreating, or old canvases leak and
  // ghost tooltips follow the mouse around the screen
  chart?.destroy()
  chart = new Chart(canvas.value, props.config)
}

const resize = () => {
  // the observer can fire once more while the component is being torn down,
  // after the chart is gone — there is nothing to re-measure then
  if (!chart || !canvas.value) return

  chart.resize()
}

onMounted(() => {
  render()

  /*
   | A ResizeObserver on the plot box rather than a window resize listener.
   | The box is what actually decides how big the chart can be, and it can
   | change size without the window doing anything — a sibling column growing,
   | a panel opening, the sidebar margin appearing at the lg breakpoint. A
   | window listener sees none of that; this sees all of it, window resizes
   | included.
   */
  observer = new ResizeObserver(() => {
    clearTimeout(timer)
    timer = setTimeout(resize, 120)
  })

  if (box.value) observer.observe(box.value)
})

watch(() => props.config, render, { deep: true })

onBeforeUnmount(() => {
  // the pending debounce goes too: left alone it fires after the component is
  // gone, holding this whole closure alive until it does
  clearTimeout(timer)
  observer?.disconnect()
  observer = null
  chart?.destroy()
  chart = null
})
</script>

<template>
  <!--
    min-w-0 is doing the real work here.

    These cards are grid items, and a grid item's automatic minimum size is its
    content — which for us is a canvas that Chart.js has given an explicit pixel
    width. That width then became a floor the column could not go below, so the
    card kept the widest size it had ever had: the page grew a horizontal
    scrollbar, the box stopped shrinking, and the chart — correctly filling a box
    that was no longer shrinking — appeared frozen until a reload. min-w-0 drops
    that floor so the column can shrink, and Chart.js follows it down.
  -->
  <div class="card min-w-0">
    <!--
      Title over note, and a header whose height is a constant rather than a
      function of its text.

      min-h reserves the two note lines the longest note could ever need, and
      line-clamp-2 stops it needing a third, so the header is 84px whether the
      note is two lines, one line, or absent. That is the property the row
      alignment depends on: a header that grows with its note pushes the plot
      box below it down and throws the pair out of step.

      justify-center is what keeps the reserved space from reading as a gap. A
      one-line note is centred in the box, so the header looks like it has
      generous padding rather than an empty row waiting underneath it.

      Not one class here is bound. Cards rendering a header each of their own
      is what this file is fixing, so there is nothing left to render
      differently with.
    -->
    <div class="flex min-h-[5.25rem] flex-col justify-center border-b border-slate-100 px-5 py-3.5">
      <h3 class="truncate text-sm font-semibold" :title="title">{{ title }}</h3>
      <p class="mt-0.5 line-clamp-2 text-xs text-slate-400" :title="note">{{ note }}</p>
    </div>
    <div class="px-5 py-4">
      <!--
        overflow-hidden covers the frame between the box shrinking and Chart.js
        redrawing the canvas at the new size: without it that one oversized
        frame is enough to flash a horizontal scrollbar across the page.
      -->
      <div ref="box" class="relative overflow-hidden" :class="height">
        <canvas ref="canvas"></canvas>
        <div v-if="empty"
             class="absolute inset-0 flex items-center justify-center text-sm text-slate-400">
          {{ emptyText }}
        </div>
      </div>
    </div>
  </div>
</template>
