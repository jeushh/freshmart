<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import { sessionStore } from '../../stores/session.js'
import PageHeader from '../../components/common/PageHeader.vue'
import WorkspaceTable from '../../components/common/WorkspaceTable.vue'

const requests = ref([])
const transactions = ref([])
const error = ref('')
const money = value => new Intl.NumberFormat('en-US', {
  style: 'currency',
  currency: 'USD'
}).format(value || 0)

async function load() {
  try {
    error.value = ''
    const pending = []

    if (sessionStore.can('finance.requests.view')) {
      pending.push(
        api.get('/workspace/finance/requests', { per_page: 100 })
          .then(data => { requests.value = data.requests.data })
      )
    }
    if (sessionStore.can('finance.manage')) {
      pending.push(
        api.get('/workspace/finance/overview')
          .then(data => { transactions.value = data.transactions })
      )
    }

    await Promise.all(pending)
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function decide(id, decision) {
  try {
    error.value = ''
    await api.post(`/workspace/finance/requests/${id}/review`, { decision })
    await load()
  } catch (requestError) {
    error.value = requestError.message
  }
}

onMounted(load)
</script>

<template>
  <PageHeader title="Finance" description="Review requests and track cash movement." />
  <p v-if="error" class="form-error">{{ error }}</p>

  <template v-if="sessionStore.can('finance.requests.view')">
    <h2>Finance requests</h2>
    <WorkspaceTable
      :columns="[
        { key: 'full_name', label: 'Employee' },
        { key: 'request_type', label: 'Type' },
        { key: 'amount', label: 'Amount' },
        { key: 'description', label: 'Description' },
        { key: 'status', label: 'Status' }
      ]"
      :rows="requests"
    >
      <template #cell-amount="{ row }">{{ money(row.amount) }}</template>
      <template v-if="sessionStore.can('finance.requests.approve')" #actions="{ row }">
        <button v-if="row.status === 'Pending'" @click="decide(row.id, 'Approved')">Approve</button>
        <button v-if="row.status === 'Pending'" @click="decide(row.id, 'Rejected')">Reject</button>
        <button v-if="row.status === 'Approved'" @click="decide(row.id, 'Paid')">Pay</button>
      </template>
    </WorkspaceTable>
  </template>

  <template v-if="sessionStore.can('finance.manage')">
    <h2 class="section-title">Recent transactions</h2>
    <WorkspaceTable
      :columns="[
        { key: 'created_at', label: 'Date' },
        { key: 'transaction_type', label: 'Type' },
        { key: 'description', label: 'Description' },
        { key: 'direction', label: 'Direction' },
        { key: 'amount', label: 'Amount' }
      ]"
      :rows="transactions"
    >
      <template #cell-amount="{ row }">{{ money(row.amount) }}</template>
    </WorkspaceTable>
  </template>
</template>
