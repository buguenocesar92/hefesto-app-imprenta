/**
 * Configuracion de Nuxt de Gestion de Imprenta.
 *
 * EL NAVEGADOR NUNCA HABLA CON LA API.
 *
 * Todo va a `/api/...` de este mismo servidor, y ese es el BFF: reenvia a
 * la API de Laravel y le pasa la cookie de sesion. El navegador no sabe
 * cual es la direccion de la API ni por que: eso queda en `NUXT_API_BASE`,
 * que es config PRIVADA y por eso no lleva el prefijo `NUXT_PUBLIC_`.
 *
 * Ver docs/01-ARQUITECTURA.md.
 */
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',

  devtools: {
    enabled: false
  },

  ssr: true,

  css: ['~/assets/css/main.css'],

  runtimeConfig: {
    // Config PRIVADA: solo la usa el servidor de Nuxt para reenviar a la
    // API. El navegador nunca la ve.
    apiBase: process.env.NUXT_API_BASE ?? 'http://localhost:8000',

    public: {
      baseUrl: process.env.NUXT_PUBLIC_BASE ?? 'http://localhost:3000'
    }
  },

  app: {
    head: {
      htmlAttrs: { lang: 'es-CL' },
      title: 'Gestion de Imprenta',
      meta: [
        {
          name: 'description',
          content:
            'Pedidos con prueba aprobada, consumo de papel por pliegos y control de merma para imprenta, libreria y papeleria.'
        },
        { name: 'robots', content: 'noindex, nofollow' }
      ]
    }
  },

  nitro: {
    routeRules: {
      // La API nunca se cachea en un proxy: las respuestas dependen de la
      // empresa de la sesion y cachearlas seria mostrarle los datos de una
      // empresa a otra.
      '/api/**': { cache: false, headers: { 'cache-control': 'no-store' } }
    }
  },

  typescript: {
    strict: true,
    typeCheck: false
  }
})
