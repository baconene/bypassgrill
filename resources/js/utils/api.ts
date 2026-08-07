import axios from 'axios'
import { router } from '@inertiajs/vue3'

const api = axios.create({
    baseURL: window.location.origin,
    withCredentials: true,
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
})

api.interceptors.request.use((config) => {
    if (config.data instanceof FormData) {
        delete config.headers['Content-Type']
    }
    return config
})

api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            // Use Inertia's router so it cleanly cancels any pending XHR visits
            // instead of aborting them with a hard location change (which triggers
            // HttpNetworkError). The never-settling promise prevents an unhandled
            // rejection after the navigation takes over.
            router.visit('/login', { replace: true })
            return new Promise(() => {})
        }
        return Promise.reject(error)
    }
)

export default api
