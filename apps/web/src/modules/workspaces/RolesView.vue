<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import { UiButton, UiCheckbox, UiInput, UiPageHeader, UiSectionCard, UiSelect, UiStatusBadge, UiTableShell } from '../../components/ui/index.js'

const roles = ref([])
const permissionGroups = ref({})
const landingPages = ref([])
const loading = ref(true)
const error = ref('')
const saving = ref(false)
const message = ref('')

const blank = () => ({
  id: null,
  name: '',
  description: '',
  landing_page: 'dashboard',
  permissions: []
})
const form = ref(blank())

const editingSystemRole = computed(() => Boolean(form.value.id && roles.value.find(r => r.id === form.value.id)?.is_system))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await api.get('/roles', { per_page: 100 })
    roles.value = data.roles.data
    permissionGroups.value = data.permission_groups
    landingPages.value = data.landing_pages
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    loading.value = false
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

function togglePermission(permission, checked) {
  if (checked) {
    if (!form.value.permissions.includes(permission)) form.value.permissions.push(permission)
  } else {
    form.value.permissions = form.value.permissions.filter(item => item !== permission)
  }
}

async function save() {
  saving.value = true
  error.value = ''
  message.value = ''
  try {
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
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <UiPageHeader title="Roles & Permissions" description="Manage workspace access with protected built-in roles." />

  <UiSectionCard :title="form.id ? 'Edit role' : 'Create role'" class="roles-form-card">
    <form class="roles-form" @submit.prevent="save">
      <fieldset class="roles-fieldset">
        <legend>Role details</legend>
        <UiInput
          v-model.trim="form.name"
          label="Role name"
          :disabled="editingSystemRole"
          :hint="editingSystemRole ? 'Built-in role names can\'t be changed.' : ''"
          required
        />
        <UiSelect v-model="form.landing_page" label="Landing page" required>
          <option v-for="page in landingPages" :key="page" :value="page">{{ page }}</option>
        </UiSelect>
        <label class="ui-field roles-fieldset__span">
          <span class="ui-field__label">Description</span>
          <textarea v-model.trim="form.description" class="ui-field-control" rows="2" maxlength="500"></textarea>
        </label>
      </fieldset>

      <div class="roles-permission-groups">
        <UiSectionCard
          v-for="(permissions, group) in permissionGroups"
          :key="group"
          :title="group"
          class="roles-permission-card"
        >
          <div class="roles-permission-list">
            <UiCheckbox
              v-for="(label, permission) in permissions"
              :key="permission"
              :model-value="form.permissions.includes(permission)"
              :label="label"
              :description="permission"
              @update:model-value="checked => togglePermission(permission, checked)"
            />
          </div>
        </UiSectionCard>
      </div>

      <div class="roles-form-footer">
        <div aria-live="polite">
          <p v-if="error" class="form-error" role="alert">{{ error }}</p>
          <p v-else-if="message" class="success-message">{{ message }}</p>
        </div>
        <div class="roles-form-actions">
          <UiButton type="submit" :loading="saving" loading-label="Saving">{{ form.id ? 'Save role' : 'Create role' }}</UiButton>
          <UiButton v-if="form.id" variant="secondary" type="button" :disabled="saving" @click="reset">Cancel edit</UiButton>
        </div>
      </div>
    </form>
  </UiSectionCard>

  <UiTableShell
    title="Roles"
    :loading="loading"
    :error="error"
    :empty="!loading && !error && !roles.length"
    empty-title="No roles found"
    @retry="load"
  >
    <table>
      <thead>
        <tr>
          <th>Role</th>
          <th>Landing page</th>
          <th>Active users</th>
          <th>Permissions</th>
          <th>Type</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="role in roles" :key="role.id">
          <td>{{ role.name }}</td>
          <td>{{ role.landing_page }}</td>
          <td>{{ role.active_user_count }}</td>
          <td>{{ role.permissions.length }}</td>
          <td><UiStatusBadge :status="role.is_system ? 'Built-in' : 'Custom'" :tone="role.is_system ? 'info' : 'neutral'" /></td>
          <td><UiButton size="sm" variant="secondary" @click="edit(role)">Edit</UiButton></td>
        </tr>
      </tbody>
    </table>
  </UiTableShell>
</template>

<style scoped>
.roles-form-card {
  margin-bottom: var(--fm-space-4);
}
.roles-form {
  display: grid;
  gap: var(--fm-space-6);
}
.roles-fieldset {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr));
  gap: var(--fm-space-5);
  margin: 0;
  padding: 0;
  border: 0;
}
.roles-fieldset legend {
  grid-column: 1 / -1;
  padding: 0 0 var(--fm-space-2);
  color: var(--fm-color-text);
  font-size: var(--fm-font-size-sm);
  font-weight: var(--fm-font-weight-semibold);
  border-bottom: var(--fm-border-width) solid var(--fm-color-border);
  margin-bottom: var(--fm-space-1);
}
.roles-fieldset__span {
  grid-column: 1 / -1;
}
.roles-permission-groups {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 18rem), 1fr));
  gap: var(--fm-space-4);
}
.roles-permission-list {
  display: grid;
  gap: var(--fm-space-3);
}
.roles-form-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--fm-space-4);
  padding-top: var(--fm-space-5);
  border-top: var(--fm-border-width) solid var(--fm-color-border);
  flex-wrap: wrap;
}
.roles-form-footer > div:first-child {
  min-width: 0;
}
.roles-form-actions {
  display: flex;
  gap: var(--fm-space-2);
}
@media (max-width: 44rem) {
  .roles-form-footer {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
