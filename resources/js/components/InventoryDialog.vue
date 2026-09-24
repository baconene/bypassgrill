<script setup lang="ts">
import { X } from 'lucide-vue-next';
import {
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';

withDefaults(
    defineProps<{
        title: string;
        description: string;
        busy?: boolean;
        compact?: boolean;
    }>(),
    { busy: false, compact: false },
);
const emit = defineEmits<{ close: [] }>();
const opener = typeof document !== 'undefined' ? document.activeElement : null;
function restoreFocus(event: Event) {
    event.preventDefault();

    if (opener instanceof HTMLElement && opener.isConnected) {
        opener.focus();
    }
}
</script>

<template>
    <DialogRoot :open="true" @update:open="!busy && emit('close')">
        <DialogPortal>
            <DialogOverlay class="inventory-dialog-overlay" />
            <DialogContent
                class="inventory-theme inventory-dialog"
                :class="{ 'inventory-dialog-compact': compact }"
                :aria-busy="busy"
                @escape-key-down="busy && $event.preventDefault()"
                @interact-outside="busy && $event.preventDefault()"
                @close-auto-focus="restoreFocus"
            >
                <header class="inventory-dialog-header">
                    <div>
                        <p class="inventory-dialog-eyebrow">
                            BYPASS GRILL / INVENTORY
                        </p>
                        <DialogTitle class="inventory-dialog-title">{{
                            title
                        }}</DialogTitle>
                        <DialogDescription
                            class="inventory-dialog-description"
                            >{{ description }}</DialogDescription
                        >
                    </div>
                    <button
                        type="button"
                        class="inventory-dialog-close"
                        aria-label="Close dialog"
                        :disabled="busy"
                        @click="emit('close')"
                    >
                        <X :size="20" />
                    </button>
                </header>
                <div class="inventory-dialog-body" :inert="busy || undefined">
                    <slot />
                </div>
                <fieldset class="inventory-dialog-footer" :disabled="busy">
                    <slot name="footer" />
                </fieldset>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
