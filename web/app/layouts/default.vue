<template>
  <div class="marco">
    <header class="marco__barra">
      <NuxtLink to="/" class="marco__marca">Gestion de Imprenta</NuxtLink>

      <nav v-if="usuario" class="marco__menu">
        <NuxtLink to="/pedidos">Pedidos</NuxtLink>
        <NuxtLink to="/pruebas">Pruebas</NuxtLink>
        <NuxtLink to="/papeles">Papeles</NuxtLink>
        <NuxtLink to="/produccion">Produccion</NuxtLink>
        <NuxtLink to="/entregas">Entregas</NuxtLink>
        <NuxtLink to="/merma">Merma</NuxtLink>
        <NuxtLink to="/libreria">Libreria</NuxtLink>
        <NuxtLink to="/reportes">Reportes</NuxtLink>
      </nav>

      <div v-if="usuario" class="marco__sesion">
        <span class="marco__quien">
          {{ usuario.nombre }}
          <span class="marco__empresa">{{ usuario.empresa }}</span>
          <span class="marco__rol">{{ usuario.rol }}</span>
        </span>
        <button type="button" class="boton boton--chico" @click="salir">Salir</button>
      </div>
    </header>

    <main class="pantalla">
      <slot />
    </main>

    <footer class="marco__pie">
      <p>
        Aplicacion propia de Hefesto Studio. La cuenta del papel que se gasta
        se guarda con el calculo completo a la vista, no como un numero.
      </p>
    </footer>
  </div>
</template>

<script setup lang="ts">
const { usuario, salir } = useSesion()
</script>

<style scoped>
.marco {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

.marco__barra {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 1rem;
  padding: 0.7rem 1rem;
  background: var(--tinta);
  color: #ffffff;
}

.marco__marca {
  color: #ffffff;
  font-weight: 700;
  text-decoration: none;
  white-space: nowrap;
}

.marco__menu {
  display: flex;
  flex-wrap: wrap;
  gap: 0.85rem;
  flex: 1;
}

.marco__menu a {
  color: #cbd4de;
  text-decoration: none;
  font-size: 0.92rem;
}

.marco__menu a:hover,
.marco__menu a.router-link-active {
  color: #ffffff;
  text-decoration: underline;
}

.marco__sesion {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

.marco__quien {
  font-size: 0.85rem;
  display: flex;
  gap: 0.4rem;
  align-items: baseline;
}

.marco__empresa {
  color: #cbd4de;
}

.marco__rol {
  color: #8fa1b3;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.marco__pie {
  margin-top: auto;
  padding: 1rem;
  border-top: 1px solid var(--linea);
  color: var(--tinta-suave);
  font-size: 0.82rem;
}

.marco__pie p {
  max-width: 72rem;
  margin: 0 auto;
}
</style>
