<script setup lang="ts">
import { Deferred, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import ContentState from './ContentState.vue';

const props = defineProps<{ data: string; errorTitle: string }>();
const retrying = ref(false);
const reloadFailed = ref(false);

function retry(): void {
    router.reload({
        only: [props.data],
        onStart: () => {
            retrying.value = true;
            reloadFailed.value = false;
        },
        onFinish: () => {
            retrying.value = false;
        },
        onNetworkError: () => {
            reloadFailed.value = true;
            return false;
        },
        onHttpException: () => {
            reloadFailed.value = true;
            return false;
        },
    });
}
</script>

<template>
    <Deferred :data="data">
        <template #fallback><slot name="fallback" /></template>
        <template #rescue>
            <ContentState
                error
                :title="errorTitle"
                description="Please try again in a moment."
            >
                <Button :disabled="retrying" class="min-h-11" @click="retry">{{
                    retrying ? 'Trying again…' : 'Try again'
                }}</Button>
                <p v-if="reloadFailed" role="alert" class="mt-3 text-sm">
                    Still unable to connect. Please check your connection and
                    try again.
                </p>
            </ContentState>
        </template>
        <slot />
    </Deferred>
</template>
