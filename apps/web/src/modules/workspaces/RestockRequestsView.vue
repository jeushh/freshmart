<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import { sessionStore } from '../../stores/session.js'
import {
  UiButton,
  UiConfirmDialog,
  UiInput,
  UiPageHeader,
  UiSectionCard,
  UiSelect,
  UiStatusBadge,
  UiTableShell
} from '../../components/ui/index.js'

const rows = ref([])
const products = ref([])
const filters = ref({ status: '', priority: '', search: '' })
const form = ref({ priority: 'Normal', reason: '', notes: '', items: [] })
const newItem = ref({ product_id: '', requested_quantity: 1, notes: '' })
const loading = ref(true)
const listError = ref('')
const formError = ref('')
const reviewError = ref('')
const message = ref('')
const creating = ref(false)
const review = ref(null)
const note = ref('')
const submitting = ref(false)
const canRequest = computed(() => sessionStore.can('restock.request'))
const canReview = computed(() => sessionStore.can('restock.approve'))
const selectedProduct = computed(() => products.value.find(product => product.id === Number(newItem.value.product_id)))
const availableProducts = computed(() => products.value.filter(product => !form.value.items.some(item => Number(item.product_id) === product.id)))
const supplierCount = computed(() => new Set(form.value.items.map(item => products.value.find(product => product.id === Number(item.product_id))?.supplier_id).filter(Boolean)).size)

async function load() {
  loading.value = true
  listError.value = ''
  try {
    const params = Object.fromEntries(Object.entries(filters.value).filter(([, value]) => value))
    const data = await api.get('/restock-requests', { ...params, per_page: 100 })
    rows.value = data.requests.data
    products.value = data.products
  } catch (requestError) {
    listError.value = requestError.message
  } finally {
    loading.value = false
  }
}

function selectProduct() {
  const product = selectedProduct.value
  if (product) newItem.value.requested_quantity = Math.max(1, product.max_stock - product.stock_quantity)
}

function addItem() {
  if (!newItem.value.product_id || Number(newItem.value.requested_quantity) < 1) return
  form.value.items.push({ ...newItem.value, product_id: Number(newItem.value.product_id), requested_quantity: Number(newItem.value.requested_quantity) })
  newItem.value = { product_id: '', requested_quantity: 1, notes: '' }
}

function removeItem(index) {
  form.value.items.splice(index, 1)
}

async function createRequest() {
  creating.value = true
  formError.value = ''
  message.value = ''
  try {
    await api.post('/restock-requests', form.value)
    message.value = 'Restock request submitted.'
    form.value = { priority: 'Normal', reason: '', notes: '', items: [] }
    await load()
  } catch (requestError) {
    formError.value = requestError.message
  } finally {
    creating.value = false
  }
}

function startReview(row, decision) {
  review.value = {
    id: row.id,
    decision,
    summary: `${row.ref_number} — ${row.items?.length === 1 ? row.items[0].product_name : `${row.items?.length || 0} items`}`,
    decisions: (row.items || []).map(item => ({
      item_id: item.id,
      label: `${item.sku} — ${item.product_name}`,
      requested_quantity: item.requested_quantity,
      decision,
      approved_quantity: item.requested_quantity,
      notes: ''
    }))
  }
  note.value = ''
  reviewError.value = ''
}

function cancelReview() {
  review.value = null
  note.value = ''
  reviewError.value = ''
}

async function confirmReview() {
  if (!review.value) return
  submitting.value = true
  reviewError.value = ''
  try {
    const decisions = review.value.decisions.map(line => ({
      item_id: line.item_id,
      decision: line.decision,
      // The API rejects an approved quantity on a rejected line.
      approved_quantity: line.decision === 'Approved' ? Number(line.approved_quantity) : null,
      notes: line.notes?.trim() || null
    }))
    await api.post(`/restock-requests/${review.value.id}/review`, { decisions, notes: note.value.trim() || null })
    cancelReview()
    await load()
  } catch (requestError) {
    reviewError.value = requestError.message
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <UiPageHeader title="Restock Requests" description="Request stock replenishment and review pending requests." />

  <UiSectionCard
    v-if="canRequest"
    title="New restock request"
    description="Build one replenishment request with one or more products."
  >
    <form class="restock-form" @submit.prevent="createRequest">
      <div class="restock-form__fields">
        <UiSelect v-model="newItem.product_id" label="Add product" @change="selectProduct">
          <option value="">Select product</option>
          <option v-for="product in availableProducts" :key="product.id" :value="product.id">
            {{ product.sku }} — {{ product.name }}
          </option>
        </UiSelect>
        <UiInput v-model.number="newItem.requested_quantity" type="number" min="1" label="Requested quantity" />
        <UiButton type="button" variant="secondary" @click="addItem">Add item</UiButton>
        <UiSelect v-model="form.priority" label="Priority">
          <option>Low</option>
          <option>Normal</option>
          <option>High</option>
          <option>Urgent</option>
        </UiSelect>
        <label class="ui-field restock-form__span">
          <span class="ui-field__label">Reason<span class="ui-field__required" aria-hidden="true">*</span></span>
          <textarea v-model.trim="form.reason" class="ui-field-control" rows="2" maxlength="500" required></textarea>
        </label>
      </div>
      <p v-if="selectedProduct" class="restock-form__help">
        Current {{ selectedProduct.stock_quantity }} · Reorder {{ selectedProduct.reorder_level }} · Maximum {{ selectedProduct.max_stock }}
      </p>
      <div v-if="form.items.length" class="restock-lines">
        <div v-for="(item, index) in form.items" :key="item.product_id" class="restock-lines__row">
          <span>{{ products.find(product => product.id === Number(item.product_id))?.sku }} — {{ products.find(product => product.id === Number(item.product_id))?.name }}</span>
          <span>Qty {{ item.requested_quantity }}</span>
          <UiButton type="button" size="sm" variant="destructive" @click="removeItem(index)">Remove</UiButton>
        </div>
      </div>
      <p class="restock-form__help">{{ form.items.length }} item{{ form.items.length === 1 ? '' : 's' }}<template v-if="supplierCount"> · {{ supplierCount }} supplier{{ supplierCount === 1 ? '' : 's' }} — this will produce {{ supplierCount }} purchase order{{ supplierCount === 1 ? '' : 's' }}</template></p>
      <p v-if="formError" class="form-error" role="alert">{{ formError }}</p>
      <p v-if="message" class="success-message">{{ message }}</p>
      <UiButton type="submit" :disabled="!form.items.length" :loading="creating" loading-label="Submitting">Submit request</UiButton>
    </form>
  </UiSectionCard>

  <UiTableShell
    title="Request queue"
    :loading="loading"
    :error="listError"
    :empty="!loading && !listError && !rows.length"
    empty-title="No restock requests found"
    empty-description="Try different filters, or check back later."
    @retry="load"
  >
    <template #toolbar>
      <form class="filter-form" @submit.prevent="load">
        <UiInput v-model.trim="filters.search" label="Search" size="sm" placeholder="Reference, SKU, or product" />
        <UiSelect v-model="filters.status" label="Status" size="sm">
          <option value="">All statuses</option>
          <option>Pending Approval</option>
          <option>Approved</option>
          <option>Rejected</option>
          <option>Purchase Order Created</option>
          <option>Ordered</option>
          <option>Partially Received</option>
          <option>Completed</option>
          <option>Cancelled</option>
        </UiSelect>
        <UiSelect v-model="filters.priority" label="Priority" size="sm">
          <option value="">All priorities</option>
          <option>Low</option>
          <option>Normal</option>
          <option>High</option>
          <option>Urgent</option>
        </UiSelect>
        <UiButton type="submit" variant="secondary" size="sm">Apply filters</UiButton>
      </form>
    </template>
    <table>
      <thead>
        <tr>
          <th>Reference</th>
          <th>Products</th>
          <th>Current</th>
          <th>Reorder</th>
          <th>Requested</th>
          <th>Requester</th>
          <th>Priority</th>
          <th>Status</th>
          <th v-if="canReview">Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.id">
          <td>{{ row.ref_number }}</td>
          <td><span v-if="row.items?.length === 1">{{ row.items[0].product_name }}</span><span v-else>{{ row.items?.length || 0 }} items</span></td>
          <td>{{ row.items?.length === 1 ? row.items[0].current_stock : '—' }}</td>
          <td>{{ row.items?.length === 1 ? row.items[0].reorder_level : '—' }}</td>
          <td>{{ row.items?.length === 1 ? row.items[0].requested_quantity : row.items?.reduce((total, item) => total + item.requested_quantity, 0) }}</td>
          <td>{{ row.requested_by }}</td>
          <td>{{ row.priority }}</td>
          <td><UiStatusBadge :status="row.status" /></td>
          <td v-if="canReview">
            <div v-if="row.status === 'Pending Approval'" class="request-actions">
              <UiButton size="sm" @click="startReview(row, 'Approved')">Approve</UiButton>
              <UiButton size="sm" variant="destructive" @click="startReview(row, 'Rejected')">Reject</UiButton>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </UiTableShell>

  <UiConfirmDialog
    :open="!!review"
    :title="`${review?.decision} request`"
    :description="review?.summary"
    :confirm-label="`Confirm ${review?.decision}`"
    :destructive="review?.decision === 'Rejected'"
    :loading="submitting"
    loading-label="Saving"
    @confirm="confirmReview"
    @cancel="cancelReview"
  >
    <div v-if="review" class="review-lines">
      <div v-for="decision in review.decisions" :key="decision.item_id" class="review-lines__row">
        <span class="review-lines__label">{{ decision.label }} · requested {{ decision.requested_quantity }}</span>
        <UiSelect v-model="decision.decision" label="Decision">
          <option>Approved</option>
          <option>Rejected</option>
        </UiSelect>
        <UiInput
          v-if="decision.decision === 'Approved'"
          v-model.number="decision.approved_quantity"
          type="number"
          min="1"
          :max="decision.requested_quantity"
          label="Approved quantity"
        />
      </div>
    </div>
    <UiInput v-model="note" label="Review note" maxlength="500" hint="Optional. This note will be recorded with this action." />
    <p v-if="reviewError" class="form-error" role="alert">{{ reviewError }}</p>
  </UiConfirmDialog>
</template>

<style scoped>
.restock-form {
  display: flex;
  flex-direction: column;
  gap: var(--fm-space-4);
}
.restock-form__fields {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr));
  gap: var(--fm-space-5);
}
.restock-form__span {
  grid-column: 1 / -1;
}
.restock-form__help {
  margin: 0;
  color: var(--fm-color-text-muted);
}
.filter-form {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: end;
}
.request-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.restock-lines, .review-lines { display: flex; flex-direction: column; gap: var(--fm-space-2); }
.restock-lines__row, .review-lines__row { display: flex; flex-wrap: wrap; align-items: end; gap: var(--fm-space-3); padding: var(--fm-space-3); border: 1px solid var(--fm-color-border); border-radius: var(--fm-radius-md); }
.review-lines__label { flex: 1 1 100%; font-weight: 600; }
</style>
