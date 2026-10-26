import { createRenderer, h, nextTick, reactive, type Component } from 'vue';

// Minimal Vue host for interaction tests; browser focus/portal behavior is tested in the real browser.
export type TestNode = {
    tag: string;
    props: Record<string, unknown>;
    children: TestNode[];
    parent: TestNode | null;
    text: string;
    style: Record<string, string>;
    value: string;
    readonly options: TestNode[];
    addEventListener: () => void;
    removeEventListener: () => void;
    focus: () => void;
    select: () => void;
    getRootNode: () => { activeElement: null };
};

function node(tag: string, text = ''): TestNode {
    return {
        tag,
        text,
        props: {},
        children: [],
        parent: null,
        style: {},
        value: '',
        get options() {
            return this.children.filter((child) => child.tag === 'option');
        },
        addEventListener() {},
        removeEventListener() {},
        focus() {},
        select() {},
        getRootNode() {
            return { activeElement: null };
        },
    };
}

const renderer = createRenderer<TestNode, TestNode>({
    createElement: (tag) => node(tag),
    createText: (text) => node('#text', text),
    createComment: (text) => node('#comment', text),
    setText: (target, text) => {
        target.text = text;
    },
    setElementText: (target, text) => {
        target.text = text;
        target.children = [];
    },
    parentNode: (target) => target.parent,
    nextSibling: (target) =>
        target.parent?.children[target.parent.children.indexOf(target) + 1] ??
        null,
    patchProp: (target, key, _previous, value) => {
        target.props[key] = value;
    },
    insert(target, parent, anchor) {
        if (target.parent)
            target.parent.children.splice(
                target.parent.children.indexOf(target),
                1,
            );
        target.parent = parent;
        const index = anchor ? parent.children.indexOf(anchor) : -1;
        if (index < 0) parent.children.push(target);
        else parent.children.splice(index, 0, target);
    },
    remove(target) {
        target.parent?.children.splice(
            target.parent.children.indexOf(target),
            1,
        );
        target.parent = null;
    },
});

export function mountComponent(
    component: Component,
    props: Record<string, unknown>,
) {
    const root = node('root');
    const state = reactive(props);
    const app = renderer.createApp({ render: () => h(component, state) });
    app.mount(root);
    let unmounted = false;
    return {
        root,
        setProps: (updates: Record<string, unknown>) =>
            Object.assign(state, updates),
        unmount: () => {
            if (!unmounted) {
                app.unmount();
                unmounted = true;
            }
        },
    };
}

export function findAll(
    root: TestNode,
    predicate: (element: TestNode) => boolean,
): TestNode[] {
    return [
        ...(predicate(root) ? [root] : []),
        ...root.children.flatMap((child) => findAll(child, predicate)),
    ];
}

export function textContent(root: TestNode): string {
    return (
        (root.tag === '#comment' ? '' : root.text) +
        root.children.map(textContent).join('')
    );
}

export async function fire(
    target: TestNode,
    event: string,
    value?: unknown,
): Promise<void> {
    const handler = target.props[event] as
        | ((argument: unknown) => unknown)
        | undefined;
    await handler?.(
        value ?? { currentTarget: target, target, preventDefault() {} },
    );
    await nextTick();
}

export async function flush(): Promise<void> {
    await new Promise<void>((resolve) => setTimeout(resolve, 0));
    await nextTick();
}
