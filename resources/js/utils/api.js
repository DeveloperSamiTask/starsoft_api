import { ofetch } from 'ofetch'

const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL || '/api')
  .trim()
  .replace(/^['"]|['"]$/g, '')

export const $api = ofetch.create({
  baseURL: apiBaseUrl,
  async onRequest({ options }) {
    const accessToken = useCookie('accessToken').value
    if (accessToken)
      options.headers.append('Authorization', `Bearer ${accessToken}`)
  },
})
