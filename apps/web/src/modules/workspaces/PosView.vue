<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import PageHeader from '../../components/common/PageHeader.vue'
import { sessionStore } from '../../stores/session.js'
import { formatMoney } from '../../utils/formatters.js'

const products = ref([])
const cart = ref([])
const payment = ref('Cash')
const message = ref('')
const error = ref('')
const baseTotal = computed(() => cart.value.reduce((sum, item) => sum + item.price * item.quantity, 0))
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

async function checkout() {
  try {
    error.value = ''
    message.value = ''
    const result = await api.post('/workspace/pos/checkout', {
      items: cart.value.map(item => ({ product_id: item.id, quantity: item.quantity })),
      payment_method: payment.value
    })
    message.value = `Order ${result.order_id} completed — ${formatMoney(result.total)}`
    cart.value = []
    await load()
  } catch (requestError) {
    error.value = requestError.message
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
        <strong>{{ formatMoney(item.price * item.quantity) }}</strong>
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
      <button class="primary-button" :disabled="!cart.length" @click="checkout">Complete sale</button>
    </aside>
  </div>
</template>
