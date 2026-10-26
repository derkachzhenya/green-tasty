import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
    type Ref,
} from 'vue';

export type PreOrderTiming = {
    server_now: string;
    warning_at: string;
    locks_at: string;
    locked: boolean;
    unconfirmed: boolean;
};
/** UI hint follows server time plus monotonic elapsed time; Laravel remains authoritative. */
export function usePreOrderClock(
    source: Readonly<Ref<PreOrderTiming | undefined>>,
) {
    const now = ref(0);
    let received = 0;
    let server = 0;
    let timer: ReturnType<typeof setInterval> | undefined;
    watch(
        source,
        (value) => {
            if (!value) return;
            server = Date.parse(value.server_now);
            received = performance.now();
            now.value = server;
        },
        { immediate: true },
    );
    onMounted(() => {
        timer = setInterval(() => {
            now.value = server + performance.now() - received;
        }, 1000);
    });
    onBeforeUnmount(() => clearInterval(timer));
    const cutoffLocked = computed(() =>
        Boolean(
            source.value &&
            (source.value.locked ||
                now.value >= Date.parse(source.value.locks_at)),
        ),
    );
    const warning = computed(() =>
        Boolean(
            source.value?.unconfirmed &&
            !cutoffLocked.value &&
            now.value >= Date.parse(source.value.warning_at),
        ),
    );
    const minutes = computed(() =>
        source.value
            ? Math.max(
                  0,
                  Math.ceil(
                      (Date.parse(source.value.locks_at) - now.value) / 60000,
                  ),
              )
            : 0,
    );
    return { cutoffLocked, warning, minutes };
}
