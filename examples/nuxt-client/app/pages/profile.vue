<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

// Appel à une route serveur, qui utilise l'access token (jamais exposé au navigateur)
const { data: me, error } = await useFetch('/api/me')
</script>

<template>
  <h1>Mon profil</h1>
  <p v-if="error">Session expirée ou accès retiré : <a href="/auth/login?redirect=/profile">reconnectez-vous</a>.</p>
  <pre v-else>{{ me }}</pre>
  <p><NuxtLink to="/">Accueil</NuxtLink></p>
</template>
