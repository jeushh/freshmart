<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import { UiPageHeader, UiStatusBadge, UiTableShell } from '../../components/ui/index.js'
import { formatDateTime, formatMoney } from '../../utils/formatters.js'
const transactions = ref([]); const loading = ref(true); const error = ref('')
async function load() { loading.value = true; error.value = ''; try { transactions.value = (await api.get('/workspace/finance/overview')).transactions } catch (requestError) { error.value = requestError.message } finally { loading.value = false } }
onMounted(load)
</script>
<template>
  <UiPageHeader title="Recent Transactions" description="The current capped list of recent finance activity; use Reports for deeper reporting."><template #actions><RouterLink class="ui-button ui-button--secondary" to="/reports">Open reports</RouterLink></template></UiPageHeader>
  <UiTableShell title="Recent finance activity" :loading="loading" :error="error" :empty="!transactions.length" empty-title="No recent transactions" empty-description="No recent finance activity is available." @retry="load"><table><thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Direction</th><th>Amount</th></tr></thead><tbody><tr v-for="row in transactions" :key="row.id"><td>{{ formatDateTime(row.created_at) }}</td><td>{{ row.transaction_type }}</td><td>{{ row.description }}</td><td><UiStatusBadge :status="row.direction" :tone="row.direction === 'In' ? 'success' : 'warning'" /></td><td>{{ formatMoney(row.amount) }}</td></tr></tbody></table></UiTableShell>
</template>
