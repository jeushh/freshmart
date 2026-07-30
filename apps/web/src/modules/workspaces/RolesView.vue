<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import PageHeader from '../../components/common/PageHeader.vue'
import WorkspaceTable from '../../components/common/WorkspaceTable.vue'

const roles = ref([])
const permissionGroups = ref({})
const landingPages = ref([])
const error = ref('')
const message = ref('')
const blank = () => ({
  id: null,
  name: '',
  description: '',
  landing_page: 'dashboard',
  permissions: []
})
const form = ref(blank())

async function load() {
  try {
    error.value = ''
    const data = await api.get('/roles', { per_page: 100 })
    roles.value = data.roles.data
    permissionGroups.value = data.permission_groups
    landingPages.value = data.landing_pages
  } catch (requestError) {
    error.value = requestError.message
  }
}

function edit(role) {
  message.value = ''
  form.value = {
    id: role.id,
    name: role.name,
    description: role.description || '',
    landing_page: role.landing_page,
    permissions: [...role.permissions]
  }
}

function reset() {
  form.value = blank()
}

async function save() {
  try {
    error.value = ''
    message.value = ''
    if (form.value.id) {
      await api.put(`/roles/${form.value.id}`, form.value)
    } else {
      await api.post('/roles', form.value)
    }
    message.value = 'Role permissions saved.'
    reset()
    await load()
  } catch (requestError) {
    error.value = requestError.message
  }
}

onMounted(load)
</script>

<template>
  <PageHeader title="Roles & Permissions" description="Manage workspace access with protected built-in roles." />
  <p v-if="error" class="form-error">{{ error }}</p>
  <p v-if="message" class="success-message">{{ message }}</p>

  <form class="management-form" @submit.prevent="save">
    <div class="form-grid">
      <label>
        Role name
        <input v-model.trim="form.name" :disabled="Boolean(form.id && roles.find(r => r.id === form.id)?.is_system)" required>
      </label>
      <label>
        Landing page
        <select v-model="form.landing_page" required>
          <option v-for="page in landingPages" :key="page" :value="page">{{ page }}</option>
        </select>
      </label>
      <label class="full-field">
        Description
        <textarea v-model.trim="form.description" rows="2" maxlength="500" />
      </label>
    </div>
    <div class="permission-groups">
      <fieldset v-for="(permissions, group) in permissionGroups" :key="group">
        <legend>{{ group }}</legend>
        <label v-for="(label, permission) in permissions" :key="permission" class="check-field">
          <input v-model="form.permissions" type="checkbox" :value="permission">
          <span><strong>{{ label }}</strong><small>{{ permission }}</small></span>
        </label>
      </fieldset>
    </div>
    <div class="form-actions">
      <button class="primary-button">{{ form.id ? 'Save role' : 'Create role' }}</button>
      <button v-if="form.id" class="secondary-button" type="button" @click="reset">Cancel edit</button>
    </div>
  </form>

  <h2 class="section-title">Roles</h2>
  <WorkspaceTable
    :columns="[
      { key: 'name', label: 'Role' },
      { key: 'landing_page', label: 'Landing page' },
      { key: 'active_user_count', label: 'Active users' },
      { key: 'permissions', label: 'Permissions' },
      { key: 'is_system', label: 'Type' }
    ]"
    :rows="roles"
  >
    <template #cell-permissions="{ row }">{{ row.permissions.length }}</template>
    <template #cell-is_system="{ row }">
      <span class="status-badge">{{ row.is_system ? 'Built-in' : 'Custom' }}</span>
    </template>
    <template #actions="{ row }">
      <button @click="edit(row)">Edit</button>
    </template>
  </WorkspaceTable>
</template>
