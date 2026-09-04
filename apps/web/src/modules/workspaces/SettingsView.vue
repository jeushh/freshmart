<script setup>
import { onMounted, ref, watch } from 'vue'
import { api } from '../../api/http.js'
import {
  UiButton,
  UiInput,
  UiPageHeader,
  UiSectionCard,
  UiSelect,
  UiToggle
} from '../../components/ui/index.js'
import { sessionStore } from '../../stores/session.js'

const groups = ref({})
const settings = ref({})
const error = ref('')
const message = ref('')
const saving = ref(false)

async function load() {
  try {
    error.value = ''
    const data = await api.get('/settings')
    groups.value = data.groups
    settings.value = {}
    Object.values(data.groups).flat().forEach(field => {
      settings.value[field.key] = field.value
    })
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function save() {
  saving.value = true
  error.value = ''
  message.value = ''
  try {
    const data = await api.put('/settings', { settings: settings.value })
    groups.value = data.groups
    sessionStore.updateSettings(settings.value)
    message.value = 'System settings saved.'
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    saving.value = false
  }
}

onMounted(load)
watch(() => settings.value.currency_code, code => {
  if (!code) return
  settings.value.currency_symbol = code === 'PHP' ? '₱' : '$'
  settings.value.currency_locale = code === 'PHP' ? 'en-PH' : 'en-US'
})
</script>

<template>
  <UiPageHeader title="System Settings" description="Manage approved business and application settings." />
  <p v-if="error" class="form-error">{{ error }}</p>
  <p v-if="message" class="success-message">{{ message }}</p>

  <div class="settings-policy">
    <strong>Configuration policy</strong>
    <p>Currency and tax changes apply to new transactions. Historical sales retain the tax snapshots recorded when they were finalized.</p>
    <p>Database backups and restores are intentionally available only through the protected server command line.</p>
  </div>

  <form class="settings-form" @submit.prevent="save">
    <UiSectionCard v-for="(fields, group) in groups" :key="group" :title="group">
      <div class="settings-fields">
        <template v-for="field in fields" :key="field.key">
          <div v-if="field.type === 'boolean'" class="ui-field">
            <span class="ui-field__label settings-fields__hidden-label" aria-hidden="true">&nbsp;</span>
            <UiToggle
              v-model="settings[field.key]"
              :label="field.label"
            />
          </div>
          <UiSelect
            v-else-if="field.type === 'select'"
            v-model="settings[field.key]"
            :label="field.label"
          >
            <option v-for="option in field.options" :key="option" :value="option">{{ option }}</option>
          </UiSelect>
          <label v-else-if="field.type === 'textarea'" class="ui-field settings-fields__span">
            <span class="ui-field__label">{{ field.label }}</span>
            <textarea v-model="settings[field.key]" class="ui-field-control" rows="3"></textarea>
          </label>
          <UiInput
            v-else
            v-model="settings[field.key]"
            :label="field.label"
            :type="field.type"
            :step="field.type === 'number' ? '.01' : undefined"
          />
        </template>
      </div>
    </UiSectionCard>
    <UiButton type="submit" :loading="saving" loading-label="Saving settings">Save settings</UiButton>
  </form>
</template>

<style scoped>
.settings-policy {
  border: var(--fm-border-width) solid var(--fm-color-info-200);
  background: var(--fm-color-info-50);
  color: var(--fm-color-info-700);
  border-radius: var(--fm-radius-card);
  padding: var(--fm-space-4) var(--fm-space-5);
  margin-bottom: var(--fm-space-4);
}
.settings-policy strong {
  display: block;
  margin-bottom: var(--fm-space-2);
}
.settings-policy p {
  margin: 0;
}
.settings-policy p + p {
  margin-top: var(--fm-space-2);
}
.settings-form {
  display: flex;
  flex-direction: column;
  gap: var(--fm-space-4);
}
.settings-fields {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr));
  gap: var(--fm-space-5);
}
.settings-fields__span {
  grid-column: 1 / -1;
}
.settings-fields__hidden-label {
  visibility: hidden;
}
</style>
