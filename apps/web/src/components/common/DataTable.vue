<script setup>
import { computed, ref, watch } from 'vue'
const props = defineProps({ columns: { type: Array, required: true }, rows: { type: Array, required: true }, rowKey: { type: String, default: 'id' }, emptyText: { type: String, default: 'No records found.' } })
const query = ref(''); const page = ref(1); const size = ref(10)
const filtered = computed(() => { const q=query.value.trim().toLowerCase(); if(!q) return props.rows; return props.rows.filter(row => props.columns.some(c => String(row[c.key] ?? '').toLowerCase().includes(q))) })
const pages = computed(() => Math.max(1, Math.ceil(filtered.value.length / size.value)))
const visible = computed(() => filtered.value.slice((page.value-1)*size.value, page.value*size.value))
watch([query,size,()=>props.rows],()=>page.value=1)
watch(pages,p=>{ if(page.value>p) page.value=p })
</script>
<template>
<section class="table-panel">
  <div class="table-toolbar">
    <label class="search-field"><span>Search records</span><input v-model="query" type="search" placeholder="Type to search…"></label>
    <label class="rows-field"><span>Rows</span><select v-model.number="size"><option :value="5">5</option><option :value="10">10</option><option :value="25">25</option><option :value="50">50</option></select></label>
  </div>
  <div class="table-scroll">
    <table><thead><tr><th v-for="c in columns" :key="c.key">{{ c.label }}</th></tr></thead>
      <tbody><tr v-for="row in visible" :key="row[rowKey]"><td v-for="c in columns" :key="c.key"><slot :name="`cell-${c.key}`" :row="row">{{ row[c.key] }}</slot></td></tr>
      <tr v-if="!visible.length"><td :colspan="columns.length" class="empty-cell">{{ emptyText }}</td></tr></tbody></table>
  </div>
  <footer class="pagination"><span>Showing {{ filtered.length ? (page-1)*size+1 : 0 }}–{{ Math.min(page*size, filtered.length) }} of {{ filtered.length }}</span><div><button :disabled="page===1" @click="page=1">First</button><button :disabled="page===1" @click="page--">Previous</button><strong>Page {{ page }} of {{ pages }}</strong><button :disabled="page===pages" @click="page++">Next</button><button :disabled="page===pages" @click="page=pages">Last</button></div></footer>
</section>
</template>
