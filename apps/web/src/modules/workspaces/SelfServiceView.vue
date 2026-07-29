<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../../api/http.js'
import PageHeader from '../../components/common/PageHeader.vue'
import WorkspaceTable from '../../components/common/WorkspaceTable.vue'

const data = ref({ profile: null, hr_requests: [], finance_requests: [], attendance: [] })
const error = ref('')
const form = ref({
  kind: 'hr',
  request_type: 'Leave',
  reason: '',
  amount: null,
  start_date: '',
  end_date: '',
  hours: null
})
const requestTypes = computed(() => form.value.kind === 'hr'
  ? ['Leave', 'Overtime', 'Other']
  : ['Reimbursement', 'Purchase'])

watch(() => form.value.kind, () => {
  form.value.request_type = requestTypes.value[0]
  form.value.amount = null
  form.value.start_date = ''
  form.value.end_date = ''
  form.value.hours = null
})

async function load() {
  try {
    error.value = ''
    data.value = await api.get('/workspace/self')
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function save() {
  try {
    error.value = ''
    await api.post('/workspace/self/request', form.value)
    form.value.reason = ''
    await load()
  } catch (requestError) {
    error.value = requestError.message
  }
}

onMounted(load)
</script>

<template>
  <PageHeader title="Employee Self-Service" description="View your profile and submit requests." />
  <p v-if="error" class="form-error">{{ error }}</p>
  <section v-if="data.profile" class="profile-card">
    <h2>{{ data.profile.full_name }}</h2>
    <p>{{ data.profile.employee_no }} · {{ data.profile.position }} · {{ data.profile.department }}</p>
    <p>Leave balance: {{ data.profile.leave_balance }}</p>
  </section>

  <form class="inline-form" @submit.prevent="save">
    <select v-model="form.kind">
      <option value="hr">HR request</option>
      <option value="finance">Finance request</option>
    </select>
    <select v-model="form.request_type">
      <option v-for="type in requestTypes" :key="type">{{ type }}</option>
    </select>
    <input
      v-if="form.kind === 'finance'"
      v-model.number="form.amount"
      type="number"
      min="0.01"
      step=".01"
      placeholder="Amount"
      required
    >
    <template v-if="form.kind === 'hr' && form.request_type === 'Leave'">
      <input v-model="form.start_date" type="date" required>
      <input v-model="form.end_date" type="date" :min="form.start_date" required>
    </template>
    <input
      v-if="form.kind === 'hr' && form.request_type === 'Overtime'"
      v-model.number="form.hours"
      type="number"
      min="0.25"
      step=".25"
      placeholder="Hours"
      required
    >
    <input v-model="form.reason" placeholder="Reason or description" required>
    <button class="primary-button">Submit request</button>
  </form>

  <h2>My HR requests</h2>
  <WorkspaceTable
    :columns="[
      { key: 'created_at', label: 'Date' },
      { key: 'request_type', label: 'Type' },
      { key: 'reason', label: 'Reason' },
      { key: 'status', label: 'Status' }
    ]"
    :rows="data.hr_requests"
  />
  <h2 class="section-title">My finance requests</h2>
  <WorkspaceTable
    :columns="[
      { key: 'created_at', label: 'Date' },
      { key: 'request_type', label: 'Type' },
      { key: 'amount', label: 'Amount' },
      { key: 'status', label: 'Status' }
    ]"
    :rows="data.finance_requests"
  />
</template>
