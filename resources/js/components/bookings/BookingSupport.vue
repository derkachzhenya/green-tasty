<script setup lang="ts">
defineProps<{ phone?: string | null; greeting?: boolean }>();
</script>
<template>
    <aside
        class="flex min-w-0 items-center gap-3 rounded-lg border border-primary/10 bg-[#e4f6e5] p-3 text-xs leading-5 sm:text-sm"
    >
        <img
            src="/images/green-tasty/icons/phone-call.png"
            alt=""
            width="32"
            height="32"
            class="size-8 shrink-0 object-contain"
        />
        <p class="min-w-0 wrap-anywhere">
            {{
                greeting
                    ? 'For assistance with your reservation or pre-order, contact your restaurant.'
                    : 'For groups larger than 4, please contact the restaurant directly.'
            }}
            <a
                v-if="phone"
                :href="`tel:${phone.replace(/[^+\d]/g, '')}`"
                class="block underline focus-visible:outline-2"
                >{{ phone }}</a
            >
        </p>
    </aside>
</template>
