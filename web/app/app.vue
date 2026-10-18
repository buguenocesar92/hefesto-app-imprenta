<template>
  <div class="aplicacion">
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </div>
</template>

<script setup lang="ts">
useHead({
  titleTemplate: (titulo) => (titulo ? `${titulo} · Gestion de Imprenta` : 'Gestion de Imprenta')
})
</script>

<style scoped>
.aplicacion {
  min-height: 100vh;
}
</style>
