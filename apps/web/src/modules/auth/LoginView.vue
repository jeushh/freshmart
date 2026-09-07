<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { sessionStore } from '../../stores/session.js'
import '@fontsource/fraunces/500.css'
import '@fontsource/fraunces/600.css'

const username = ref('')
const password = ref('')
const error = ref('')
const busy = ref(false)
const router = useRouter()

async function submit() {
  error.value = ''
  busy.value = true
  try {
    await sessionStore.login(username.value, password.value)
    router.replace(sessionStore.homePath())
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="fm-login">
    <div class="fm-login__ambient" aria-hidden="true">
      <svg class="fm-login__leaf fm-login__leaf--one" viewBox="0 0 200 200">
        <path d="M100,190 C40,160 20,90 60,20 C120,50 140,120 100,190 Z" />
      </svg>
      <svg class="fm-login__leaf fm-login__leaf--two" viewBox="0 0 200 200">
        <path d="M100,190 C40,160 20,90 60,20 C120,50 140,120 100,190 Z" />
      </svg>
      <div class="fm-login__glow"></div>
    </div>

    <section class="fm-login__card">
      <div class="fm-login__letterhead">
        <svg class="fm-login__mark" viewBox="0 0 120 120" role="img" aria-label="FreshMart">
          <defs>
            <linearGradient id="ring" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stop-color="#8FB573" />
              <stop offset="1" stop-color="#2F5233" />
            </linearGradient>
            <linearGradient id="leafLeft" x1="0" y1="1" x2="0" y2="0">
              <stop offset="0" stop-color="#3E6B34" />
              <stop offset="1" stop-color="#7DA75E" />
            </linearGradient>
            <linearGradient id="leafRight" x1="0" y1="1" x2="0" y2="0">
              <stop offset="0" stop-color="#2F5233" />
              <stop offset="1" stop-color="#5C8A47" />
            </linearGradient>
          </defs>
          <circle cx="60" cy="60" r="55" fill="none" stroke="url(#ring)" stroke-width="2.5" />
          <g transform="translate(60,68)">
            <path d="M0,0 C-26,-9 -28,-42 -3,-55 C20,-43 21,-9 0,0 Z" fill="url(#leafLeft)" transform="rotate(-16)" />
            <path d="M0,0 C26,-9 28,-42 3,-55 C-20,-43 -21,-9 0,0 Z" fill="url(#leafRight)" transform="rotate(16)" />
            <path d="M0,-3 C-1,-20 -1,-38 0,-53" stroke="#213B1E" stroke-width="1.4" fill="none" opacity="0.55" stroke-linecap="round" />
          </g>
        </svg>
        <p class="fm-login__wordmark">freshmart</p>
        <p class="fm-login__caption">Business System</p>
      </div>

      <div class="fm-login__divider"></div>

      <div class="fm-login__intro">
        <h1>Welcome back</h1>
        <p>Sign in to continue to your workspace.</p>
      </div>

      <form class="fm-login__form" @submit.prevent="submit">
        <label class="fm-login__field">
          <span>Username</span>
          <input v-model.trim="username" autocomplete="username" required />
        </label>
        <label class="fm-login__field">
          <span>Password</span>
          <input v-model="password" type="password" autocomplete="current-password" required />
        </label>

        <p v-if="error" class="fm-login__error" role="alert">{{ error }}</p>

        <button class="fm-login__submit" :disabled="busy">
          {{ busy ? 'Signing in…' : 'Sign in' }}
        </button>
      </form>
    </section>
  </main>
</template>

<style scoped>
.fm-login {
  --fl-forest-950: #14261a;
  --fl-forest-700: #23492f;
  --fl-sage-500: #6c9a5c;
  --fl-sage-300: #a7c793;
  --fl-gold-400: #c7a250;
  --fl-parchment-50: #f8f5ec;
  --fl-ink-900: #1e2a20;
  --fl-ink-600: #4d5b4f;

  position: relative;
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 24px;
  overflow: hidden;
  background: radial-gradient(120% 100% at 15% 0%, var(--fl-forest-700) 0%, var(--fl-forest-950) 62%);
  color: var(--fl-ink-900);
}

.fm-login__ambient {
  position: absolute;
  inset: 0;
  z-index: 0;
  pointer-events: none;
}

.fm-login__glow {
  position: absolute;
  top: -20%;
  left: 8%;
  width: 60vw;
  height: 60vw;
  max-width: 720px;
  max-height: 720px;
  background: radial-gradient(circle, rgba(199, 162, 80, 0.16) 0%, rgba(199, 162, 80, 0) 65%);
  filter: blur(2px);
}

.fm-login__leaf {
  position: absolute;
  fill: var(--fl-sage-500);
}

.fm-login__leaf--one {
  width: min(46vw, 520px);
  height: min(46vw, 520px);
  top: -8%;
  right: -10%;
  opacity: 0.1;
  transform: rotate(24deg);
}

.fm-login__leaf--two {
  width: min(34vw, 380px);
  height: min(34vw, 380px);
  bottom: -12%;
  left: -8%;
  opacity: 0.08;
  transform: rotate(-38deg) scaleX(-1);
  fill: var(--fl-gold-400);
}

.fm-login__card {
  position: relative;
  z-index: 1;
  width: min(408px, 100%);
  background: var(--fl-parchment-50);
  border-radius: 20px;
  padding: 40px 36px 36px;
  box-shadow: 0 30px 60px -20px rgba(15, 30, 18, 0.55);
}

.fm-login__letterhead {
  display: grid;
  justify-items: center;
  text-align: center;
  gap: 4px;
}

.fm-login__mark {
  width: 56px;
  height: 56px;
  margin-bottom: 6px;
}

.fm-login__wordmark {
  margin: 0;
  font-family: 'Fraunces', ui-serif, Georgia, serif;
  font-size: 1.5rem;
  font-weight: 600;
  letter-spacing: -0.01em;
  color: var(--fl-ink-900);
}

.fm-login__caption {
  margin: 0;
  font-size: 0.8rem;
  color: var(--fl-ink-600);
}

.fm-login__divider {
  height: 1px;
  margin: 22px 0;
  background: linear-gradient(90deg, transparent, rgba(30, 42, 32, 0.16) 50%, transparent);
}

.fm-login__intro {
  margin-bottom: 22px;
}

.fm-login__intro h1 {
  margin: 0 0 6px;
  font-family: 'Fraunces', ui-serif, Georgia, serif;
  font-size: 1.375rem;
  font-weight: 600;
  color: var(--fl-ink-900);
}

.fm-login__intro p {
  margin: 0;
  color: var(--fl-ink-600);
  font-size: 0.9rem;
}

.fm-login__form {
  display: grid;
  gap: 16px;
}

.fm-login__field {
  display: grid;
  gap: 6px;
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--fl-ink-900);
}

.fm-login__field input {
  font: inherit;
  font-weight: 400;
  padding: 11px 13px;
  border-radius: 10px;
  border: 1px solid rgba(30, 42, 32, 0.18);
  background: #fff;
  color: var(--fl-ink-900);
}

.fm-login__field input:focus-visible {
  outline: none;
  border-color: var(--fl-sage-500);
  box-shadow: 0 0 0 3px rgba(108, 154, 92, 0.25);
}

.fm-login__error {
  margin: 0;
  padding: 10px 12px;
  border-radius: 10px;
  background: #fdeeee;
  color: #9c2424;
  font-size: 0.85rem;
}

.fm-login__submit {
  margin-top: 4px;
  padding: 12px 16px;
  border: 0;
  border-radius: 10px;
  background: var(--fl-forest-950);
  color: var(--fl-parchment-50);
  font-size: 0.95rem;
  font-weight: 600;
  cursor: pointer;
  transition: background-color 0.15s ease, transform 0.15s ease;
}

.fm-login__submit:hover:not(:disabled) {
  background: var(--fl-forest-700);
}

.fm-login__submit:active:not(:disabled) {
  transform: translateY(1px);
}

.fm-login__submit:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

@media (max-width: 420px) {
  .fm-login__card {
    padding: 32px 24px 28px;
  }
}
</style>
