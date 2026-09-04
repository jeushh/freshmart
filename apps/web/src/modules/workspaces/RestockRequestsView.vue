<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import { sessionStore } from '../../stores/session.js'
import {
  UiButton,
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
const form = ref({ product_id: '', requested_quantity: 1, priority: 'Normal', reason: '', notes: '' })
const loading = ref(true)
const error = ref('')
const message = ref('')
const creating = ref(false)
const review = ref(null)
const note = ref('')
const submitting = ref(false)
const canRequest = computed(() => sessionStore.can('restock.request'))
const canReview = computed(() => sessionStore.can('restock.approve'))
const selectedProduct = computed(() => products.value.find(product => product.id === Number(form.value.product_id)))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const params = Object.fromEntries(Object.entries(filters.value).filter(([, value]) => value))
    const data = await api.get('/restock-requests', { ...params, per_page: 100 })
    rows.value = data.requests.data
    products.value = data.products
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    loading.value = false
  }
}

function selectProduct() {
  const product = selectedProduct.value
  if (product) form.value.requested_quantity = Math.max(1, product.max_stock - product.stock_quantity)
}

async function createRequest() {
  creating.value = true
  error.value = ''
  message.value = ''
  try {
    await api.post('/restock-requests', form.value)
    message.value = 'Restock request submitted.'
    form.value = { product_id: '', requested_quantity: 1, priority: 'Normal', reason: '', notes: '' }
    await load()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    creating.value = false
  }
}

function startReview(row, decision) {
  review.value = { id: row.id, decision, summary: `${row.ref_number} — ${row.product_name}` }
  note.value = ''
}

function cancelReview() {
  review.value = null
  note.value = ''
}

async function confirmReview() {
  if (!review.value) return
  submitting.value = true
  error.value = ''
  try {
    await api.post(`/restock-requests/${review.value.id}/review`, { decision: review.value.decision, notes: note.value.trim() || null })
    cancelReview()
    await load()
  } catch (requestError) {
    error.value = requestError.message
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
    description="Submit a replenishment request for a product below its ideal stock level."
  >
    <form class="restock-form" @submit.prevent="createRequest">
      <div class="restock-form__fields">
        <UiSelect v-model="form.product_id" label="Product" required @change="selectProduct">
          <option value="">Select product</option>
          <option v-for="product in products" :key="product.id" :value="product.id">
            {{ product.sku }} — {{ product.name }}
          </option>
        </UiSelect>
        <UiInput v-model.number="form.requested_quantity" type="number" min="1" label="Requested quantity" required />
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
      <p v-if="error" class="form-error" role="alert">{{ error }}</p>
      <p v-if="message" class="success-message">{{ message }}</p>
      <UiButton type="submit" :loading="creating" loading-label="Submitting">Submit request</UiButton>
    </form>
  </UiSectionCard>

  <UiTableShell
    title="Request queue"
    :loading="loading"
    :error="error"
    :empty="!loading && !error && !rows.length"
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
          <th>Product</th>
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
          <td>{{ row.product_name }}</td>
          <td>{{ row.current_stock }}</td>
          <td>{{ row.reorder_level }}</td>
          <td>{{ row.requested_quantity }}</td>
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

  <section v-if="review" class="ui-card review-panel" aria-labelledby="review-title">
    <h2 id="review-title">{{ review.decision }} request</h2>
    <p>{{ review.summary }}</p>
    <UiInput v-model="note" label="Review note" maxlength="500" hint="Optional. This note will be recorded with this action." />
    <p v-if="error" class="form-error" role="alert">{{ error }}</p>
    <div class="request-actions">
      <UiButton :loading="submitting" loading-label="Saving" @click="confirmReview">Confirm {{ review.decision }}</UiButton>
      <UiButton variant="secondary" :disabled="submitting" @click="cancelReview">Cancel</UiButton>
    </div>
  </section>
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
.review-panel {
  margin-top: var(--fm-space-4);
  padding: var(--fm-space-5);
  display: grid;
  gap: var(--fm-space-3);
}
</style>
