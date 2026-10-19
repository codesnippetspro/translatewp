import { SKIP_TAGS, TRANSLATABLE_VALUE_INPUT_TYPES } from '@/shared/lib/translations/constants';

/**
 * Stateless utility class that encapsulates all "is this translatable?" decisions. Keeping these rules isolated makes them easy to test and extend
 * 
 * Every public method accepts configurable class/id opt-out lists without making NodeFilter stateful
 */
export class NodeFilter
{
    /**
     * Determines whether a text node contains translatable content
     * 
     * Rejects nodes that:
     * - Are empty or pure whitespace
     * - Live inside a skipped tag (script, style, code, etc.)
     * - Are inside an element explicitly marked as non-translatable
     * 
     * @param   {Text}      textNode
     * @param   {string[]}  noTranslateClasses
     * @param   {string[]}  noTranslateIds
     * @returns {boolean}
     */
    static isTranslatableTextNode(textNode: Text, noTranslateClasses: string[], noTranslateIds: string[]): boolean
    {
        if (!textNode.nodeValue?.trim()) return false;

        const parent = textNode.parentElement;
        if (!parent) return false;

        if (NodeFilter._isInsideSkippedTag(parent)) return false;
        if (NodeFilter._isMarkedUntranslatable(parent, noTranslateClasses, noTranslateIds)) return false;

        return true;
    }

    /**
     * Determines whether an <img> element has a translatable alt attribute
     * Decorative images (alt="") are intentionally excluded
     * 
     * @param {HTMLImageElement}    imgElement
     * @param {string[]}            noTranslateClasses
     * @param {string[]}            noTranslateIds
     * @returns {boolean}
     */
    static isTranslatableAlt(imgElement: HTMLImageElement, noTranslateClasses: string[], noTranslateIds: string[]): boolean
    {
        const alt = imgElement.getAttribute('alt');

        // alt="" means decorative; valid HTML but not translatable
        if (alt === null || alt.trim().length === 0) return false;

        if (NodeFilter._isMarkedUntranslatable(imgElement, noTranslateClasses, noTranslateIds)) return false;

        return true;
    }

    /**
     * Determines whether a specific attribute on an element is translatable
     * 
     * @param {Element}     element
     * @param {string}      attributeName
     * @param {string[]}    noTranslateClasses
     * @param {string[]}    noTranslateIds
     * @returns {boolean}
     */
    static isTranslatableAttribute(element: Element, attributeName: string, noTranslateClasses: string[], noTranslateIds: string[]): boolean
    {
        const value = element.getAttribute(attributeName);

        if (!value?.trim()) return false;

        if (attributeName === 'value' && !NodeFilter._isTranslatableValueAttribute(element)) return false;

        if (NodeFilter._isMarkedUntranslatable(element, noTranslateClasses, noTranslateIds)) return false;

        return true;
    }

    static _isTranslatableValueAttribute(element: Element): boolean
    {
        if (!(element instanceof HTMLInputElement)) return false;

        return TRANSLATABLE_VALUE_INPUT_TYPES.has(element.type);
    }

    // --- Private Helpers ---

    /**
     * Walks up the DOM to check if a node is nested inside a skipped tag.
     * @param   {Element} element
     * @returns {boolean}
     */
    static _isInsideSkippedTag(element: Element): boolean
    {
        let node: Node | null = element;

        while (node) {
            if (SKIP_TAGS.has(node.nodeName)) return true;
            node = node.parentElement;
        }

        return false;
    }

    /**
     * Checks whether the element (or any ancestor) has been explicitly opted out of translation via any of:
     * 1. 'translate="no"'          (HTML spec attribute)
     * 2. 'data-no-translate'       (data attribute convention)
     * 3. the configured CSS class  (default: 'translatewp-no-translate')
     * 4. the configured element ID list
     * 
     * Ancestor-walking means a single opt-out on a parent shields the entire subtree, consistent with how 'translate="no"' works.
     * 
     * @param   {Element}   element
     * @param   {string[]}  noTranslateClasses
     * @param   {string[]}  noTranslateIds
     * @returns {boolean}
     */
    static _isMarkedUntranslatable(element: Element, noTranslateClasses: string[], noTranslateIds: string[]): boolean
    {
        let node: Element | null = element;

        while (node) {
            const currentNode = node;
            if (node.getAttribute?.('translate') === 'no')      return true;
            if (node.hasAttribute?.('data-no-translate'))       return true;
            if (noTranslateClasses.some(className => currentNode.classList?.contains?.(className))) return true;
            if (noTranslateIds.some(id => currentNode.id === id)) return true;

            node = node.parentElement;
        }

        return false;
    }
}