<script setup lang="ts">
const { loggedIn, user } = useUserSession()
</script>

<template>
  <h1>Application Nuxt de démonstration</h1>
  <template v-if="loggedIn">
    <p>Connecté en tant que <strong>{{ user?.name }}</strong> ({{ user?.email }}).</p>
    <p>
      <NuxtLink to="/profile">Mon profil (page protégée)</NuxtLink> ·
      <a href="/auth/logout">Se déconnecter</a>
    </p>
  </template>
  <template v-else>
    <p>Vous n'êtes pas connecté.</p>
    <p><a href="/auth/login">Se connecter</a></p>
  </template>
</template>
