<script setup>
import { nextTick, ref } from 'vue'
import UiButton from './UiButton.vue'

const props = defineProps({
  label: { type: String, required: true },
  items: { type: Array, required: true },
  disabled: Boolean
})

const emit = defineEmits(['select', 'open', 'close'])
const open = ref(false)
const activeIndex = ref(-1)
const itemRefs = ref([])
const trigger = ref(null)

function enabledIndexes() {
  return props.items
    .map((item, index) => item.disabled ? -1 : index)
    .filter(index => index >= 0)
}

async function show(preferLast = false) {
  if (props.disabled) return
  open.value = true
  const enabled = enabledIndexes()
  activeIndex.value = preferLast ? enabled.at(-1) ?? -1 : enabled[0] ?? -1
  emit('open')
  await nextTick()
  itemRefs.value[activeIndex.value]?.focus()
}

async function close(restoreFocus = false) {
  if (!open.value) return
  open.value = false
  activeIndex.value = -1
  emit('close')
  if (restoreFocus) {
    await nextTick()
    trigger.value?.focus()
  }
}

function toggle() {
  open.value ? close() : show()
}

function move(direction) {
  const enabled = enabledIndexes()
  if (!enabled.length) return
  const current = enabled.indexOf(activeIndex.value)
  const next = current < 0
    ? 0
    : (current + direction + enabled.length) % enabled.length
  activeIndex.value = enabled[next]
  itemRefs.value[activeIndex.value]?.focus()
}

function onTriggerKeydown(event) {
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    show()
  }
  if (event.key === 'ArrowUp') {
    event.preventDefault()
    show(true)
  }
}

function onMenuKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    close(true)
    return
  }
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    move(1)
  }
  if (event.key === 'ArrowUp') {
    event.preventDefault()
    move(-1)
  }
  if (event.key === 'Home') {
    event.preventDefault()
    activeIndex.value = enabledIndexes()[0] ?? -1
    itemRefs.value[activeIndex.value]?.focus()
  }
  if (event.key === 'End') {
    event.preventDefault()
    activeIndex.value = enabledIndexes().at(-1) ?? -1
    itemRefs.value[activeIndex.value]?.focus()
  }
}

function select(item) {
  if (item.disabled) return
  emit('select', item)
  close(true)
}

function onFocusOut(event) {
  if (!event.currentTarget.contains(event.relatedTarget)) close()
}
</script>

<template>
  <div class="ui-dropdown" @focusout="onFocusOut">
    <UiButton
      ref="trigger"
      variant="ghost"
      :disabled="disabled"
      :aria-label="label"
      aria-haspopup="menu"
      :aria-expanded="open"
      @click="toggle"
      @keydown="onTriggerKeydown"
    >
      <slot name="trigger">{{ label }}</slot>
    </UiButton>
    <div
      v-if="open"
      class="ui-dropdown__menu"
      role="menu"
      :aria-label="label"
      @keydown="onMenuKeydown"
    >
      <template v-for="(item, index) in items" :key="item.key || item.label">
        <div v-if="item.separatorBefore" class="ui-dropdown__separator" role="separator"></div>
        <button
          :ref="element => itemRefs[index] = element"
          class="ui-dropdown__item"
          :class="{ 'ui-dropdown__item--destructive': item.destructive }"
          type="button"
          role="menuitem"
          :disabled="item.disabled"
          :tabindex="activeIndex === index ? 0 : -1"
          @click="select(item)"
          @focus="activeIndex = index"
        >
          <slot name="item" :item="item">{{ item.label }}</slot>
        </button>
      </template>
    </div>
  </div>
</template>
