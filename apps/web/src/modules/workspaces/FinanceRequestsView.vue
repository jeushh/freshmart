<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import { UiButton, UiConfirmDialog, UiInput, UiPageHeader, UiStatusBadge, UiTableShell } from '../../components/ui/index.js'
import { sessionStore } from '../../stores/session.js'
import { formatMoney } from '../../utils/formatters.js'

const requests = ref([])
const pagination = ref({ current_page: 1, last_page: 1, total: 0 })
const loading = ref(true)
const error = ref('')
const review = ref(null)
const note = ref('')
const submitting = ref(false)
const canReview = computed(() => sessionStore.can('finance.requests.approve'))
async function load(page = pagination.value.current_page) {
  loading.value = true; error.value = ''
  try {
    const data = await api.get('/workspace/finance/requests', { page, per_page: 20 })
    requests.value = data.requests.data
    pagination.value = data.requests
  } catch (requestError) { error.value = requestError.message } finally { loading.value = false }
}
function startReview(row, decision) { review.value = { id: row.id, decision, description: row.description }; note.value = '' }
function cancelReview() { review.value = null; note.value = '' }
async function confirmReview() {
  if (!review.value) return
  submitting.value = true; error.value = ''
  try {
    await api.post(`/workspace/finance/requests/${review.value.id}/review`, { decision: review.value.decision, notes: note.value.trim() || null })
    cancelReview(); await load()
  } catch (requestError) { error.value = requestError.message } finally { submitting.value = false }
}
onMounted(() => load())
</script>

<template>
  <UiPageHeader title="Finance Requests" description="Review and track the finance requests available to your account." />
  <UiTableShell title="Request queue" :loading="loading" :error="error" :empty="!requests.length" empty-title="No finance requests" empty-description="There are no finance requests to show." @retry="load">
    <table><thead><tr><th>Employee</th><th>Description</th><th>Category</th><th>Amount</th><th>Status</th><th v-if="canReview">Actions</th></tr></thead><tbody>
      <tr v-for="request in requests" :key="request.id"><td>{{ request.full_name || '—' }}</td><td>{{ request.description }}</td><td>{{ request.category }}</td><td>{{ formatMoney(request.amount) }}</td><td><UiStatusBadge :status="request.status" /></td><td v-if="canReview"><div class="request-actions">
        <UiButton v-if="request.status === 'Pending'" size="sm" @click="startReview(request, 'Approved')">Approve</UiButton>
        <UiButton v-if="request.status === 'Pending'" size="sm" variant="destructive" @click="startReview(request, 'Rejected')">Reject</UiButton>
        <UiButton v-if="request.status === 'Approved'" size="sm" @click="startReview(request, 'Paid')">Mark paid</UiButton>
      </div></td></tr>
    </tbody></table>
    <template #footer><div class="pagination"><span>Page {{ pagination.current_page }} of {{ pagination.last_page }} · {{ pagination.total }} requests</span><div><UiButton size="sm" variant="secondary" :disabled="pagination.current_page <= 1" @click="load(pagination.current_page - 1)">Previous</UiButton><UiButton size="sm" variant="secondary" :disabled="pagination.current_page >= pagination.last_page" @click="load(pagination.current_page + 1)">Next</UiButton></div></div></template>
  </UiTableShell>
  <UiConfirmDialog
    :open="!!review"
    :title="`${review?.decision} finance request`"
    :description="review?.description"
    :confirm-label="`Confirm ${review?.decision}`"
    :destructive="review?.decision === 'Rejected'"
    :loading="submitting"
    loading-label="Saving"
    @confirm="confirmReview"
    @cancel="cancelReview"
  >
    <UiInput v-model="note" label="Review note" maxlength="500" hint="Optional. This note will be recorded with this action." />
    <p v-if="error" class="form-error" role="alert">{{ error }}</p>
  </UiConfirmDialog>
</template>

<style scoped>.request-actions{display:flex;flex-wrap:wrap;gap:.5rem}.pagination{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}</style>
