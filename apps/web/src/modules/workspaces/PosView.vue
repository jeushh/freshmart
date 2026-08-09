<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import PageHeader from '../../components/common/PageHeader.vue'
import { UiButton } from '../../components/ui/index.js'
import { sessionStore } from '../../stores/session.js'
import { formatMoney } from '../../utils/formatters.js'

const products = ref([])
const cart = ref([])
const payment = ref('Cash')
const message = ref('')
const error = ref('')
const confirming = ref(false)
const submitting = ref(false)
const checkoutButton = ref(null)
const cancelButton = ref(null)
const dialogPanel = ref(null)
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

async function load() {
  try {
    error.value = ''
    const data = await api.get('/workspace/pos')
    products.value = data.products
    sessionStore.updateSettings(data.settings)
  } catch (requestError) {
    error.value = requestError.message
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
  if (!cart.value.length || submitting.value) return
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
  <PageHeader title="Point of Sale" description="Process sales with automatic stock, tax, and ledger updates." />
  <p v-if="error" class="form-error">{{ error }}</p>
  <p v-if="message" class="success-message">{{ message }}</p>
  <div class="pos-layout">
    <section>
      <div class="product-grid">
        <button v-for="product in products" :key="product.id" class="product-card" :disabled="product.stock_quantity < 1" @click="add(product)">
          <strong>{{ product.emoji }} {{ product.name }}</strong>
          <span>{{ formatMoney(product.price) }}</span>
          <small>{{ product.stock_quantity }} in stock</small>
        </button>
      </div>
    </section>
    <aside class="cart-card">
      <h2>Current sale</h2>
      <div v-for="item in cart" :key="item.id" class="cart-line">
        <span>{{ item.name }} × {{ item.quantity }}</span>
        <span class="cart-line__actions">
          <strong>{{ formatMoney(item.price * item.quantity) }}</strong>
          <button
            type="button"
            class="icon-button cart-line__decrement"
            :aria-label="`Remove one ${item.name}`"
            :title="`Remove one ${item.name}`"
            @click="decrement(item)"
          >
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            </svg>
          </button>
        </span>
      </div>
      <p v-if="!cart.length">Cart is empty.</p>
      <div class="sale-totals">
        <div><span>Subtotal</span><strong>{{ formatMoney(subtotal) }}</strong></div>
        <div>
          <span>Tax ({{ sessionStore.state.settings.tax_rate }}%{{ sessionStore.state.settings.tax_inclusive ? ', included' : '' }})</span>
          <strong>{{ formatMoney(tax) }}</strong>
        </div>
        <div class="cart-total"><span>Total</span><strong>{{ formatMoney(total) }}</strong></div>
      </div>
      <select v-model="payment"><option>Cash</option><option>Card</option><option>QR</option></select>
      <UiButton ref="checkoutButton" class="checkout-button" :disabled="!cart.length" @click="openConfirmation">
        Review sale
      </UiButton>
    </aside>
  </div>

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
</template>

<style scoped>
.cart-line__actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.cart-line .cart-line__decrement {
  flex: 0 0 auto;
  width: 32px;
  height: 32px;
  margin-top: 0;
  padding: 7px;
  border: 1px solid #dce4de;
}

.cart-line__decrement svg {
  width: 18px;
  height: 18px;
}

.checkout-button {
  width: 100%;
  margin-top: var(--fm-space-3);
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
</style>
