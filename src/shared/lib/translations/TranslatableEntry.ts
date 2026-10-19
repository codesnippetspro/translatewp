import { EntryType, type EntryTypeValue } from '@/shared/lib/translations/constants';

/**
 * Represents a single extractable / translatable unit from the DOM.
 * 
 * Immutable by design. Translations produce new entries rather than mutating originals, keeping extraction and application separate.
 */
export class TranslatableEntry
{
    readonly id: string;
    readonly type: EntryTypeValue;
    readonly node: Node;
    readonly attributeName: string | null;
    readonly value: string;

    /**
     * @param {object}      params
     * @param {string}      params.id               Unique stable ID for this entry
     * @param {EntryType}   params.type             Origin type (text | alt | attribute)
     * @param {Node}        params.node             Original DOM node (text node or element)
     * @param {string|null} params.attributeName    Attribute name, if type === 'attribute'
     * @param {string}      params.value            The extracted string value
     */
    constructor({ id, type, node, attributeName = null, value }: { id: string, type: EntryTypeValue, node: Node, attributeName: string | null, value: string })
    {
        this.id             = id;
        this.type           = type;
        this.node           = node;
        this.attributeName  = attributeName;
        this.value          = value;

        Object.freeze(this);
    }

    // Returns true if this entry holds a non-empty, non-whitespace string value
    get hasContent(): boolean
    {
        return this.value.trim().length > 0;
    }

    /**
     * Creates a copy of this entry with a new translated value
     * @param {string} translatedValue
     * @returns {TranslatableEntry}
     */
    withTranslation(translatedValue: string): TranslatableEntry
    {
        return new TranslatableEntry({
            id:             this.id,
            type:           this.type,
            node:           this.node,
            attributeName:  this.attributeName,
            value:          translatedValue,
        });
    }

    // Human-readable description for debugging / display
    toString(): string
    {
        const origin = this.attributeName ? `[${this.attributeName}]` : `<${this.node.nodeName?.toLowerCase?.() ?? 'text'}`;

        return `[${this.type}] ${origin}: "${this.value}"`;
    }
}