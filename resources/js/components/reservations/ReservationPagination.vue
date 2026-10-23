<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { MyReservationsPage } from '@/types/reservation';

defineProps<{ reservations: MyReservationsPage }>();
const loading = ref(false);
const error = ref('');
function start() {
    loading.value = true;
    error.value = '';
}
function failed(): false {
    error.value = 'We couldn’t load that page. Please try again.';
    return false;
}
</script>

<template>
    <div class="space-y-4">
        <nav
            v-if="reservations.last_page > 1"
            aria-label="Reservation pages"
            :aria-busy="loading"
            class="flex flex-wrap items-center justify-between gap-4"
        >
            <p class="text-sm text-muted-foreground">
                Page {{ reservations.current_page }} of
                {{ reservations.last_page }}
            </p>
            <div class="flex flex-wrap gap-3">
                <template
                    v-for="link in [
                        { label: 'Previous', url: reservations.prev_page_url },
                        { label: 'Next', url: reservations.next_page_url },
                    ]"
                    :key="link.label"
                >
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :aria-disabled="loading || undefined"
                        :aria-label="`${link.label} reservations page`"
                        class="inline-flex min-h-11 items-center justify-center rounded-sm border border-primary px-4 py-2 text-sm font-medium text-primary hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                        @start="start"
                        @before="() => !loading"
                        @finish="loading = false"
                        @network-error="failed"
                        @http-exception="failed"
                        >{{ link.label }}</Link
                    >
                    <span
                        v-else
                        aria-disabled="true"
                        class="inline-flex min-h-11 items-center justify-center rounded-sm border border-border px-4 py-2 text-sm text-muted-foreground"
                        >{{ link.label }}</span
                    >
                </template>
            </div>
        </nav>
        <p v-if="loading" role="status" class="text-sm">
            Loading reservations…
        </p>
        <p v-if="error" role="alert" class="text-sm text-destructive">
            {{ error }}
        </p>
    </div>
</template>
