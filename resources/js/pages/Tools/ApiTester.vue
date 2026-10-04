<script setup lang="ts">
import {Head} from '@inertiajs/vue3';import {onMounted} from 'vue';
defineOptions({layout:{breadcrumbs:[{title:'Dashboard',href:'/dashboard'},{title:'Tools',href:'/tools'},{title:'API Tester',href:'/tools/api'}]}});
onMounted(async()=>{await import('swagger-ui-dist/swagger-ui.css');const m=await import('swagger-ui-dist');const SwaggerUI=(m as any).default;SwaggerUI({dom_id:'#swagger-ui',url:'/api/v1/tools/openapi.json',deepLinking:true,displayRequestDuration:true,tryItOutEnabled:true,persistAuthorization:true,filter:true});});
</script>
<template><Head title="Tools — API Tester"/><div class="rounded-xl border bg-white p-2 shadow-sm dark:bg-white"><div id="swagger-ui"></div></div></template>
