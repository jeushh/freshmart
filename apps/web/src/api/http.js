import axios from 'axios'

const baseUrl = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000'
const http = axios.create({
  baseURL: `${baseUrl}/api`,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json'
  }
})

async function request(config) {
  try {
    return (await http(config)).data
  } catch (error) {
    const message = error.response?.data?.message
      || Object.values(error.response?.data?.errors || {}).flat()[0]
      || error.message
      || 'Request failed.'
    const requestError = new Error(message)
    requestError.status = error.response?.status
    throw requestError
  }
}

export const api = {
  csrf: () => axios.get(`${baseUrl}/sanctum/csrf-cookie`, {
    withCredentials: true,
    withXSRFToken: true
  }),
  session: async () => {
    try {
      const data = await request({ url: '/me' })
      const user = data.user
      return {
        authenticated: true,
        username: user.username,
        full_name: user.full_name,
        employee_id: user.employee_id,
        permissions: data.permissions || [],
        landing_page: data.landing_page || 'dashboard'
      }
    } catch (error) {
      if ([401, 419].includes(error.status)) {
        return { authenticated: false, permissions: [] }
      }
      throw error
    }
  },
  login: async (username, password) => {
    await api.csrf()
    return request({ method: 'post', url: '/login', data: { username, password } })
  },
  logout: () => request({ method: 'post', url: '/logout' }),
  get: (url, params = {}) => request({ url, params }),
  post: (url, data = {}) => request({ method: 'post', url, data }),
  put: (url, data = {}) => request({ method: 'put', url, data }),
  employees: (params = {}) => request({ url: '/employees', params }),
  attendance: (params = {}) => request({ url: '/attendance', params }),
  payroll: (params = {}) => request({ url: '/payroll', params })
}

export default http
