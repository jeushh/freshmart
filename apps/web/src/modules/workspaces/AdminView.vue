<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import { sessionStore } from '../../stores/session.js'
import PageHeader from '../../components/common/PageHeader.vue'
import WorkspaceTable from '../../components/common/WorkspaceTable.vue'

const users = ref([])
const roles = ref([])
const employees = ref([])
const audit = ref([])
const error = ref('')
const form = ref({ username: '', full_name: '', role_id: '', employee_id: null, status: 'Active', password: '' })

async function load() {
  try {
    error.value = ''
    const data = await api.get('/workspace/admin')
    users.value = data.users || []
    roles.value = data.roles || []
    employees.value = data.employees || []
    audit.value = data.audit || []
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function save() {
  try {
    error.value = ''
    await api.post('/workspace/users', form.value)
    form.value = { username: '', full_name: '', role_id: '', employee_id: null, status: 'Active', password: '' }
    await load()
  } catch (requestError) {
    error.value = requestError.message
  }
}

onMounted(load)
</script>

<template>
  <PageHeader title="Administration" description="Manage user access and review audit activity." />
  <p v-if="error" class="form-error">{{ error }}</p>

  <form v-if="sessionStore.can('system.users.manage')" class="inline-form" @submit.prevent="save">
    <input v-model="form.username" placeholder="Username" required>
    <input v-model="form.full_name" placeholder="Full name" required>
    <select v-model="form.role_id" required>
      <option value="">Role</option>
      <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>
    </select>
    <select v-model="form.employee_id">
      <option :value="null">No employee link</option>
      <option v-for="employee in employees" :key="employee.id" :value="employee.id">
        {{ employee.employee_no }} — {{ employee.full_name }}
      </option>
    </select>
    <input v-model="form.password" type="password" minlength="8" placeholder="Temporary password" required>
    <button class="primary-button">Create account</button>
  </form>

  <WorkspaceTable
    v-if="sessionStore.can('system.users.manage')"
    :columns="[
      { key: 'username', label: 'Username' },
      { key: 'full_name', label: 'Name' },
      { key: 'role_name', label: 'Role' },
      { key: 'status', label: 'Status' },
      { key: 'last_login', label: 'Last login' }
    ]"
    :rows="users"
  />

  <template v-if="sessionStore.can('system.audit.view')">
    <h2 class="section-title">Recent audit log</h2>
    <WorkspaceTable
      :columns="[
        { key: 'created_at', label: 'Date' },
        { key: 'username', label: 'User' },
        { key: 'action', label: 'Action' },
        { key: 'entity_type', label: 'Entity' }
      ]"
      :rows="audit"
    />
  </template>
</template>
