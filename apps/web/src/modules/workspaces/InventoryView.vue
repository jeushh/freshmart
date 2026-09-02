<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../../api/http.js'
import {
  UiButton,
  UiInput,
  UiPageHeader,
  UiSearchInput,
  UiSelect,
  UiStatusBadge,
  UiTableShell
} from '../../components/ui/index.js'
import { sessionStore } from '../../stores/session.js'
import { formatDateTime, formatMoney } from '../../utils/formatters.js'

const rows = ref([])
const pagination = ref({ current_page: 1, last_page: 1, total: 0 })
const lowStockRows = ref([])
const movements = ref([])
const error = ref('')
const loading = ref(true)
const search = ref('')
const categoryFilter = ref('')
const route = useRoute()
const section = computed(() => {
  if (route.path.endsWith('/low-stock')) return 'low-stock'
  if (route.path.endsWith('/movements')) return 'movements'
  return 'products'
})
const page = computed(() => ({
  products: {
    title: 'Products & Stock',
    description: 'Manage product information and make audited stock adjustments.'
  },
  'low-stock': {
    title: 'Low Stock',
    description: 'Monitor active products at or below their reorder levels.'
  },
  movements: {
    title: 'Inventory Movements',
    description: 'Review the latest audited changes to product stock.'
  }
})[section.value])
const blankForm = () => ({
  sku: '',
  name: '',
  category: 'General',
  price: 0,
  cost_price: 0,
  stock_quantity: 0,
  reorder_level: 5,
  unit: 'pc',
  supplier_id: null,
  status: 'Active'
})
const form = ref(blankForm())
const saving = ref(false)

const adjustTarget = ref(null)
const adjustQuantity = ref(0)
const adjustNotes = ref('')
const adjustSubmitting = ref(false)
const adjustError = ref('')
const adjustDialogPanel = ref(null)
const adjustTriggerButton = ref(null)

const categories = computed(() => {
  const unique = new Set(rows.value.map(row => row.category).filter(Boolean))
  return [...unique].sort()
})
const filteredRows = computed(() => {
  if (!categoryFilter.value) return rows.value
  return rows.value.filter(row => row.category === categoryFilter.value)
})

async function load(page_ = pagination.value.current_page) {
  loading.value = true
  error.value = ''
  try {
    await sessionStore.refreshSettings()
    const params = { per_page: 20, page: page_ }
    const searchTerm = search.value.trim()
    if (searchTerm) params.search = searchTerm
    const data = await api.get('/workspace/inventory', params)
    rows.value = data.products.data
    pagination.value = data.products
    lowStockRows.value = data.low_stock_products
    movements.value = data.inventory_movements
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    await api.post('/workspace/products', form.value)
    form.value = blankForm()
    await load(1)
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    saving.value = false
  }
}

async function openAdjust(row, event) {
  adjustTarget.value = row
  adjustQuantity.value = 0
  adjustNotes.value = ''
  adjustError.value = ''
  adjustTriggerButton.value = event?.currentTarget || null
  await nextTick()
  adjustDialogPanel.value?.querySelector('input')?.focus()
}

async function cancelAdjust() {
  if (adjustSubmitting.value) return
  adjustTarget.value = null
  await nextTick()
  adjustTriggerButton.value?.focus()
}

async function confirmAdjust() {
  if (!adjustTarget.value || !adjustQuantity.value) {
    adjustError.value = 'Enter a non-zero quantity.'
    return
  }
  adjustSubmitting.value = true
  adjustError.value = ''
  try {
    await api.post(`/workspace/products/${adjustTarget.value.id}/adjust`, {
      quantity: adjustQuantity.value,
      notes: adjustNotes.value.trim() || null
    })
    adjustTarget.value = null
    await load()
  } catch (requestError) {
    adjustError.value = requestError.message
  } finally {
    adjustSubmitting.value = false
  }
}

function handleAdjustDialogKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    cancelAdjust()
    return
  }
  if (event.key !== 'Tab') return

  const focusable = [...adjustDialogPanel.value.querySelectorAll(
    'button:not(:disabled), input:not(:disabled), textarea:not(:disabled), select:not(:disabled), [href], [tabindex]:not([tabindex="-1"])'
  )]
  if (!focusable.length) return
  const first = focusable[0]
  const last = focusable.at(-1)
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

watch(search, () => load(1))

watch(section, () => { categoryFilter.value = '' })

onMounted(() => load())
</script>

<template>
  <UiPageHeader :title="page.title" :description="page.description" />

  <section v-if="section === 'products' && sessionStore.can('inventory.manage')" class="ui-card add-product-card">
    <h2 class="add-product-card__title">Add product</h2>
    <form class="add-product-form" @submit.prevent="save">
      <UiInput v-model="form.sku" label="SKU" required />
      <UiInput v-model="form.name" label="Product name" required />
      <UiInput v-model="form.category" label="Category" required />
      <UiInput v-model.number="form.price" type="number" min="0" step=".01" label="Price" required />
      <UiInput v-model.number="form.cost_price" type="number" min="0" step=".01" label="Cost" required />
      <UiInput v-model.number="form.stock_quantity" type="number" min="0" label="Initial stock" />
      <UiInput v-model.number="form.reorder_level" type="number" min="0" label="Reorder level" required />
      <UiInput v-model="form.unit" label="Unit" required />
      <UiSelect v-model="form.status" label="Status">
        <option value="Active">Active</option>
        <option value="Inactive">Inactive</option>
      </UiSelect>
      <UiButton type="submit" :loading="saving" loading-label="Adding product">Add product</UiButton>
    </form>
  </section>

  <UiTableShell
    v-if="section === 'products'"
    title="Products"
    :loading="loading"
    :error="error"
    :empty="!loading && !error && !filteredRows.length"
    empty-title="No products found"
    empty-description="Try a different search term or category filter."
    @retry="() => load()"
  >
    <template #toolbar>
      <div class="filter-form">
        <UiSearchInput v-model="search" label="Search products" placeholder="Search by name or SKU" />
        <UiSelect v-model="categoryFilter" label="Category" size="sm">
          <option value="">All categories</option>
          <option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
        </UiSelect>
      </div>
    </template>
    <table>
      <thead>
        <tr>
          <th>SKU</th>
          <th>Product</th>
          <th>Category</th>
          <th>Price</th>
          <th>Stock</th>
          <th>Reorder</th>
          <th>Status</th>
          <th v-if="sessionStore.can('inventory.manage')">Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in filteredRows" :key="row.id">
          <td>{{ row.sku }}</td>
          <td>{{ row.emoji }} {{ row.name }}</td>
          <td><span class="category-pill">{{ row.category }}</span></td>
          <td>{{ formatMoney(row.price) }}</td>
          <td>
            <UiStatusBadge
              v-if="row.stock_quantity <= row.reorder_level"
              status="Low"
              tone="danger"
              :label="String(row.stock_quantity)"
            />
            <span v-else>{{ row.stock_quantity }}</span>
          </td>
          <td>{{ row.reorder_level }}</td>
          <td><UiStatusBadge :status="row.status" /></td>
          <td v-if="sessionStore.can('inventory.manage')">
            <UiButton size="sm" variant="secondary" @click="openAdjust(row, $event)">Adjust stock</UiButton>
          </td>
        </tr>
      </tbody>
    </table>
    <template #footer>
      <div class="pagination">
        <span>Page {{ pagination.current_page }} of {{ pagination.last_page }} · {{ pagination.total }} products</span>
        <div>
          <UiButton size="sm" variant="secondary" :disabled="pagination.current_page <= 1" @click="load(pagination.current_page - 1)">Previous</UiButton>
          <UiButton size="sm" variant="secondary" :disabled="pagination.current_page >= pagination.last_page" @click="load(pagination.current_page + 1)">Next</UiButton>
        </div>
      </div>
    </template>
  </UiTableShell>

  <UiTableShell
    v-else-if="section === 'low-stock'"
    title="Low stock"
    :loading="loading"
    :error="error"
    :empty="!loading && !error && !lowStockRows.length"
    empty-title="No low-stock products"
    empty-description="Every active product is currently above its reorder level."
    @retry="() => load()"
  >
    <table>
      <thead>
        <tr>
          <th>SKU</th>
          <th>Product</th>
          <th>Current stock</th>
          <th>Reorder level</th>
          <th>Maximum stock</th>
          <th>Unit</th>
          <th>Supplier</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in lowStockRows" :key="row.id">
          <td>{{ row.sku }}</td>
          <td>{{ row.name }}</td>
          <td><UiStatusBadge status="Low" tone="danger" :label="String(row.stock_quantity)" /></td>
          <td>{{ row.reorder_level }}</td>
          <td>{{ row.max_stock }}</td>
          <td>{{ row.unit }}</td>
          <td>{{ row.supplier_name || '—' }}</td>
        </tr>
      </tbody>
    </table>
  </UiTableShell>

  <UiTableShell
    v-else
    title="Inventory movements"
    :loading="loading"
    :error="error"
    :empty="!loading && !error && !movements.length"
    empty-title="No movements yet"
    empty-description="Audited stock changes will appear here."
    @retry="() => load()"
  >
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>SKU</th>
          <th>Product</th>
          <th>Movement</th>
          <th>Quantity</th>
          <th>Previous</th>
          <th>New stock</th>
          <th>Performed by</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="movement in movements" :key="movement.id">
          <td>{{ formatDateTime(movement.created_at) }}</td>
          <td>{{ movement.sku }}</td>
          <td>{{ movement.product_name }}</td>
          <td>{{ movement.movement_type }}</td>
          <td>{{ movement.quantity }}</td>
          <td>{{ movement.previous_stock }}</td>
          <td>{{ movement.new_stock }}</td>
          <td>{{ movement.performed_by }}</td>
        </tr>
      </tbody>
    </table>
  </UiTableShell>

  <Teleport to="body">
    <div
      v-if="adjustTarget"
      class="adjust-dialog-backdrop"
      @click.self="cancelAdjust"
      @keydown="handleAdjustDialogKeydown"
    >
      <section
        ref="adjustDialogPanel"
        class="adjust-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="adjust-dialog-title"
        aria-describedby="adjust-dialog-summary"
      >
        <header class="adjust-dialog__header">
          <p class="adjust-dialog__eyebrow">Audited stock adjustment</p>
          <h2 id="adjust-dialog-title">{{ adjustTarget.name }}</h2>
        </header>
        <form class="adjust-dialog__body" @submit.prevent="confirmAdjust">
          <p id="adjust-dialog-summary">Enter a positive quantity to add stock or a negative quantity to remove it.</p>
          <dl class="adjust-details">
            <div><dt>SKU</dt><dd>{{ adjustTarget.sku }}</dd></div>
            <div><dt>Current stock</dt><dd>{{ adjustTarget.stock_quantity }}</dd></div>
          </dl>
          <UiInput v-model.number="adjustQuantity" type="number" step="1" label="Quantity" hint="Negative values remove stock." required />
          <UiInput v-model="adjustNotes" label="Notes" hint="Optional. Recorded with this adjustment." />
          <p v-if="adjustError" class="form-error" role="alert">{{ adjustError }}</p>
          <footer class="adjust-dialog__actions">
            <UiButton type="button" variant="secondary" :disabled="adjustSubmitting" @click="cancelAdjust">Cancel</UiButton>
            <UiButton type="submit" :loading="adjustSubmitting" loading-label="Saving adjustment">Save adjustment</UiButton>
          </footer>
        </form>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.add-product-card {
  margin-bottom: 1rem;
  padding: 1rem;
}
.add-product-card__title {
  margin: 0 0 0.75rem;
}
.add-product-form {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: flex-end;
}
.filter-form {
  display: flex;
  gap: 0.75rem;
  align-items: end;
  flex-wrap: wrap;
}
.category-pill {
  display: inline-block;
  padding: 0.125rem 0.5rem;
  border-radius: 999px;
  background: var(--fm-color-slate-100);
  font-size: 0.85em;
}
.pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.adjust-dialog-backdrop {
  position: fixed;
  inset: 0;
  z-index: calc(var(--fm-z-dropdown) + 10);
  display: grid;
  place-items: center;
  padding: var(--fm-space-4);
  overflow-y: auto;
  background: var(--fm-color-overlay);
}

.adjust-dialog {
  width: min(28rem, 100%);
  max-height: calc(100dvh - (2 * var(--fm-space-4)));
  overflow-y: auto;
  border: var(--fm-border-width) solid var(--fm-color-border);
  border-radius: var(--fm-radius-panel);
  background: var(--fm-color-surface);
  box-shadow: var(--fm-shadow-menu);
}

.adjust-dialog__header,
.adjust-dialog__body,
.adjust-dialog__actions {
  padding: var(--fm-space-5);
}

.adjust-dialog__header {
  border-bottom: var(--fm-border-width) solid var(--fm-color-border);
}

.adjust-dialog__header h2,
.adjust-dialog__eyebrow,
.adjust-dialog__body > p {
  margin: 0;
}

.adjust-dialog__eyebrow {
  color: var(--fm-color-primary-700);
  font-size: var(--fm-font-size-xs);
  font-weight: var(--fm-font-weight-bold);
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.adjust-dialog__header h2 {
  margin-top: var(--fm-space-1);
  font-size: var(--fm-font-size-xl);
}

.adjust-dialog__body {
  display: grid;
  gap: var(--fm-space-4);
}

.adjust-details {
  display: grid;
  gap: var(--fm-space-2);
  margin: 0;
}

.adjust-details > div {
  display: flex;
  justify-content: space-between;
  gap: var(--fm-space-4);
}

.adjust-details dd {
  margin: 0;
  font-weight: var(--fm-font-weight-semibold);
  text-align: right;
}

.adjust-dialog__actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--fm-space-3);
  border-top: var(--fm-border-width) solid var(--fm-color-border);
}

@media (max-width: 30rem) {
  .adjust-dialog-backdrop {
    align-items: end;
    padding: var(--fm-space-2);
  }

  .adjust-dialog {
    max-height: calc(100dvh - (2 * var(--fm-space-2)));
  }

  .adjust-dialog__header,
  .adjust-dialog__body,
  .adjust-dialog__actions {
    padding: var(--fm-space-4);
  }

  .adjust-dialog__actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
  }
}
</style>
