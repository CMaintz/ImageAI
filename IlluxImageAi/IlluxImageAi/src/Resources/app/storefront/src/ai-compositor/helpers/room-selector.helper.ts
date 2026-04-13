import type { RoomFolder } from '../types';

export class RoomSelectorHelper {
    private selected: Map<string, string> = new Map();
    private readonly maxRooms: number;
    private readonly selectedClass: string;

    constructor(maxRooms: number = 5, selectedClass: string = 'is-selected') {
        this.maxRooms = maxRooms;
        this.selectedClass = selectedClass;
    }

    toggle(toggle: HTMLElement): boolean {
        const roomId = toggle.dataset.roomId;
        const roomName = toggle.dataset.roomName;

        if (!roomId || !roomName) {
            return false;
        }

        if (toggle.classList.contains(this.selectedClass)) {
            toggle.classList.remove(this.selectedClass);
            this.selected.delete(roomId);
            return true;
        }

        if (this.selected.size >= this.maxRooms) {
            return false;
        }

        toggle.classList.add(this.selectedClass);
        this.selected.set(roomId, roomName);
        return true;
    }

    getSelected(): RoomFolder[] {
        return Array.from(this.selected.entries()).map(([folderId, name]) => ({
            folderId,
            name
        }));
    }

    getCount(): number {
        return this.selected.size;
    }

    clear(): void {
        this.selected.clear();
    }

    updateCounterUI(counterElement: HTMLElement | null): void {
        if (counterElement) {
            counterElement.textContent = String(this.selected.size);
        }
    }

    updateButtonState(button: HTMLButtonElement | null): void {
        if (button) {
            button.disabled = this.selected.size === 0;
        }
    }
}
