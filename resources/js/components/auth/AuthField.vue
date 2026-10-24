<script setup lang="ts">
import { Eye, EyeOff } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';

defineOptions({ inheritAttrs: false });
const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        type?: string;
        error?: string;
        describedBy?: string;
        disabled?: boolean;
        strength?: string;
    }>(),
    { type: 'text', error: '' },
);
const value = defineModel<string>({ required: true });
const visible = ref(false);
const interacted = ref(false);
defineEmits<{ blur: []; change: [] }>();
</script>

<template>
    <div class="min-w-0 space-y-1">
        <div class="flex items-center justify-between gap-2">
            <label :for="id" class="block text-sm leading-6 font-medium">{{
                label
            }}</label>
            <span
                v-if="strength"
                class="flex items-center gap-2 text-xs"
                aria-live="polite"
                ><span
                    class="size-2 rounded-full"
                    :class="
                        strength === 'Strong' ? 'bg-primary' : 'bg-destructive'
                    "
                    aria-hidden="true"
                />{{ strength }}</span
            >
        </div>
        <div class="relative">
            <input
                :id="id"
                v-model="value"
                v-bind="$attrs"
                required
                :type="type === 'password' && visible ? 'text' : type"
                :disabled="disabled"
                :aria-invalid="Boolean(error)"
                :aria-describedby="
                    [error ? `${id}-error` : '', describedBy]
                        .filter(Boolean)
                        .join(' ') || undefined
                "
                class="auth-input"
                :class="{ 'pr-12': type === 'password' }"
                @keydown="interacted = true"
                @pointerdown="interacted = true"
                @blur="interacted && $emit('blur')"
                @input="
                    interacted = true;
                    $emit('change');
                "
            />
            <button
                v-if="props.type === 'password'"
                type="button"
                class="absolute inset-y-0 right-0 grid size-11 place-items-center rounded-lg focus-visible:outline-2 focus-visible:outline-ring"
                :aria-label="
                    visible
                        ? `Hide ${label.toLowerCase()}`
                        : `Show ${label.toLowerCase()}`
                "
                :aria-pressed="visible"
                :disabled="disabled"
                @click="visible = !visible"
            >
                <Eye v-if="visible" class="size-5" aria-hidden="true" /><EyeOff
                    v-else
                    class="size-5"
                    aria-hidden="true"
                />
            </button>
        </div>
        <InputError
            v-if="error"
            :id="`${id}-error`"
            :message="error"
            role="alert"
            class="text-xs leading-[18px]"
        />
    </div>
</template>
