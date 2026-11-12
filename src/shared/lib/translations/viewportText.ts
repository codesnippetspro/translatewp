export const isTextNodeInViewport = (node: Node): boolean =>
{
    if (node.nodeType !== Node.TEXT_NODE || !node.isConnected) return false;
    for (let element = node.parentElement; element; element = element.parentElement)
    {
        const style = getComputedStyle(element);
        if (style.display === 'none' || style.visibility === 'hidden'
            || style.visibility === 'collapse' || style.opacity === '0') return false;
    }
    const range = document.createRange();
    if (typeof range.getClientRects !== 'function') return false;
    range.selectNodeContents(node);
    return Array.from(range.getClientRects()).some(rect => rect.width > 0 && rect.height > 0
        && rect.bottom > 0 && rect.right > 0 && rect.top < window.innerHeight && rect.left < window.innerWidth);
};
