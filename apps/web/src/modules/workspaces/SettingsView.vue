<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import PageHeader from '../../components/common/PageHeader.vue'

const groups = ref({})
const settings = ref({})
const error = ref('')
const message = ref('')

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
  try {
    error.value = ''
    message.value = ''
    const data = await api.put('/settings', { settings: settings.value })
    groups.value = data.groups
    message.value = 'System settings saved.'
  } catch (requestError) {
    error.value = requestError.message
  }
}

onMounted(load)
</script>

<template>
  <PageHeader title="System Settings" description="Manage approved business and application settings." />
  <p v-if="error" class="form-error">{{ error }}</p>
  <p v-if="message" class="success-message">{{ message }}</p>

  <form class="settings-form" @submit.prevent="save">
    <fieldset v-for="(fields, group) in groups" :key="group">
      <legend>{{ group }}</legend>
      <label v-for="field in fields" :key="field.key" :class="{ 'check-field': field.type === 'boolean' }">
        <template v-if="field.type === 'boolean'">
          <input v-model="settings[field.key]" type="checkbox">
          <span>{{ field.label }}</span>
        </template>
        <template v-else>
          {{ field.label }}
          <textarea
            v-if="field.type === 'textarea'"
            v-model="settings[field.key]"
            rows="3"
          />
          <select v-else-if="field.type === 'select'" v-model="settings[field.key]">
            <option v-for="option in field.options" :key="option" :value="option">{{ option }}</option>
          </select>
          <input
            v-else
            v-model="settings[field.key]"
            :type="field.type"
            :step="field.type === 'number' ? '.01' : undefined"
          >
        </template>
      </label>
    </fieldset>
    <button class="primary-button">Save settings</button>
  </form>
</template>
