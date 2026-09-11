<script setup>
import { computed } from 'vue'

/*
 | The trend line inside a KPI tile. A shape and nothing else.
 |
 | No axes, no labels, no tooltips, no chart library. Nobody reads a number off
 | one of these — the number is printed above it, in the tile — so everything
 | that would help you read one off is noise in a 60px box. What it is for is
 | the one question the figure above it cannot answer on its own: is this
 | steady, is it climbing, did it all happen on one day.
 |
 | Inline SVG rather than a canvas. Six of these sit in the top row of the
 | dashboard and each would otherwise be a Chart.js instance with its own
 | animation loop and its own teardown; an SVG is markup Vue already knows how
 | to replace when the data changes, and it costs nothing to have six.
 |
 | The series arrives zero-filled from the server — every bucket in the range
 | has a value, and an empty day is a 0. This file trusts that and draws a
 | point per entry, so a gap in the line is not something it can produce.
 */
const props = defineProps({
  /** @type {number[]} one value per bucket, in order, zeros included */
  values: { type: Array, default: () => [] },
  /** the tile's own accent, so the line matches the number above it */
  color: { type: String, default: '#0F766E' },
})

/*
 | The viewBox, in its own units. The SVG is stretched to whatever width the
 | tile gives it — preserveAspectRatio="none" — so these are ratios rather
 | than pixels, and the stroke is drawn non-scaling so a wide tile does not get
 | a fat line and a narrow one a hairline.
 */
const W = 100
const H = 30
const PAD = 3

const points = computed(() => {
  const values = props.values

  if (!values.length) return []

  /*
   | Scaled against the largest bucket, with 1 as the floor for that maximum.
   | A series that is all zeros — a genuinely quiet fortnight — would otherwise
   | divide by nothing; with the floor it draws a flat line along the bottom,
   | which is the true shape of a fortnight in which nothing happened.
   */
  const max = Math.max(...values, 1)

  const y = n => PAD + (1 - n / max) * (H - PAD * 2)

  /*
   | One bucket is a range one day (or one week) long. There is no trend to
   | draw, so it draws a level line at that value rather than a single dot,
   | which at this size is a speck of dust on the tile.
   */
  if (values.length === 1) return [[0, y(values[0])], [W, y(values[0])]]

  const step = W / (values.length - 1)

  return values.map((n, i) => [i * step, y(n)])
})

const line = computed(() =>
  points.value.map(([x, p], i) => `${i ? 'L' : 'M'}${x.toFixed(2)},${p.toFixed(2)}`).join(' '))

// the same line, closed along the bottom edge — a wash under it, not a fill
const area = computed(() => points.value.length
  ? `${line.value} L${W},${H} L0,${H} Z`
  : '')
</script>

<template>
  <!--
    aria-hidden: the tile's number and label are the content, and a screen
    reader announcing a decorative polyline would be reading out punctuation.
  -->
  <svg v-if="points.length" class="block h-full w-full" :viewBox="`0 0 ${W} ${H}`"
       preserveAspectRatio="none" aria-hidden="true" focusable="false">
    <path :d="area" :fill="color" fill-opacity="0.10" />
    <path :d="line" fill="none" :stroke="color" stroke-width="1.5"
          stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
  </svg>
</template>
