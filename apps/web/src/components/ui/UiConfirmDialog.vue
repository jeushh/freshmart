<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import UiButton from './UiButton.vue'

const props = defineProps({
  open: Boolean,
  title: { type: String, required: true },
  description: { type: String, default: '' },
  confirmLabel: { type: String, default: 'Confirm' },
  cancelLabel: { type: String, default: 'Cancel' },
  loading: Boolean,
  loadingLabel: { type: String, default: 'Saving' },
  destructive: Boolean
})

const emit = defineEmits(['confirm', 'cancel'])
const dialog = ref(null)

function onKeydown(event) {
  if (event.key === 'Escape' && !props.loading) emit('cancel')
}

function onBackdropClick() {
  if (!props.loading) emit('cancel')
}

watch(() => props.open, async isOpen => {
  if (isOpen) {
    document.addEventListener('keydown', onKeydown)
    document.body.style.overflow = 'hidden'
    await nextTick()
    dialog.value?.focus()
  } else {
    document.removeEventListener('keydown', onKeydown)
    document.body.style.overflow = ''
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <Transition name="ui-dialog-fade">
      <div v-if="open" class="ui-dialog-overlay" @mousedown.self="onBackdropClick">
        <div
          ref="dialog"
          class="ui-dialog"
          :class="{ 'ui-dialog--destructive': destructive }"
          role="alertdialog"
          aria-modal="true"
          aria-labelledby="ui-dialog-title"
          tabindex="-1"
        >
          <button
            type="button"
            class="ui-dialog__close"
            aria-label="Close"
            :disabled="loading"
            @click="emit('cancel')"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
              <path d="M5 5l14 14M19 5L5 19" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" />
            </svg>
          </button>

          <div class="ui-dialog__icon" :class="{ 'ui-dialog__icon--destructive': destructive }" aria-hidden="true">
            <svg v-if="destructive" width="22" height="22" viewBox="0 0 24 24" fill="none">
              <path
                d="M12 9v4m0 3h.01M10.29 3.86L1.82 18a1 1 0 00.87 1.5h18.62a1 1 0 00.87-1.5L13.71 3.86a1 1 0 00-1.72 0z"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
            </svg>
            <svg v-else width="22" height="22" viewBox="0 0 24 24" fill="none">
              <path d="M5 12.5l4.5 4.5L19 7" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </div>

          <h2 id="ui-dialog-title" class="ui-dialog__title">{{ title }}</h2>
          <p v-if="description" class="ui-dialog__description">{{ description }}</p>
          <div v-if="$slots.default" class="ui-dialog__body">
            <slot></slot>
          </div>

          <div class="ui-dialog__actions">
            <UiButton variant="secondary" :disabled="loading" @click="emit('cancel')">
              {{ cancelLabel }}
            </UiButton>
            <UiButton
              :variant="destructive ? 'destructive' : 'primary'"
              :loading="loading"
              :loading-label="loadingLabel"
              @click="emit('confirm')"
            >
              {{ confirmLabel }}
            </UiButton>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.ui-dialog-overlay {
  position: fixed;
  inset: 0;
  background: var(--fm-color-overlay);
  backdrop-filter: blur(2px);
  -webkit-backdrop-filter: blur(2px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--fm-space-4);
  z-index: var(--fm-z-modal);
}
.ui-dialog {
  position: relative;
  background: var(--fm-color-surface);
  border-radius: var(--fm-radius-panel);
  box-shadow:
    0 12px 28px rgb(15 23 42 / 16%),
    0 0 0 1px rgb(15 23 42 / 4%),
    0 20px 44px rgb(4 120 87 / 12%);
  padding: var(--fm-space-6);
  max-width: 26rem;
  width: 100%;
  display: grid;
  gap: var(--fm-space-2);
}
.ui-dialog--destructive {
  box-shadow:
    0 12px 28px rgb(15 23 42 / 16%),
    0 0 0 1px rgb(15 23 42 / 4%),
    0 20px 44px rgb(185 28 28 / 12%);
}
.ui-dialog__close {
  position: absolute;
  top: var(--fm-space-3);
  right: var(--fm-space-3);
  display: grid;
  place-items: center;
  width: 2rem;
  height: 2rem;
  border-radius: var(--fm-radius-pill);
  border: none;
  background: transparent;
  color: var(--fm-color-text-muted);
  cursor: pointer;
  transition: background var(--fm-transition-fast), color var(--fm-transition-fast);
}
.ui-dialog__close:hover:not(:disabled) {
  background: var(--fm-color-slate-100);
  color: var(--fm-color-text);
}
.ui-dialog__close:disabled {
  opacity: 0.5;
  cursor: default;
}
.ui-dialog__icon {
  display: grid;
  place-items: center;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: var(--fm-radius-pill);
  background: var(--fm-color-success-50);
  color: var(--fm-color-success-700);
  box-shadow: 0 0 0 6px rgb(4 120 87 / 8%);
  margin-bottom: var(--fm-space-1);
}
.ui-dialog__icon--destructive {
  background: var(--fm-color-danger-50);
  color: var(--fm-color-danger-700);
  box-shadow: 0 0 0 6px rgb(185 28 28 / 8%);
}
.ui-dialog__title {
  margin: 0;
  font-size: var(--fm-font-size-xl);
  font-weight: var(--fm-font-weight-semibold);
  color: var(--fm-color-text);
}
.ui-dialog__description {
  margin: 0;
  color: var(--fm-color-text-secondary);
}
.ui-dialog__body {
  margin-top: var(--fm-space-1);
  display: grid;
  gap: var(--fm-space-3);
}
.ui-dialog__actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--fm-space-2);
  margin-top: var(--fm-space-4);
  padding-top: var(--fm-space-4);
  border-top: var(--fm-border-width) solid var(--fm-color-border);
}
.ui-dialog-fade-enter-active,
.ui-dialog-fade-leave-active {
  transition: opacity var(--fm-transition-base);
}
.ui-dialog-fade-enter-active .ui-dialog {
  transition: transform 240ms cubic-bezier(0.34, 1.56, 0.64, 1), opacity var(--fm-transition-base);
}
.ui-dialog-fade-leave-active .ui-dialog {
  transition: transform var(--fm-transition-base), opacity var(--fm-transition-base);
}
.ui-dialog-fade-enter-from,
.ui-dialog-fade-leave-to {
  opacity: 0;
}
.ui-dialog-fade-enter-from .ui-dialog,
.ui-dialog-fade-leave-to .ui-dialog {
  transform: scale(0.94) translateY(6px);
  opacity: 0;
}
@media (prefers-reduced-motion: reduce) {
  .ui-dialog-fade-enter-active .ui-dialog,
  .ui-dialog-fade-leave-active .ui-dialog {
    transition: opacity var(--fm-transition-base);
  }
  .ui-dialog-fade-enter-from .ui-dialog,
  .ui-dialog-fade-leave-to .ui-dialog {
    transform: none;
  }
}
</style>
