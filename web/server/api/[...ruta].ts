/**
 * BFF: reenvia las llamadas del navegador a la API de Laravel.
 *
 * POR QUE EXISTE Y POR QUE ESTA EN EL SERVIDOR
 *
 * Si el navegador hablara directo con la API, habria que exponer la API al
 * mundo y resolver ahi el problema del origen: dos dominios, cookies y CORS.
 * Ademas el rol vive en una sesion del servidor y el navegador no la tiene.
 *
 * En vez de eso, el navegador llama a `/api/v1/...` en SU origen. Nuxt recibe
 * esa llamada y la reenvia a la API de Laravel, copiando la cookie de
 * sesion. El usuario nunca ve la direccion de la API.
 *
 * LO QUE NO SE COPIA
 *
 * Solo la cookie de sesion y el token CSRF, que son justamente lo que hace
 * falta para que la peticion sea "de esta persona". Nada mas se propaga: si
 * una cabecera se propagara sin pensar, un atacante que controle la URL del
 * `NUXT_API_BASE` podria inyectar cabeceras.
 *
 * LA EMPRESA LA PONE LA API, NO EL NAVEGADOR
 *
 * No se manda ninguna cabecera de empresa. El contexto de empresa lo saca la
 * API de la sesion, y mandarlo desde aca seria permitir que alguien elija a
 * que empresa entra: justo lo que el aislamiento tiene que impedir.
 */
import type { H3Event } from 'h3'

export default defineEventHandler(async (event: H3Event) => {
  const config = useRuntimeConfig(event)

  const ruta = event.context.params?.ruta
  const metodo = event.method
  const consulta = getQuery(event)

  const destino = new URL(
    `/${String(Array.isArray(ruta) ? ruta.join('/') : (ruta ?? ''))}`,
    config.apiBase
  )

  for (const [clave, valor] of Object.entries(consulta)) {
    if (valor !== undefined && valor !== null) {
      destino.searchParams.set(clave, String(valor))
    }
  }

  const cookie = getCookie(event, 'imprenta_sesion')
  const csrf = getHeader(event, 'x-csrf-token')

  const cabeceras: Record<string, string> = {
    accept: 'application/json'
  }

  // La cookie de sesion es lo unico que hace que la API sepa quien es.
  if (cookie) {
    cabeceras.cookie = `imprenta_sesion=${cookie}`
  }

  // El token CSRF viaja en su cabecera propia. Sin esto, Laravel rechaza
  // cualquier escritura con 419.
  if (csrf) {
    cabeceras['x-csrf-token'] = csrf
  }

  let cuerpo: string | undefined

  if (metodo !== 'GET' && metodo !== 'HEAD') {
    cuerpo = await readBody(event).then((cuerpoLeido) =>
      cuerpoLeido === undefined ? undefined : JSON.stringify(cuerpoLeido)
    )

    if (cuerpo !== undefined) {
      cabeceras['content-type'] = 'application/json'
    }
  }

  try {
    const respuesta = await fetch(destino.toString(), {
      method: metodo,
      headers: cabeceras,
      body: cuerpo,
      redirect: 'manual'
    })

    const texto = await respuesta.text()

    // Se reenvian los codigos de error sin cambiarlos. Traducir un 404 en un
    // 200 esconderia que la API no entrego el recurso.
    setResponseStatus(event, respuesta.status)

    const tipo = respuesta.headers.get('content-type') ?? ''

    if (tipo.includes('application/json')) {
      setHeader(event, 'content-type', 'application/json')
      return texto
    }

    setHeader(event, 'content-type', 'text/html; charset=utf-8')
    return texto
  } catch {
    throw createError({
      statusCode: 502,
      statusMessage: 'La API de imprenta no respondio.',
      data: {
        mensaje:
          'No se pudo comunicar con la API. Revise que este levantada y que NUXT_API_BASE apunte bien.'
      }
    })
  }
})
