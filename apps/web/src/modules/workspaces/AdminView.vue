<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import { sessionStore } from '../../stores/session.js'
import UiButton from '../../components/ui/UiButton.vue'
import UiEmptyState from '../../components/ui/UiEmptyState.vue'
import UiErrorState from '../../components/ui/UiErrorState.vue'
import UiInput from '../../components/ui/UiInput.vue'
import UiKpiCard from '../../components/ui/UiKpiCard.vue'
import UiLoadingSkeleton from '../../components/ui/UiLoadingSkeleton.vue'
import UiPageHeader from '../../components/ui/UiPageHeader.vue'
import UiSectionCard from '../../components/ui/UiSectionCard.vue'
import UiSelect from '../../components/ui/UiSelect.vue'
import UiStatusBadge from '../../components/ui/UiStatusBadge.vue'
import UiTableShell from '../../components/ui/UiTableShell.vue'
import { formatDateTime } from '../../utils/formatters.js'

const users = ref([])
const roles = ref([])
const employees = ref([])
const audit = ref([])
const loading = ref(true)
const saving = ref(false)
const loadError = ref('')
const formError = ref('')
const formErrors = ref({})
const successMessage = ref('')
const form = ref(emptyForm())

const canManageUsers = computed(() => sessionStore.can('system.users.manage'))
const canManageRoles = computed(() => sessionStore.can('system.roles.manage'))
const canViewAudit = computed(() => sessionStore.can('system.audit.view'))
const canManageSettings = computed(() => sessionStore.can('system.settings.manage'))

const activeUsers = computed(() => users.value.filter(user => user.status === 'Active').length)
const disabledUsers = computed(() => users.value.filter(user => user.status === 'Disabled').length)

const administrationKpis = computed(() => {
  const metrics = []

  if (canManageUsers.value) {
    metrics.push(
      { label: 'Total accounts', value: users.value.length, supportingText: 'Accounts in the system' },
      { label: 'Active accounts', value: activeUsers.value, supportingText: 'Currently enabled' },
      { label: 'Disabled accounts', value: disabledUsers.value, supportingText: 'Access currently disabled' }
    )
  }

  if (canManageUsers.value || canManageRoles.value) {
    metrics.push({ label: 'Available roles', value: roles.value.length, supportingText: 'Roles available for assignment' })
  }

  if (canViewAudit.value) {
    metrics.push({ label: 'Recent events', value: audit.value.length, supportingText: 'Latest audit activity returned' })
  }

  return metrics
})

const quickActions = computed(() => [
  {
    label: 'Create account',
    description: 'Add a user and assign an approved role.',
    target: 'create-account',
    visible: canManageUsers.value
  },
  {
    label: 'Review accounts',
    description: 'Check account roles, status, and recent access.',
    target: 'user-accounts',
    visible: canManageUsers.value
  },
  {
    label: 'Roles & permissions',
    description: 'Open the dedicated role management workspace.',
    to: '/roles',
    visible: canManageRoles.value
  },
  {
    label: 'System settings',
    description: 'Open approved application configuration.',
    to: '/settings',
    visible: canManageSettings.value
  },
  {
    label: 'Audit activity',
    description: 'Review the latest recorded administration events.',
    target: 'audit-activity',
    visible: canViewAudit.value
  }
].filter(action => action.visible))

const accessSummary = computed(() => {
  const areas = []
  if (canManageUsers.value) areas.push('user accounts')
  if (canManageRoles.value) areas.push('roles and permissions')
  if (canManageSettings.value) areas.push('system settings')
  if (canViewAudit.value) areas.push('audit activity')

  if (!areas.length) return 'No administration tools are available to this account.'
  if (areas.length === 1) return `Your account has access to ${areas[0]}.`
  return `Your account has access to ${areas.slice(0, -1).join(', ')}, and ${areas.at(-1)}.`
})

function emptyForm() {
  return {
    username: '',
    full_name: '',
    role_id: '',
    employee_id: '',
    status: 'Active',
    password: ''
  }
}

function requestMessage(requestError) {
  return requestError.requestId
    ? `${requestError.message} Reference: ${requestError.requestId}`
    : requestError.message
}

function fieldError(field) {
  return formErrors.value[field]?.[0] || ''
}

async function load(showLoading = true) {
  if (showLoading) loading.value = true
  loadError.value = ''

  try {
    const data = await api.get('/workspace/admin')
    users.value = data.users || []
    roles.value = data.roles || []
    employees.value = data.employees || []
    audit.value = data.audit || []
  } catch (requestError) {
    loadError.value = requestMessage(requestError)
  } finally {
    if (showLoading) loading.value = false
  }
}

async function save() {
  saving.value = true
  formError.value = ''
  formErrors.value = {}
  successMessage.value = ''
  const createdName = form.value.full_name

  try {
    await api.post('/workspace/users', {
      ...form.value,
      employee_id: form.value.employee_id || null
    })
    form.value = emptyForm()
    successMessage.value = `${createdName} can now sign in with the new account.`
    await load(false)
  } catch (requestError) {
    formError.value = requestMessage(requestError)
    formErrors.value = requestError.errors || {}
  } finally {
    saving.value = false
  }
}

function humanize(value) {
  if (!value) return '—'
  return String(value).replaceAll('.', ' · ').replaceAll('_', ' ')
}

function scrollToSection(id) {
  document.getElementById(id)?.scrollIntoView({ block: 'start' })
}

onMounted(load)
</script>

<template>
  <div class="fm-admin">
    <UiPageHeader
      eyebrow="System administration"
      title="Administration"
      description="Manage account access and review system activity within your assigned permissions."
    >
      <template v-if="canManageUsers" #actions>
        <UiButton @click="scrollToSection('create-account')">Create account</UiButton>
      </template>
    </UiPageHeader>

    <section v-if="loading" class="ui-card fm-admin__request-state">
      <UiLoadingSkeleton :rows="8" label="Loading administration dashboard" />
    </section>

    <UiErrorState
      v-else-if="loadError"
      title="Unable to load administration"
      :message="loadError"
      :retrying="loading"
      @retry="load"
    />

    <template v-else>
      <section class="fm-admin__overview ui-card" aria-labelledby="administration-overview-title">
        <div class="fm-admin__overview-copy">
          <div class="fm-admin__overview-icon" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
              <path d="M12 3 5 6v5c0 4.5 2.5 7.5 7 10 4.5-2.5 7-5.5 7-10V6l-7-3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
              <path d="m9 12 2 2 4-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </div>
          <div>
            <p class="fm-admin__overline">Administration overview</p>
            <h2 id="administration-overview-title">Secure access, clear accountability</h2>
            <p>{{ accessSummary }} Every action remains subject to server-side authorization.</p>
          </div>
        </div>

        <div v-if="quickActions.length" class="fm-admin__quick-actions" aria-label="Administration quick actions">
          <template v-for="action in quickActions" :key="action.label">
            <button
              v-if="action.target"
              class="fm-admin__quick-action"
              type="button"
              @click="scrollToSection(action.target)"
            >
              <span>
                <strong>{{ action.label }}</strong>
                <small>{{ action.description }}</small>
              </span>
              <span aria-hidden="true">→</span>
            </button>
            <RouterLink v-else class="fm-admin__quick-action" :to="action.to">
              <span>
                <strong>{{ action.label }}</strong>
                <small>{{ action.description }}</small>
              </span>
              <span aria-hidden="true">→</span>
            </RouterLink>
          </template>
        </div>
      </section>

      <section v-if="administrationKpis.length" class="fm-admin__kpis" aria-label="Administration summary">
        <UiKpiCard
          v-for="metric in administrationKpis"
          :key="metric.label"
          :label="metric.label"
          :value="metric.value"
          :supporting-text="metric.supportingText"
        >
          <template #icon>
            <span class="fm-admin__kpi-icon" aria-hidden="true"></span>
          </template>
        </UiKpiCard>
      </section>

      <UiSectionCard
        v-if="canManageUsers"
        id="create-account"
        class="fm-admin__anchor"
        title="Create an account"
        description="Set up sign-in details, assign a role, and optionally link an employee record."
      >
        <form class="fm-admin__form" @submit.prevent="save">
          <UiInput
            v-model="form.full_name"
            label="Full name"
            autocomplete="name"
            placeholder="e.g. Maria Santos"
            :error="fieldError('full_name')"
            :disabled="saving"
            required
          />
          <UiInput
            v-model="form.username"
            label="Username"
            autocomplete="username"
            placeholder="e.g. maria.santos"
            :error="fieldError('username')"
            :disabled="saving"
            required
          />
          <UiSelect
            v-model="form.role_id"
            label="Role"
            :error="fieldError('role_id')"
            hint="The role determines the account's approved access."
            :disabled="saving"
            required
          >
            <option value="" disabled>Select a role</option>
            <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>
          </UiSelect>
          <UiSelect
            v-model="form.employee_id"
            label="Employee link"
            :error="fieldError('employee_id')"
            hint="Required for employee self-service accounts."
            :disabled="saving"
          >
            <option value="">No employee link</option>
            <option v-for="employee in employees" :key="employee.id" :value="employee.id">
              {{ employee.employee_no }} — {{ employee.full_name }}
            </option>
          </UiSelect>
          <UiInput
            v-model="form.password"
            class="fm-admin__form-wide"
            label="Temporary password"
            type="password"
            autocomplete="new-password"
            minlength="8"
            hint="Use at least 8 characters and share it securely."
            :error="fieldError('password')"
            :disabled="saving"
            required
          />

          <div class="fm-admin__form-footer">
            <div aria-live="polite">
              <p v-if="formError" class="fm-admin__message fm-admin__message--error" role="alert">{{ formError }}</p>
              <p v-else-if="successMessage" class="fm-admin__message fm-admin__message--success">{{ successMessage }}</p>
              <p v-else class="fm-admin__form-note">New accounts are active immediately after creation.</p>
            </div>
            <UiButton type="submit" :loading="saving" loading-label="Creating account">
              Create account
            </UiButton>
          </div>
        </form>
      </UiSectionCard>

      <UiTableShell
        v-if="canManageUsers"
        id="user-accounts"
        class="fm-admin__anchor"
        title="User accounts"
        description="Review account roles, access status, and the most recent sign-in."
        :empty="users.length === 0"
        empty-title="No user accounts"
        empty-description="Create the first account to begin managing system access."
      >
        <template #actions>
          <span class="fm-admin__record-count">{{ users.length }} {{ users.length === 1 ? 'account' : 'accounts' }}</span>
        </template>
        <template #emptyActions>
          <UiButton variant="secondary" @click="scrollToSection('create-account')">Create account</UiButton>
        </template>

        <table class="fm-admin__table">
          <thead>
            <tr>
              <th scope="col">User</th>
              <th scope="col">Username</th>
              <th scope="col">Role</th>
              <th scope="col">Status</th>
              <th scope="col">Last login</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="user in users" :key="user.id">
              <td><strong class="fm-admin__primary-cell">{{ user.full_name }}</strong></td>
              <td><span class="fm-admin__monospace">{{ user.username }}</span></td>
              <td>{{ user.role_name || '—' }}</td>
              <td><UiStatusBadge :status="user.status" /></td>
              <td>{{ formatDateTime(user.last_login) }}</td>
            </tr>
          </tbody>
        </table>
      </UiTableShell>

      <UiTableShell
        v-if="canViewAudit"
        id="audit-activity"
        class="fm-admin__anchor"
        title="Recent audit activity"
        description="The latest recorded system administration events available to your account."
        :empty="audit.length === 0"
        empty-title="No audit activity"
        empty-description="Recorded administration events will appear here."
      >
        <template #actions>
          <span class="fm-admin__record-count">Latest {{ audit.length }} {{ audit.length === 1 ? 'event' : 'events' }}</span>
        </template>

        <table class="fm-admin__table">
          <thead>
            <tr>
              <th scope="col">Date</th>
              <th scope="col">User</th>
              <th scope="col">Action</th>
              <th scope="col">Entity</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in audit" :key="entry.id">
              <td>{{ formatDateTime(entry.created_at) }}</td>
              <td><strong class="fm-admin__primary-cell">{{ entry.username || 'System' }}</strong></td>
              <td><span class="fm-admin__event-label">{{ humanize(entry.action) }}</span></td>
              <td>{{ humanize(entry.entity_type) }}</td>
            </tr>
          </tbody>
        </table>
      </UiTableShell>

      <UiEmptyState
        v-if="!canManageUsers && !canManageRoles && !canViewAudit && !canManageSettings"
        title="No administration tools available"
        description="Your account does not currently have access to user management or audit activity."
      />
    </template>
  </div>
</template>

<style scoped>
.fm-admin {
  min-width: 0;
  display: grid;
  gap: var(--fm-space-6);
}

.fm-admin > .ui-page-header {
  margin-bottom: 0;
}

.fm-admin__request-state {
  padding: var(--fm-space-8);
}

.fm-admin__overview {
  position: relative;
  overflow: hidden;
  padding: var(--fm-space-6);
  background:
    radial-gradient(circle at top right, rgb(16 185 129 / 12%), transparent 34rem),
    var(--fm-color-surface);
}

.fm-admin__overview::before {
  position: absolute;
  inset: 0 auto 0 0;
  width: 0.25rem;
  background: var(--fm-color-primary-600);
  content: '';
}

.fm-admin__overview-copy {
  display: flex;
  align-items: flex-start;
  gap: var(--fm-space-4);
  max-width: 52rem;
}

.fm-admin__overview-icon {
  flex: 0 0 auto;
  display: grid;
  place-items: center;
  width: 3rem;
  height: 3rem;
  border-radius: var(--fm-radius-card);
  background: var(--fm-color-primary-50);
  color: var(--fm-color-primary-700);
}

.fm-admin__overline {
  margin: 0 0 var(--fm-space-1);
  color: var(--fm-color-primary-700);
  font-size: var(--fm-font-size-xs);
  font-weight: var(--fm-font-weight-bold);
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.fm-admin__overview h2 {
  margin: 0;
  color: var(--fm-color-text);
  font-size: var(--fm-font-size-xl);
}

.fm-admin__overview-copy p:last-child {
  margin: var(--fm-space-2) 0 0;
  color: var(--fm-color-text-secondary);
  line-height: var(--fm-line-height-normal);
}

.fm-admin__quick-actions {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 13.5rem), 1fr));
  gap: var(--fm-space-3);
  margin-top: var(--fm-space-6);
}

.fm-admin__quick-action {
  width: 100%;
  min-width: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--fm-space-3);
  padding: var(--fm-space-4);
  border: var(--fm-border-width) solid var(--fm-color-border);
  border-radius: var(--fm-radius-card);
  background: rgb(255 255 255 / 78%);
  color: var(--fm-color-text);
  font: inherit;
  text-align: left;
  text-decoration: none;
  cursor: pointer;
  transition:
    border-color var(--fm-transition-fast),
    background-color var(--fm-transition-fast),
    color var(--fm-transition-fast);
}

.fm-admin__quick-action > span:first-child {
  min-width: 0;
  display: grid;
  gap: var(--fm-space-1);
}

.fm-admin__quick-action strong {
  font-size: var(--fm-font-size-sm);
}

.fm-admin__quick-action small {
  color: var(--fm-color-text-muted);
  font-size: var(--fm-font-size-xs);
  font-weight: var(--fm-font-weight-normal);
  line-height: var(--fm-line-height-normal);
}

.fm-admin__quick-action > span:last-child {
  color: var(--fm-color-primary-700);
  font-weight: var(--fm-font-weight-bold);
}

.fm-admin__quick-action:hover {
  border-color: var(--fm-color-primary-500);
  background: var(--fm-color-primary-50);
  color: var(--fm-color-primary-700);
}

.fm-admin__quick-action:focus-visible {
  outline: none;
  box-shadow: var(--fm-focus-ring);
}

.fm-admin__kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr));
  gap: var(--fm-space-4);
}

.fm-admin__kpi-icon {
  width: 0.625rem;
  height: 0.625rem;
  border-radius: var(--fm-radius-pill);
  background: var(--fm-color-primary-500);
  box-shadow: 0 0 0 0.25rem var(--fm-color-primary-50);
}

.fm-admin__anchor {
  scroll-margin-top: calc(var(--fm-shell-header-height) + var(--fm-space-4));
}

.fm-admin__form {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--fm-space-5);
}

.fm-admin__form-wide,
.fm-admin__form-footer {
  grid-column: 1 / -1;
}

.fm-admin__form-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--fm-space-4);
  padding-top: var(--fm-space-5);
  border-top: var(--fm-border-width) solid var(--fm-color-border);
}

.fm-admin__form-footer > div {
  min-width: 0;
}

.fm-admin__form-note,
.fm-admin__message {
  margin: 0;
  font-size: var(--fm-font-size-sm);
}

.fm-admin__form-note {
  color: var(--fm-color-text-muted);
}

.fm-admin__message--error {
  color: var(--fm-color-danger-700);
}

.fm-admin__message--success {
  color: var(--fm-color-success-700);
}

.fm-admin__record-count {
  display: inline-flex;
  align-items: center;
  min-height: var(--fm-control-height-sm);
  padding: 0 var(--fm-space-3);
  border-radius: var(--fm-radius-pill);
  background: var(--fm-color-slate-100);
  color: var(--fm-color-text-secondary);
  font-size: var(--fm-font-size-xs);
  font-weight: var(--fm-font-weight-semibold);
  white-space: nowrap;
}

.fm-admin__table {
  width: 100%;
  border-collapse: collapse;
}

.fm-admin__table th,
.fm-admin__table td {
  padding: var(--fm-space-3) var(--fm-space-4);
  border-bottom: var(--fm-border-width) solid var(--fm-color-border);
  text-align: left;
  white-space: nowrap;
}

.fm-admin__table th {
  background: var(--fm-color-slate-50);
  color: var(--fm-color-text-muted);
  font-size: var(--fm-font-size-xs);
  font-weight: var(--fm-font-weight-semibold);
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.fm-admin__table td {
  color: var(--fm-color-text-secondary);
  font-size: var(--fm-font-size-sm);
}

.fm-admin__table tbody tr:last-child td {
  border-bottom: 0;
}

.fm-admin__primary-cell {
  color: var(--fm-color-text);
  font-weight: var(--fm-font-weight-semibold);
}

.fm-admin__monospace {
  color: var(--fm-color-slate-700);
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: var(--fm-font-size-xs);
}

.fm-admin__event-label {
  display: inline-flex;
  padding: var(--fm-space-1) var(--fm-space-2);
  border-radius: var(--fm-radius-sm);
  background: var(--fm-color-slate-100);
  color: var(--fm-color-slate-700);
  font-size: var(--fm-font-size-xs);
  font-weight: var(--fm-font-weight-semibold);
  text-transform: capitalize;
}

@media (max-width: 44rem) {
  .fm-admin__overview {
    padding: var(--fm-space-5);
  }

  .fm-admin__overview-copy {
    flex-direction: column;
  }

  .fm-admin__form {
    grid-template-columns: minmax(0, 1fr);
  }

  .fm-admin__form-wide,
  .fm-admin__form-footer {
    grid-column: auto;
  }

  .fm-admin__form-footer {
    align-items: stretch;
    flex-direction: column;
  }

  .fm-admin__form-footer .ui-button {
    width: 100%;
  }
}
</style>
