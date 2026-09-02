<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import PageHeader from '../../components/common/PageHeader.vue'
import { UiButton, UiEmptyState, UiSearchInput, UiStatusBadge } from '../../components/ui/index.js'
import { sessionStore } from '../../stores/session.js'
import { formatMoney } from '../../utils/formatters.js'

const LOW_STOCK_THRESHOLD = 5
const PAYMENT_METHODS = ['Cash', 'Card', 'QR']

const products = ref([])
const cart = ref([])
const payment = ref('Cash')
const searchQuery = ref('')
const activeCategory = ref('All')
const message = ref('')
const error = ref('')
const confirming = ref(false)
const submitting = ref(false)
const completedSale = ref(null)
const productsReady = ref(false)
const refreshingProducts = ref(false)
const productRefreshError = ref('')
const checkoutButton = ref(null)
const cancelButton = ref(null)
const dialogPanel = ref(null)
const refundStep = ref('')
const selectedRefundLine = ref(null)
const refundQuantity = ref(1)
const refundReason = ref('')
const refundSubmitting = ref(false)
const refundError = ref('')
const refundSuccess = ref(null)
const confirmedRefundedBySku = ref({})
const refundCancelButton = ref(null)
const refundDialogPanel = ref(null)
const baseTotal = computed(() => cart.value.reduce((sum, item) => sum + item.price * item.quantity, 0))
const itemCount = computed(() => cart.value.reduce((sum, item) => sum + item.quantity, 0))
const tax = computed(() => {
  const rate = Number(sessionStore.state.settings.tax_rate || 0)
  return sessionStore.state.settings.tax_inclusive
    ? baseTotal.value * rate / (100 + rate)
    : baseTotal.value * rate / 100
})
const total = computed(() => sessionStore.state.settings.tax_inclusive
  ? baseTotal.value
  : baseTotal.value + tax.value)
const subtotal = computed(() => total.value - tax.value)

const categories = computed(() => {
  const found = new Set(products.value.map(product => product.category).filter(Boolean))
  return ['All', ...[...found].sort()]
})

const filteredProducts = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  return products.value.filter(product => {
    const matchesCategory = activeCategory.value === 'All' || product.category === activeCategory.value
    if (!matchesCategory) return false
    if (!query) return true
    return product.name.toLowerCase().includes(query) || product.sku.toLowerCase().includes(query)
  })
})

function stockTone(product) {
  if (product.stock_quantity < 1) return 'danger'
  if (product.stock_quantity <= LOW_STOCK_THRESHOLD) return 'warning'
  return 'success'
}

function stockLabel(product) {
  if (product.stock_quantity < 1) return 'Out of stock'
  if (product.stock_quantity <= LOW_STOCK_THRESHOLD) return `Low stock · ${product.stock_quantity} left`
  return `${product.stock_quantity} in stock`
}

function cartQuantityFor(productId) {
  return cart.value.find(item => item.id === productId)?.quantity || 0
}

async function load() {
  refreshingProducts.value = true
  productsReady.value = false
  try {
    const data = await api.get('/workspace/pos')
    products.value = data.products
    sessionStore.updateSettings(data.settings)
    productRefreshError.value = ''
    error.value = ''
    productsReady.value = true
    return true
  } catch (requestError) {
    productRefreshError.value = requestError.message
    error.value = requestError.message
    return false
  } finally {
    refreshingProducts.value = false
  }
}

function add(product) {
  const existing = cart.value.find(item => item.id === product.id)
  if (existing && existing.quantity < product.stock_quantity) existing.quantity++
  else if (!existing && product.stock_quantity > 0) cart.value.push({ ...product, quantity: 1 })
}

function decrement(item) {
  const index = cart.value.findIndex(cartItem => cartItem.id === item.id)
  if (index < 0) return
  if (cart.value[index].quantity === 1) cart.value.splice(index, 1)
  else cart.value[index].quantity--
}

async function openConfirmation() {
  if (!cart.value.length || !productsReady.value || submitting.value) return
  confirming.value = true
  await nextTick()
  cancelButton.value?.focus()
}

async function cancelConfirmation() {
  if (submitting.value) return
  confirming.value = false
  await nextTick()
  checkoutButton.value?.focus()
}

function handleDialogKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    cancelConfirmation()
    return
  }
  if (event.key !== 'Tab') return

  const focusable = [...dialogPanel.value.querySelectorAll(
    'button:not(:disabled), select:not(:disabled), [href], [tabindex]:not([tabindex="-1"])'
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

function handleRefundDialogKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    cancelRefund()
    return
  }
  if (event.key !== 'Tab') return

  const focusable = [...refundDialogPanel.value.querySelectorAll(
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

function completedSaleSnapshot(result) {
  const settings = sessionStore.state.settings
  const items = Object.freeze(result.items.map(item => Object.freeze({ ...item })))

  return Object.freeze({
    order_id: result.order_id,
    total: result.total,
    tax_total: result.tax_total,
    tax_rate: result.tax_rate,
    tax_inclusive: result.tax_inclusive,
    completed_at: result.completed_at,
    cashier_username: result.cashier_username,
    payment_method: result.payment_method,
    subtotal: result.subtotal,
    items,
    business_name: settings.business_name,
    business_address: settings.business_address,
    business_contact: settings.business_contact,
    receipt_footer: settings.receipt_footer,
    currency_code: settings.currency_code,
    currency_symbol: settings.currency_symbol,
    currency_locale: settings.currency_locale,
    timezone: settings.timezone
  })
}

function formatReceiptMoney(value) {
  const sale = completedSale.value
  try {
    return new Intl.NumberFormat(sale.currency_locale || 'en-PH', {
      style: 'currency',
      currency: sale.currency_code || 'PHP'
    }).format(Number(value) || 0)
  } catch {
    return `${sale.currency_symbol || '₱'}${(Number(value) || 0).toFixed(2)}`
  }
}

function formatReceiptDateTime(value) {
  const sale = completedSale.value
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value
  return new Intl.DateTimeFormat(sale.currency_locale || 'en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: sale.timezone || 'Asia/Manila'
  }).format(parsed)
}

function printReceipt() {
  window.print()
}

function refundedQuantityFor(item) {
  return confirmedRefundedBySku.value[item.sku] || 0
}

async function openRefund(line) {
  if (!sessionStore.can('pos.refund')) return
  selectedRefundLine.value = line
  refundQuantity.value = 1
  refundReason.value = ''
  refundError.value = ''
  refundStep.value = 'configure'
  await nextTick()
  refundCancelButton.value?.focus()
}

function resetRefundFlow() {
  refundStep.value = ''
  selectedRefundLine.value = null
  refundQuantity.value = 1
  refundReason.value = ''
  refundError.value = ''
}

function resetRefundSession() {
  resetRefundFlow()
  refundSuccess.value = null
  confirmedRefundedBySku.value = {}
}

function cancelRefund() {
  if (refundSubmitting.value) return
  resetRefundFlow()
}

function reviewRefund() {
  const quantity = Number(refundQuantity.value)
  if (!Number.isInteger(quantity) || quantity < 1) {
    refundError.value = 'Refund quantity must be a positive whole number.'
    return
  }
  if (quantity > Number(selectedRefundLine.value?.quantity || 0)) {
    refundError.value = 'Refund quantity cannot exceed the original sold quantity shown on this receipt.'
    return
  }
  if (!refundReason.value.trim()) {
    refundError.value = 'A refund reason is required.'
    return
  }
  refundError.value = ''
  refundStep.value = 'review'
}

function backToRefundConfiguration() {
  if (refundSubmitting.value) return
  refundError.value = ''
  refundStep.value = 'configure'
}

async function confirmRefund() {
  if (refundSubmitting.value || refundStep.value !== 'review' || !completedSale.value || !selectedRefundLine.value) return
  refundSubmitting.value = true
  refundError.value = ''
  try {
    const result = await api.post('/workspace/pos/refunds', {
      order_id: completedSale.value.order_id,
      item_sku: selectedRefundLine.value.sku,
      quantity: Number(refundQuantity.value),
      reason: refundReason.value.trim()
    })
    const itemSku = result.item_sku
    confirmedRefundedBySku.value = {
      ...confirmedRefundedBySku.value,
      [itemSku]: (confirmedRefundedBySku.value[itemSku] || 0) + Number(result.quantity_refunded)
    }
    refundSuccess.value = result
    resetRefundFlow()
  } catch (requestError) {
    refundError.value = requestError.message
    refundStep.value = 'configure'
  } finally {
    refundSubmitting.value = false
  }
}

async function newSale() {
  const refreshRequired = !productsReady.value || Boolean(productRefreshError.value)
  resetRefundSession()
  completedSale.value = null
  cart.value = []
  payment.value = 'Cash'
  message.value = ''
  if (refreshRequired) await load()
  else error.value = ''
}

async function checkout() {
  if (submitting.value) return
  submitting.value = true
  try {
    error.value = ''
    message.value = ''
    const result = await api.post('/workspace/pos/checkout', {
      items: cart.value.map(item => ({ product_id: item.id, quantity: item.quantity })),
      payment_method: payment.value
    })
    resetRefundSession()
    completedSale.value = completedSaleSnapshot(result)
    message.value = `Order ${result.order_id} completed — ${formatMoney(result.total)}`
    confirming.value = false
    cart.value = []
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
  <div v-if="completedSale" class="receipt-page">
    <PageHeader class="receipt-screen-header" title="Sale complete" description="The transaction was completed successfully.">
      <div class="receipt-actions">
        <UiButton variant="secondary" @click="printReceipt">Print receipt</UiButton>
        <UiButton @click="newSale">New sale</UiButton>
      </div>
    </PageHeader>

    <div v-if="productRefreshError" class="form-error receipt-refresh-error" role="alert">
      <div>
        <strong>Sale completed, but products could not be refreshed.</strong>
        <span>{{ productRefreshError }}</span>
      </div>
      <UiButton variant="secondary" :loading="refreshingProducts" loading-label="Refreshing products" @click="load">
        Retry refresh
      </UiButton>
    </div>

    <article class="receipt" aria-labelledby="receipt-title">
      <header class="receipt__header">
        <p class="receipt__status">Payment accepted</p>
        <h2 id="receipt-title">{{ completedSale.business_name }}</h2>
        <p v-if="completedSale.business_address">{{ completedSale.business_address }}</p>
        <p v-if="completedSale.business_contact">{{ completedSale.business_contact }}</p>
      </header>

      <dl class="receipt__metadata">
        <div><dt>Receipt</dt><dd>{{ completedSale.order_id }}</dd></div>
        <div><dt>Date</dt><dd>{{ formatReceiptDateTime(completedSale.completed_at) }}</dd></div>
        <div><dt>Cashier</dt><dd>{{ completedSale.cashier_username }}</dd></div>
        <div><dt>Payment</dt><dd>{{ completedSale.payment_method }}</dd></div>
      </dl>

      <div class="receipt__items">
        <div v-for="item in completedSale.items" :key="`${item.product_id}-${item.sku}`" class="receipt__item">
          <div>
            <strong>{{ item.name }}</strong>
            <small>{{ item.sku }} · {{ item.quantity }} × {{ formatReceiptMoney(item.unit_price) }}</small>
          </div>
          <div class="receipt__item-actions">
            <strong>{{ formatReceiptMoney(item.total) }}</strong>
            <div v-if="sessionStore.can('pos.refund')" class="receipt__refund">
              <small v-if="refundedQuantityFor(item)">{{ refundedQuantityFor(item) }} of {{ item.quantity }} refunded</small>
              <UiButton size="sm" variant="secondary" @click="openRefund(item)">Refund item</UiButton>
            </div>
          </div>
        </div>
      </div>

      <div v-if="refundSuccess" class="receipt__refund-success" role="status">
        <strong>Refund completed: {{ refundSuccess.quantity_refunded }} {{ refundSuccess.quantity_refunded === 1 ? 'unit' : 'units' }} of {{ refundSuccess.item_sku }}.</strong>
        <span>{{ formatReceiptMoney(refundSuccess.refund_amount) }} was refunded. Stock was restored.</span>
      </div>

      <dl class="receipt__totals">
        <div><dt>Subtotal</dt><dd>{{ formatReceiptMoney(completedSale.subtotal) }}</dd></div>
        <div>
          <dt>Tax ({{ completedSale.tax_rate }}%{{ completedSale.tax_inclusive ? ', included' : '' }})</dt>
          <dd>{{ formatReceiptMoney(completedSale.tax_total) }}</dd>
        </div>
        <div class="receipt__grand-total"><dt>Total</dt><dd>{{ formatReceiptMoney(completedSale.total) }}</dd></div>
      </dl>

      <footer class="receipt__footer">
        <p>{{ completedSale.receipt_footer || 'Thank you for shopping with us.' }}</p>
        <small>{{ completedSale.order_id }}</small>
      </footer>
    </article>
  </div>

  <template v-else>
    <PageHeader title="Point of Sale" description="Process sales with automatic stock, tax, and ledger updates." />
    <div v-if="error" class="form-error pos-error" role="alert">
      <span>{{ error }}</span>
      <UiButton
        v-if="!productsReady"
        variant="secondary"
        :loading="refreshingProducts"
        loading-label="Refreshing products"
        @click="load"
      >
        Retry products
      </UiButton>
    </div>
    <p v-if="message" class="success-message">{{ message }}</p>

    <div class="pos-workspace">
      <section class="pos-catalog" aria-label="Product catalog">
        <div class="pos-catalog__toolbar">
          <UiSearchInput
            v-model="searchQuery"
            class="pos-catalog__search"
            label="Search products"
            placeholder="Search by name or SKU…"
          />
          <div class="pos-categories" role="tablist" aria-label="Filter by category">
            <button
              v-for="category in categories"
              :key="category"
              type="button"
              role="tab"
              class="pos-category-pill"
              :class="{ 'pos-category-pill--active': activeCategory === category }"
              :aria-selected="activeCategory === category"
              @click="activeCategory = category"
            >
              {{ category }}
            </button>
          </div>
        </div>

        <UiEmptyState
          v-if="!filteredProducts.length"
          title="No products match"
          description="Try a different search term or category."
        />
        <div v-else class="pos-product-grid" :aria-busy="refreshingProducts || undefined">
          <button
            v-for="product in filteredProducts"
            :key="product.id"
            type="button"
            class="pos-product-card"
            :disabled="!productsReady || product.stock_quantity < 1"
            @click="add(product)"
          >
            <span class="pos-product-card__media" aria-hidden="true">{{ product.emoji || '🛒' }}</span>
            <span class="pos-product-card__name">{{ product.name }}</span>
            <span class="pos-product-card__price">{{ formatMoney(product.price) }}</span>
            <UiStatusBadge class="pos-product-card__stock" :status="stockLabel(product)" :tone="stockTone(product)" />
            <span v-if="cartQuantityFor(product.id)" class="pos-product-card__in-cart">{{ cartQuantityFor(product.id) }} in cart</span>
          </button>
        </div>
      </section>

      <aside class="pos-cart" aria-label="Current sale">
        <div class="pos-cart__header">
          <h2>Current sale</h2>
          <span v-if="itemCount" class="ui-badge ui-badge--info">{{ itemCount }} {{ itemCount === 1 ? 'item' : 'items' }}</span>
        </div>

        <div class="pos-cart__lines">
          <UiEmptyState v-if="!cart.length" title="Cart is empty" description="Select a product to start this sale." />
          <div v-for="item in cart" :key="item.id" class="pos-cart-line">
            <div class="pos-cart-line__info">
              <strong>{{ item.name }}</strong>
              <small>{{ formatMoney(item.price) }} each</small>
            </div>
            <div class="pos-cart-line__controls">
              <button
                type="button"
                class="pos-qty-button"
                :aria-label="`Remove one ${item.name}`"
                @click="decrement(item)"
              >−</button>
              <span class="pos-qty-value">{{ item.quantity }}</span>
              <button
                type="button"
                class="pos-qty-button"
                :aria-label="`Add one more ${item.name}`"
                :disabled="item.quantity >= item.stock_quantity"
                @click="add(item)"
              >+</button>
            </div>
            <strong class="pos-cart-line__total">{{ formatMoney(item.price * item.quantity) }}</strong>
          </div>
        </div>

        <div class="pos-cart__summary">
          <div class="pos-cart__summary-row">
            <span>Subtotal</span><strong>{{ formatMoney(subtotal) }}</strong>
          </div>
          <div class="pos-cart__summary-row">
            <span>Tax ({{ sessionStore.state.settings.tax_rate }}%{{ sessionStore.state.settings.tax_inclusive ? ', included' : '' }})</span>
            <strong>{{ formatMoney(tax) }}</strong>
          </div>
          <div class="pos-cart__summary-row pos-cart__summary-row--total">
            <span>Total</span><strong>{{ formatMoney(total) }}</strong>
          </div>
        </div>

        <fieldset class="pos-payment">
          <legend>Payment method</legend>
          <div class="pos-payment__options">
            <button
              v-for="method in PAYMENT_METHODS"
              :key="method"
              type="button"
              class="pos-payment-option"
              :class="{ 'pos-payment-option--active': payment === method }"
              :aria-pressed="payment === method"
              @click="payment = method"
            >
              {{ method }}
            </button>
          </div>
        </fieldset>

        <UiButton
          ref="checkoutButton"
          class="pos-checkout-button"
          size="lg"
          :disabled="!cart.length || !productsReady"
          @click="openConfirmation"
        >
          Review sale · {{ formatMoney(total) }}
        </UiButton>
      </aside>
    </div>
  </template>

  <Teleport to="body">
    <div
      v-if="confirming"
      class="checkout-dialog-backdrop"
      @click.self="cancelConfirmation"
      @keydown="handleDialogKeydown"
    >
      <section
        ref="dialogPanel"
        class="checkout-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="checkout-confirmation-title"
        aria-describedby="checkout-confirmation-summary"
      >
        <header class="checkout-dialog__header">
          <div>
            <p class="checkout-dialog__eyebrow">Review transaction</p>
            <h2 id="checkout-confirmation-title">Confirm sale</h2>
          </div>
        </header>

        <div class="checkout-dialog__body">
          <p id="checkout-confirmation-summary">
            Confirm {{ itemCount }} {{ itemCount === 1 ? 'item' : 'items' }} before completing this sale.
          </p>
          <ul class="checkout-dialog__items">
            <li v-for="item in cart" :key="item.id">
              <span>{{ item.name }} × {{ item.quantity }}</span>
              <span>{{ formatMoney(item.price) }} each</span>
            </li>
          </ul>
          <dl class="checkout-dialog__totals">
            <div><dt>Subtotal</dt><dd>{{ formatMoney(subtotal) }}</dd></div>
            <div>
              <dt>Tax ({{ sessionStore.state.settings.tax_rate }}%{{ sessionStore.state.settings.tax_inclusive ? ', included' : '' }})</dt>
              <dd>{{ formatMoney(tax) }}</dd>
            </div>
            <div class="checkout-dialog__total"><dt>Total</dt><dd>{{ formatMoney(total) }}</dd></div>
            <div><dt>Payment method</dt><dd>{{ payment }}</dd></div>
          </dl>
          <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        </div>

        <footer class="checkout-dialog__actions">
          <UiButton ref="cancelButton" variant="secondary" :disabled="submitting" @click="cancelConfirmation">
            Cancel
          </UiButton>
          <UiButton :loading="submitting" loading-label="Completing sale" @click="checkout">
            Complete sale
          </UiButton>
        </footer>
      </section>
    </div>
  </Teleport>

  <Teleport to="body">
    <div
      v-if="refundStep"
      class="checkout-dialog-backdrop"
      @click.self="cancelRefund"
      @keydown="handleRefundDialogKeydown"
    >
      <section
        ref="refundDialogPanel"
        class="checkout-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="refund-dialog-title"
        aria-describedby="refund-dialog-summary"
      >
        <header class="checkout-dialog__header">
          <div>
            <p class="checkout-dialog__eyebrow">Receipt refund</p>
            <h2 id="refund-dialog-title">{{ refundStep === 'review' ? 'Review refund' : 'Refund item' }}</h2>
          </div>
        </header>

        <form v-if="refundStep === 'configure'" class="checkout-dialog__body refund-form" @submit.prevent="reviewRefund">
          <p id="refund-dialog-summary">Select the quantity and reason before reviewing this refund.</p>
          <dl class="refund-details">
            <div><dt>Item</dt><dd>{{ selectedRefundLine.name }}</dd></div>
            <div><dt>SKU</dt><dd>{{ selectedRefundLine.sku }}</dd></div>
            <div><dt>Original sold quantity</dt><dd>{{ selectedRefundLine.quantity }}</dd></div>
          </dl>
          <label>
            Quantity to refund
            <input v-model.number="refundQuantity" type="number" min="1" :max="selectedRefundLine.quantity" step="1" required>
          </label>
          <label>
            Reason
            <textarea v-model.trim="refundReason" rows="3" maxlength="500" required></textarea>
          </label>
          <p class="field-help">The server confirms the refundable quantity when you confirm this refund.</p>
          <p v-if="refundError" class="form-error" role="alert">{{ refundError }}</p>
          <footer class="checkout-dialog__actions">
            <UiButton ref="refundCancelButton" type="button" variant="secondary" :disabled="refundSubmitting" @click="cancelRefund">
              Cancel
            </UiButton>
            <UiButton type="submit" :disabled="refundSubmitting">Review refund</UiButton>
          </footer>
        </form>

        <div v-else class="checkout-dialog__body">
          <p id="refund-dialog-summary">Confirm the refund details below. The final refund amount is calculated by the server.</p>
          <dl class="refund-details">
            <div><dt>Receipt / order ID</dt><dd>{{ completedSale.order_id }}</dd></div>
            <div><dt>Item</dt><dd>{{ selectedRefundLine.name }}</dd></div>
            <div><dt>SKU</dt><dd>{{ selectedRefundLine.sku }}</dd></div>
            <div><dt>Requested quantity</dt><dd>{{ refundQuantity }}</dd></div>
            <div><dt>Reason</dt><dd>{{ refundReason }}</dd></div>
          </dl>
          <p v-if="refundError" class="form-error" role="alert">{{ refundError }}</p>
          <footer class="checkout-dialog__actions">
            <UiButton variant="secondary" :disabled="refundSubmitting" @click="backToRefundConfiguration">Back</UiButton>
            <UiButton variant="secondary" :disabled="refundSubmitting" @click="cancelRefund">Cancel</UiButton>
            <UiButton :loading="refundSubmitting" loading-label="Confirming refund" @click="confirmRefund">Confirm Refund</UiButton>
          </footer>
        </div>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.receipt-page {
  min-width: 0;
}

.receipt-refresh-error,
.pos-error {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--fm-space-4);
  margin-bottom: var(--fm-space-5);
}

.receipt-refresh-error > div {
  display: grid;
  gap: var(--fm-space-1);
}

.receipt-refresh-error .ui-button,
.pos-error .ui-button {
  flex: 0 0 auto;
}

.receipt-actions {
  display: flex;
  gap: var(--fm-space-3);
  flex-wrap: wrap;
}

.receipt {
  width: min(30rem, 100%);
  margin-inline: auto;
  overflow: hidden;
  border: var(--fm-border-width) solid var(--fm-color-border);
  border-radius: var(--fm-radius-panel);
  background: var(--fm-color-surface);
  box-shadow: var(--fm-shadow-card);
}

.receipt__header,
.receipt__metadata,
.receipt__items,
.receipt__totals,
.receipt__footer {
  padding: var(--fm-space-5);
}

.receipt__header,
.receipt__footer {
  text-align: center;
}

.receipt__header {
  border-bottom: var(--fm-border-width) dashed var(--fm-color-slate-300);
}

.receipt__header h2,
.receipt__header p,
.receipt__footer p {
  margin: 0;
}

.receipt__header h2 {
  margin-block: var(--fm-space-2);
  font-size: var(--fm-font-size-2xl);
}

.receipt__header p,
.receipt__footer {
  color: var(--fm-color-text-secondary);
}

.receipt__status {
  color: var(--fm-color-success-700) !important;
  font-size: var(--fm-font-size-xs);
  font-weight: var(--fm-font-weight-bold);
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.receipt__metadata,
.receipt__totals {
  display: grid;
  gap: var(--fm-space-2);
  margin: 0;
}

.receipt__metadata {
  border-bottom: var(--fm-border-width) dashed var(--fm-color-slate-300);
  font-size: var(--fm-font-size-sm);
}

.receipt__metadata > div,
.receipt__totals > div,
.receipt__item {
  display: flex;
  justify-content: space-between;
  gap: var(--fm-space-4);
}

.receipt__metadata dd,
.receipt__totals dd {
  margin: 0;
  font-weight: var(--fm-font-weight-semibold);
  text-align: right;
  overflow-wrap: anywhere;
}

.receipt__items {
  display: grid;
  gap: var(--fm-space-3);
  border-bottom: var(--fm-border-width) dashed var(--fm-color-slate-300);
}

.receipt__item {
  align-items: start;
}

.receipt__item > div {
  min-width: 0;
  display: grid;
  gap: var(--fm-space-1);
}

.receipt__item small {
  color: var(--fm-color-text-muted);
  overflow-wrap: anywhere;
}

.receipt__item-actions {
  flex: 0 0 auto;
  display: grid;
  justify-items: end;
  gap: var(--fm-space-2);
}

.receipt__refund {
  display: grid;
  justify-items: end;
  gap: var(--fm-space-1);
}

.receipt__refund small {
  color: var(--fm-color-text-muted);
  font-size: var(--fm-font-size-xs);
}

.receipt__refund-success {
  display: grid;
  gap: var(--fm-space-1);
  margin: var(--fm-space-5);
  padding: var(--fm-space-3);
  border: var(--fm-border-width) solid var(--fm-color-success-600);
  border-radius: var(--fm-radius-control);
  background: var(--fm-color-success-50);
  color: var(--fm-color-success-700);
}

.receipt__grand-total {
  padding-top: var(--fm-space-3);
  border-top: var(--fm-border-width) solid var(--fm-color-border);
  font-size: var(--fm-font-size-lg);
  font-weight: var(--fm-font-weight-bold);
}

.receipt__footer {
  border-top: var(--fm-border-width) dashed var(--fm-color-slate-300);
}

.receipt__footer small {
  display: block;
  margin-top: var(--fm-space-2);
  overflow-wrap: anywhere;
}

.pos-workspace {
  display: grid;
  grid-template-columns: 1fr 22rem;
  align-items: start;
  gap: var(--fm-space-6);
}

.pos-catalog {
  display: grid;
  gap: var(--fm-space-4);
  min-width: 0;
}

.pos-catalog__toolbar {
  display: grid;
  gap: var(--fm-space-3);
}

.pos-catalog__search {
  max-width: 24rem;
}

.pos-categories {
  display: flex;
  flex-wrap: wrap;
  gap: var(--fm-space-2);
}

.pos-category-pill {
  min-height: var(--fm-control-height-sm);
  padding: 0 var(--fm-space-4);
  border: var(--fm-border-width) solid var(--fm-color-border);
  border-radius: var(--fm-radius-pill);
  background: var(--fm-color-surface);
  color: var(--fm-color-text-secondary);
  font-size: var(--fm-font-size-sm);
  font-weight: var(--fm-font-weight-semibold);
  cursor: pointer;
  transition: background-color var(--fm-transition-fast), color var(--fm-transition-fast), border-color var(--fm-transition-fast);
}

.pos-category-pill:hover {
  background: var(--fm-color-slate-100);
}

.pos-category-pill--active {
  border-color: var(--fm-color-primary-700);
  background: var(--fm-color-primary-700);
  color: var(--fm-color-white);
}

.pos-category-pill:focus-visible {
  outline: none;
  box-shadow: var(--fm-focus-ring);
}

.pos-product-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr));
  gap: var(--fm-space-4);
}

.pos-product-card {
  position: relative;
  display: grid;
  gap: var(--fm-space-2);
  min-height: 9.5rem;
  padding: var(--fm-space-4);
  border: var(--fm-border-width) solid var(--fm-color-border);
  border-radius: var(--fm-radius-card);
  background: var(--fm-color-surface);
  box-shadow: var(--fm-shadow-card);
  text-align: left;
  cursor: pointer;
  transition: transform var(--fm-transition-fast), border-color var(--fm-transition-fast), box-shadow var(--fm-transition-fast);
}

.pos-product-card:hover:not(:disabled) {
  border-color: var(--fm-color-primary-500);
  transform: translateY(-2px);
  box-shadow: var(--fm-shadow-menu);
}

.pos-product-card:focus-visible {
  outline: none;
  box-shadow: var(--fm-focus-ring);
}

.pos-product-card:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.pos-product-card__media {
  display: grid;
  place-items: center;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: var(--fm-radius-control);
  background: var(--fm-color-primary-50);
  font-size: var(--fm-font-size-xl);
}

.pos-product-card__name {
  color: var(--fm-color-text);
  font-weight: var(--fm-font-weight-semibold);
  line-height: var(--fm-line-height-tight);
}

.pos-product-card__price {
  color: var(--fm-color-primary-700);
  font-weight: var(--fm-font-weight-bold);
}

.pos-product-card__stock {
  justify-self: start;
}

.pos-product-card__in-cart {
  position: absolute;
  top: var(--fm-space-3);
  right: var(--fm-space-3);
  padding: var(--fm-space-1) var(--fm-space-2);
  border-radius: var(--fm-radius-pill);
  background: var(--fm-color-primary-700);
  color: var(--fm-color-white);
  font-size: var(--fm-font-size-xs);
  font-weight: var(--fm-font-weight-bold);
}

.pos-cart {
  position: sticky;
  top: var(--fm-space-6);
  display: grid;
  gap: var(--fm-space-4);
  padding: var(--fm-space-5);
  border: var(--fm-border-width) solid var(--fm-color-border);
  border-radius: var(--fm-radius-panel);
  background: var(--fm-color-surface);
  box-shadow: var(--fm-shadow-card);
}

.pos-cart__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--fm-space-3);
}

.pos-cart__header h2 {
  margin: 0;
  font-size: var(--fm-font-size-lg);
}

.pos-cart__lines {
  display: grid;
  gap: var(--fm-space-1);
  max-height: 20rem;
  overflow-y: auto;
}

.pos-cart-line {
  display: grid;
  grid-template-columns: 1fr auto auto;
  align-items: center;
  gap: var(--fm-space-3);
  padding-block: var(--fm-space-3);
  border-bottom: var(--fm-border-width) solid var(--fm-color-border);
}

.pos-cart-line__info {
  display: grid;
  gap: var(--fm-space-1);
  min-width: 0;
}

.pos-cart-line__info small {
  color: var(--fm-color-text-muted);
}

.pos-cart-line__controls {
  display: flex;
  align-items: center;
  gap: var(--fm-space-2);
}

.pos-qty-button {
  display: grid;
  place-items: center;
  width: 1.75rem;
  height: 1.75rem;
  border: var(--fm-border-width) solid var(--fm-color-border);
  border-radius: var(--fm-radius-control);
  background: var(--fm-color-surface);
  color: var(--fm-color-text);
  font-weight: var(--fm-font-weight-bold);
  cursor: pointer;
}

.pos-qty-button:hover:not(:disabled) {
  background: var(--fm-color-slate-100);
}

.pos-qty-button:disabled {
  cursor: not-allowed;
  opacity: 0.45;
}

.pos-qty-value {
  min-width: 1.25rem;
  text-align: center;
  font-variant-numeric: tabular-nums;
}

.pos-cart-line__total {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.pos-cart__summary {
  display: grid;
  gap: var(--fm-space-2);
  padding-top: var(--fm-space-3);
  border-top: var(--fm-border-width) solid var(--fm-color-border);
}

.pos-cart__summary-row {
  display: flex;
  justify-content: space-between;
  gap: var(--fm-space-3);
  color: var(--fm-color-text-secondary);
}

.pos-cart__summary-row--total {
  padding-top: var(--fm-space-2);
  border-top: var(--fm-border-width) solid var(--fm-color-border);
  color: var(--fm-color-text);
  font-size: var(--fm-font-size-lg);
  font-weight: var(--fm-font-weight-bold);
}

.pos-payment {
  margin: 0;
  padding: 0;
  border: 0;
}

.pos-payment legend {
  margin-bottom: var(--fm-space-2);
  padding: 0;
  color: var(--fm-color-text);
  font-size: var(--fm-font-size-sm);
  font-weight: var(--fm-font-weight-semibold);
}

.pos-payment__options {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: var(--fm-space-2);
}

.pos-payment-option {
  min-height: var(--fm-control-height-md);
  border: var(--fm-border-width) solid var(--fm-color-border);
  border-radius: var(--fm-radius-control);
  background: var(--fm-color-surface);
  color: var(--fm-color-text);
  font-weight: var(--fm-font-weight-semibold);
  cursor: pointer;
}

.pos-payment-option:hover {
  background: var(--fm-color-slate-100);
}

.pos-payment-option--active {
  border-color: var(--fm-color-primary-700);
  background: var(--fm-color-primary-700);
  color: var(--fm-color-white);
}

.pos-payment-option:focus-visible {
  outline: none;
  box-shadow: var(--fm-focus-ring);
}

.pos-checkout-button {
  width: 100%;
}

@media (max-width: 64rem) {
  .pos-workspace {
    grid-template-columns: 1fr;
  }

  .pos-cart {
    position: static;
  }
}

@media (max-width: 30rem) {
  .pos-payment__options {
    grid-template-columns: 1fr 1fr;
  }
}

.checkout-dialog-backdrop {
  position: fixed;
  inset: 0;
  z-index: calc(var(--fm-z-dropdown) + 10);
  display: grid;
  place-items: center;
  padding: var(--fm-space-4);
  overflow-y: auto;
  background: var(--fm-color-overlay);
}

.checkout-dialog {
  width: min(34rem, 100%);
  max-height: calc(100dvh - (2 * var(--fm-space-4)));
  overflow-y: auto;
  border: var(--fm-border-width) solid var(--fm-color-border);
  border-radius: var(--fm-radius-panel);
  background: var(--fm-color-surface);
  box-shadow: var(--fm-shadow-menu);
}

.checkout-dialog__header,
.checkout-dialog__body,
.checkout-dialog__actions {
  padding: var(--fm-space-5);
}

.checkout-dialog__header {
  border-bottom: var(--fm-border-width) solid var(--fm-color-border);
}

.checkout-dialog__header h2,
.checkout-dialog__eyebrow,
.checkout-dialog__body > p {
  margin: 0;
}

.checkout-dialog__eyebrow {
  color: var(--fm-color-primary-700);
  font-size: var(--fm-font-size-xs);
  font-weight: var(--fm-font-weight-bold);
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.checkout-dialog__header h2 {
  margin-top: var(--fm-space-1);
  font-size: var(--fm-font-size-xl);
}

.checkout-dialog__body {
  display: grid;
  gap: var(--fm-space-4);
}

.refund-form label {
  display: grid;
  gap: var(--fm-space-2);
  color: var(--fm-color-text-primary);
  font-weight: var(--fm-font-weight-semibold);
}

.refund-form input,
.refund-form textarea {
  width: 100%;
  border: var(--fm-border-width) solid var(--fm-color-border);
  border-radius: var(--fm-radius-control);
  padding: var(--fm-space-3);
  color: var(--fm-color-text-primary);
  background: var(--fm-color-surface);
  font: inherit;
}

.refund-details {
  display: grid;
  gap: var(--fm-space-2);
  margin: 0;
}

.refund-details > div {
  display: flex;
  justify-content: space-between;
  gap: var(--fm-space-4);
}

.refund-details dd {
  margin: 0;
  font-weight: var(--fm-font-weight-semibold);
  text-align: right;
  overflow-wrap: anywhere;
}

.checkout-dialog__items {
  display: grid;
  gap: var(--fm-space-2);
  max-height: 12rem;
  margin: 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
}

.checkout-dialog__items li,
.checkout-dialog__totals > div {
  display: flex;
  justify-content: space-between;
  gap: var(--fm-space-4);
}

.checkout-dialog__items li {
  padding-block: var(--fm-space-2);
  border-bottom: var(--fm-border-width) solid var(--fm-color-border);
  color: var(--fm-color-text-secondary);
}

.checkout-dialog__totals {
  display: grid;
  gap: var(--fm-space-2);
  margin: 0;
}

.checkout-dialog__totals dd {
  margin: 0;
  font-weight: var(--fm-font-weight-semibold);
  text-align: right;
}

.checkout-dialog__total {
  padding-top: var(--fm-space-3);
  border-top: var(--fm-border-width) solid var(--fm-color-border);
  font-size: var(--fm-font-size-lg);
  font-weight: var(--fm-font-weight-bold);
}

.checkout-dialog__actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--fm-space-3);
  border-top: var(--fm-border-width) solid var(--fm-color-border);
}

@media (max-width: 30rem) {
  .receipt-refresh-error,
  .pos-error {
    align-items: stretch;
    flex-direction: column;
  }

  .receipt-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    width: 100%;
  }

  .receipt__header,
  .receipt__metadata,
  .receipt__items,
  .receipt__totals,
  .receipt__footer {
    padding: var(--fm-space-4);
  }

  .checkout-dialog-backdrop {
    align-items: end;
    padding: var(--fm-space-2);
  }

  .checkout-dialog {
    max-height: calc(100dvh - (2 * var(--fm-space-2)));
  }

  .checkout-dialog__header,
  .checkout-dialog__body,
  .checkout-dialog__actions {
    padding: var(--fm-space-4);
  }

  .checkout-dialog__actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
  }
}

@media print {
  @page {
    size: auto;
    margin: 4mm;
  }

  :global(html),
  :global(body) {
    width: auto;
    min-width: 0;
    background: #fff;
  }

  .receipt-screen-header,
  .receipt-refresh-error {
    display: none !important;
  }

  .receipt-page {
    width: 100%;
    color: #000;
    background: #fff;
  }

  .receipt {
    width: 72mm;
    max-width: 100%;
    margin: 0 auto;
    border: 0;
    border-radius: 0;
    color: #000;
    background: #fff;
    box-shadow: none;
    font-size: 9pt;
  }

  .receipt *,
  .receipt__status {
    color: #000 !important;
    background: transparent !important;
    box-shadow: none !important;
  }

  .receipt__header,
  .receipt__metadata,
  .receipt__items,
  .receipt__totals,
  .receipt__footer {
    padding: 3mm 0;
    border-color: #000;
    break-inside: avoid;
  }

  .receipt__header h2 {
    font-size: 14pt;
  }

  .receipt__item,
  .receipt__metadata > div,
  .receipt__totals > div {
    gap: 3mm;
  }

  .receipt__refund,
  .receipt__refund-success {
    display: none !important;
  }
}
</style>
