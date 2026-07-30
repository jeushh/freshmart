<script setup>
import { computed } from 'vue'
import { formatMoney, formatNumber } from '../../utils/formatters.js'

const props = defineProps({
  chart: { type: Object, required: true }
})
const maximum = computed(() => Math.max(...props.chart.points.map(point => Number(point.value) || 0), 1))
const display = value => props.chart.format === 'money' ? formatMoney(value) : formatNumber(value)
</script>

<template>
  <figure class="metric-chart">
    <figcaption>
      <strong>{{ chart.label }}</strong>
      <span>{{ chart.summary }}</span>
    </figcaption>
    <div class="bar-chart" role="img" :aria-label="chart.summary">
      <div v-for="point in chart.points" :key="point.label" class="bar-column">
        <span class="bar-value">{{ display(point.value) }}</span>
        <div class="bar-track">
          <div class="bar-fill" :style="{ height: `${(Number(point.value) || 0) / maximum * 100}%` }"></div>
        </div>
        <span>{{ point.label }}</span>
      </div>
    </div>
  </figure>
</template>
