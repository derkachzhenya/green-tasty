<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { authRequest } from '@/lib/authRequest';
import { validEmail, validName, validPassword } from '@/lib/authValidation';
import { SessionRequestError } from '@/lib/sessionJson';
import AuthField from './AuthField.vue';
import PasswordGuidance from './PasswordGuidance.vue';

const emit = defineEmits<{ close: [] }>();
const props = defineProps<{
    enabled: boolean;
    restaurant: { name: string; address: string } | null;
}>();
const fields = reactive({
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    password_confirmation: '',
});
const errors = reactive<Record<string, string>>({});
const busy = ref(false);
const message = ref('');
const retryAt = ref(0);
const clock = ref(Date.now());
const retryAfter = computed(() =>
    Math.max(0, Math.ceil((retryAt.value - clock.value) / 1000)),
);
const canSubmit = computed(
    () =>
        !busy.value &&
        props.enabled &&
        Boolean(props.restaurant) &&
        !retryAfter.value &&
        validName(fields.first_name) &&
        validName(fields.last_name) &&
        validEmail(fields.email) &&
        validPassword(fields.password) &&
        fields.password === fields.password_confirmation,
);
let controller: AbortController | undefined;
let timer: ReturnType<typeof setInterval> | undefined;
let alive = true;

function clear(field: string) {
    delete errors[field];
    message.value = '';
}
function validate(field: keyof typeof fields) {
    const value = fields[field];
    if (!value.trim()) errors[field] = 'This field is required.';
    else if (
        (field === 'first_name' || field === 'last_name') &&
        !validName(value)
    )
        errors[field] = 'Use letters, hyphens or apostrophes.';
    else if (field === 'email' && !validEmail(value))
        errors[field] = 'Enter a valid email address.';
    else if (field === 'password' && !validPassword(value))
        errors[field] =
            'Use 8–16 characters with uppercase, lowercase, number and symbol.';
    else if (field === 'password_confirmation' && value !== fields.password)
        errors[field] = 'Passwords must match.';
    else delete errors[field];
}
function wipeSecrets() {
    fields.password = '';
    fields.password_confirmation = '';
}
function close() {
    alive = false;
    controller?.abort();
    wipeSecrets();
    emit('close');
}
async function register() {
    if (!canSubmit.value) return;
    for (const key of Object.keys(errors)) delete errors[key];
    message.value = '';
    busy.value = true;
    controller = new AbortController();
    try {
        const result = await authRequest('/staff/register', controller.signal, {
            ...fields,
            email: fields.email.trim(),
        });
        if (!alive) return;
        if (result.next !== 'authenticated' || !result.redirect)
            throw new Error('Missing staff registration destination.');
        wipeSecrets();
        router.clearHistory();
        router.visit(result.redirect, { replace: true });
    } catch (exception) {
        if (
            !alive ||
            (exception instanceof DOMException &&
                exception.name === 'AbortError')
        )
            return;
        fields.password = '';
        fields.password_confirmation = '';
        if (exception instanceof SessionRequestError) {
            for (const [field, values] of Object.entries(exception.errors))
                errors[field] = values[0] ?? '';
            if (exception.status === 429) {
                clock.value = Date.now();
                retryAt.value =
                    clock.value + Math.max(exception.retryAfter, 1) * 1000;
                message.value =
                    'Too many registration attempts. Please wait before trying again.';
            } else if (exception.status === 419 || exception.status === 401)
                message.value =
                    'Your session expired. Refresh this page and try again.';
            else if (!Object.keys(exception.errors).length)
                message.value =
                    'Unable to create your account right now. Please try again.';
        } else
            message.value =
                'Unable to create your account right now. Please try again.';
    } finally {
        if (alive) busy.value = false;
    }
}
onMounted(() => {
    timer = setInterval(() => {
        clock.value = Date.now();
    }, 1000);
    window.addEventListener('pagehide', close);
});
onBeforeUnmount(() => {
    alive = false;
    controller?.abort();
    wipeSecrets();
    if (timer) clearInterval(timer);
    window.removeEventListener('pagehide', close);
});
</script>

<template>
    <Dialog
        :open="true"
        @update:open="
            (open) => {
                if (!open) close();
            }
        "
    >
        <DialogContent
            class="max-h-[calc(100dvh-2rem)] overflow-y-auto rounded-3xl bg-white p-6 sm:max-w-[560px] sm:p-8"
        >
            <DialogTitle class="pr-6 text-2xl font-medium"
                >Create a staff account</DialogTitle
            >
            <DialogDescription class="text-sm leading-6 text-muted-foreground"
                >Create a separate account to manage your restaurant’s
                reservations and orders.</DialogDescription
            >
            <p
                v-if="!enabled || !restaurant"
                role="alert"
                class="rounded-lg bg-destructive/5 p-3 text-sm text-destructive"
            >
                Staff registration is currently unavailable. Please contact the
                restaurant administrator.
            </p>
            <form
                v-else
                class="mt-2 space-y-5"
                novalidate
                :aria-busy="busy"
                @submit.prevent="register"
            >
                <p
                    v-if="message"
                    role="alert"
                    class="rounded-lg bg-destructive/5 p-3 text-sm text-destructive"
                >
                    {{ message }}
                </p>
                <p class="rounded-lg bg-accent p-3 text-sm">
                    <span class="font-medium">Your workplace</span><br />{{
                        restaurant.name
                    }}<br />{{ restaurant.address }} · Waiter
                </p>
                <p
                    v-if="errors.restaurant"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.restaurant }}
                </p>
                <div class="grid gap-5 sm:grid-cols-2">
                    <AuthField
                        v-for="field in ['first_name', 'last_name'] as const"
                        :id="`staff-register-${field}`"
                        :key="field"
                        v-model="fields[field]"
                        :label="
                            field === 'first_name' ? 'First Name' : 'Last Name'
                        "
                        :autocomplete="
                            field === 'first_name'
                                ? 'given-name'
                                : 'family-name'
                        "
                        maxlength="100"
                        :disabled="busy"
                        :error="errors[field]"
                        @change="clear(field)"
                        @blur="validate(field)"
                    />
                </div>
                <AuthField
                    id="staff-register-email"
                    v-model="fields.email"
                    label="Staff email"
                    type="email"
                    autocomplete="username"
                    maxlength="255"
                    :disabled="busy"
                    :error="errors.email"
                    @change="clear('email')"
                    @blur="validate('email')"
                />
                <div>
                    <AuthField
                        id="staff-register-password"
                        v-model="fields.password"
                        label="Password"
                        type="password"
                        autocomplete="new-password"
                        described-by="staff-password-guidance"
                        :disabled="busy"
                        :error="errors.password"
                        @change="clear('password')"
                        @blur="validate('password')"
                    />
                    <PasswordGuidance
                        id="staff-password-guidance"
                        :password="fields.password"
                    />
                </div>
                <AuthField
                    id="staff-register-password_confirmation"
                    v-model="fields.password_confirmation"
                    label="Confirm Password"
                    type="password"
                    autocomplete="new-password"
                    :disabled="busy"
                    :error="errors.password_confirmation"
                    @change="clear('password_confirmation')"
                    @blur="validate('password_confirmation')"
                />
                <Button
                    type="submit"
                    class="min-h-12 w-full"
                    :disabled="!canSubmit"
                    >{{
                        busy ? 'Creating account…' : 'Create staff account'
                    }}</Button
                >
                <p
                    v-if="retryAfter"
                    role="timer"
                    class="text-sm text-muted-foreground"
                >
                    Try again in {{ retryAfter }} seconds.
                </p>
                <p class="text-center text-sm text-muted-foreground">
                    Already have a staff account?
                    <button
                        type="button"
                        class="text-primary underline underline-offset-4"
                        @click="close"
                    >
                        Sign in
                    </button>
                </p>
            </form>
        </DialogContent>
    </Dialog>
</template>
