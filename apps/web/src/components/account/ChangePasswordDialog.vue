<script setup>
import { reactive, ref, watch } from 'vue'
import { api } from '../../api/http.js'
import UiConfirmDialog from '../ui/UiConfirmDialog.vue'
import UiInput from '../ui/UiInput.vue'

const props = defineProps({
  open: Boolean
})
const emit = defineEmits(['close'])

const form = reactive({ current_password: '', password: '', password_confirmation: '' })
const saving = ref(false)
const formError = ref('')
const formErrors = ref({})
const successMessage = ref('')

function fieldError(field) {
  return formErrors.value[field]?.[0] || ''
}

function resetState() {
  form.current_password = ''
  form.password = ''
  form.password_confirmation = ''
  formError.value = ''
  formErrors.value = {}
  successMessage.value = ''
}

watch(() => props.open, isOpen => {
  if (isOpen) resetState()
})

async function submit() {
  formError.value = ''
  formErrors.value = {}
  successMessage.value = ''

  if (!form.current_password || !form.password || !form.password_confirmation) {
    formError.value = 'Fill in every field to continue.'
    return
  }
  if (form.password !== form.password_confirmation) {
    formErrors.value = { password_confirmation: ['New password and confirmation do not match.'] }
    return
  }

  saving.value = true
  try {
    await api.post('/me/password', { ...form })
    successMessage.value = 'Your password has been updated.'
    form.current_password = ''
    form.password = ''
    form.password_confirmation = ''
    setTimeout(() => emit('close'), 900)
  } catch (requestError) {
    formErrors.value = requestError.errors || {}
    if (!Object.keys(formErrors.value).length) formError.value = requestError.message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UiConfirmDialog
    :open="open"
    title="Change password"
    description="Confirm your current password, then choose a new one."
    confirm-label="Update password"
    loading-label="Updating"
    :loading="saving"
    @confirm="submit"
    @cancel="emit('close')"
  >
    <UiInput
      v-model="form.current_password"
      label="Current password"
      type="password"
      autocomplete="current-password"
      :error="fieldError('current_password')"
      :disabled="saving"
      required
    />
    <UiInput
      v-model="form.password"
      label="New password"
      type="password"
      autocomplete="new-password"
      minlength="8"
      hint="Use at least 8 characters."
      :error="fieldError('password')"
      :disabled="saving"
      required
    />
    <UiInput
      v-model="form.password_confirmation"
      label="Confirm new password"
      type="password"
      autocomplete="new-password"
      :error="fieldError('password_confirmation')"
      :disabled="saving"
      required
    />
    <p v-if="formError" class="fm-password-dialog__message fm-password-dialog__message--error" role="alert">
      {{ formError }}
    </p>
    <p v-else-if="successMessage" class="fm-password-dialog__message fm-password-dialog__message--success">
      {{ successMessage }}
    </p>
  </UiConfirmDialog>
</template>

<style scoped>
.fm-password-dialog__message {
  margin: 0;
  font-size: var(--fm-font-size-sm);
}
.fm-password-dialog__message--error {
  color: var(--fm-color-danger-700);
}
.fm-password-dialog__message--success {
  color: var(--fm-color-success-700);
}
</style>
