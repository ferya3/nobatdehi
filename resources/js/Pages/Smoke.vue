<script setup lang="ts">
defineProps<{ message: string }>();
</script>

<template>
    <div class="p-8 text-lg">{{ message }}</div>
</template>
